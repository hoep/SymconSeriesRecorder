<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Eine Aufnahme ist mehr als eine Datei.
 *
 * Der Receiver legt neben dem Video eine Reihe von Begleitern ab - zu
 * "CSI Miami - S09E10 - Alptraumpaar.ts" gehoeren:
 *
 *     .eit  .nfo  .jpg  -thumb.jpg  .ts.ap  .ts.cuts  .ts.meta  .ts.sc
 *
 * Wer nur das Video loescht, laesst sieben Dateien zurueck; wer nur das Video
 * umbenennt, zerreisst den Satz. Deshalb wird hier nicht mit einer festen
 * Endungsliste gearbeitet, sondern mit dem STAMM: alles im selben Ordner, dessen
 * Name mit "<Stamm>." oder "<Stamm>-" beginnt, gehoert dazu.
 *
 * Der Trenner ist wichtig. "Folge 1" und "Folge 10" haetten sonst denselben
 * Praefix, und ein Loeschvorgang naehme die falsche Aufnahme mit; ebenso
 * gehoert "Alptraumpaar_001.ts" NICHT zu "Alptraumpaar", weil nach dem Stamm
 * ein Unterstrich und kein Trenner folgt.
 */
final class Dateisatz
{
    /**
     * Alle Dateien, die zu dieser Aufnahme gehoeren - das Video eingeschlossen.
     *
     * @return list<string>
     */
    public static function geschwister(string $tsPfad): array
    {
        $ordner = dirname($tsPfad);
        $stamm = self::stamm($tsPfad);
        if ($stamm === '' || !is_dir($ordner)) {
            return is_file($tsPfad) ? [$tsPfad] : [];
        }
        $out = [];
        foreach ((array) @scandir($ordner) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            if (self::gehoertZu((string) $e, $stamm)) {
                $p = $ordner . '/' . $e;
                if (is_file($p)) {
                    $out[] = $p;
                }
            }
        }
        sort($out);
        return $out;
    }

    /** Summe aller zugehoerigen Dateien in Byte. */
    public static function groesse(string $tsPfad): int
    {
        $n = 0;
        foreach (self::geschwister($tsPfad) as $p) {
            $n += (int) @filesize($p);
        }
        return $n;
    }

    /**
     * Traegt der Name den Zusatz, den der Receiver bei einer zweiten Aufnahme
     * anhaengt ("_001", "_002")? Dann steht hier der Name ohne ihn.
     */
    public static function ohneZaehler(string $tsPfad): ?string
    {
        $stamm = self::stamm($tsPfad);
        $neu = preg_replace('/_\d{3}$/', '', $stamm);
        return ($neu !== null && $neu !== '' && $neu !== $stamm) ? $neu : null;
    }

    /**
     * Benennt den ganzen Satz um.
     *
     * Bricht ab, BEVOR etwas passiert, wenn schon eine Datei des Zielnamens
     * existiert - ein halb umbenannter Satz waere schlimmer als der Zustand
     * vorher, weil Video und Begleiter dann verschiedene Namen truegen.
     *
     * @return array{ok:bool,umbenannt:int,grund:string,neu:string}
     */
    public static function benenneUm(string $tsPfad, string $neuerStamm): array
    {
        $ordner = dirname($tsPfad);
        $alt = self::stamm($tsPfad);
        if ($alt === '' || $neuerStamm === '' || $alt === $neuerStamm) {
            return ['ok' => false, 'umbenannt' => 0, 'grund' => 'nichts zu tun', 'neu' => ''];
        }
        $satz = self::geschwister($tsPfad);
        if ($satz === []) {
            return ['ok' => false, 'umbenannt' => 0, 'grund' => 'keine Dateien gefunden', 'neu' => ''];
        }
        // Erst alle Ziele pruefen, dann handeln.
        $plan = [];
        foreach ($satz as $p) {
            $name = basename($p);
            $rest = substr($name, strlen($alt));
            $ziel = $ordner . '/' . $neuerStamm . $rest;
            if (file_exists($ziel)) {
                return ['ok' => false, 'umbenannt' => 0,
                        'grund' => 'Zielname ist belegt: ' . basename($ziel), 'neu' => ''];
            }
            $plan[$p] = $ziel;
        }
        $n = 0;
        foreach ($plan as $von => $nach) {
            if (@rename($von, $nach)) {
                $n++;
            }
        }
        return ['ok' => $n > 0, 'umbenannt' => $n, 'grund' => '',
                'neu' => $ordner . '/' . $neuerStamm . '.ts'];
    }

    /** Dateiname ohne Endung - bei ".ts" also der ganze Rest davor. */
    public static function stamm(string $tsPfad): string
    {
        $name = basename($tsPfad);
        $p = strrpos($name, '.');
        return $p === false ? $name : substr($name, 0, $p);
    }

    private static function gehoertZu(string $dateiname, string $stamm): bool
    {
        $l = strlen($stamm);
        if (strncmp($dateiname, $stamm, $l) !== 0 || strlen($dateiname) <= $l) {
            return false;
        }
        // Nach dem Stamm MUSS ein Trenner folgen, sonst waeren "Folge 1" und
        // "Folge 10" derselbe Satz.
        $next = $dateiname[$l];
        return $next === '.' || $next === '-';
    }
}
