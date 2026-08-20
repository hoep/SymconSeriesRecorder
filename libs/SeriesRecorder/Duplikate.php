<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';
require_once __DIR__ . '/Dateisatz.php';

/**
 * Findet mehrfach vorhandene Aufnahmen derselben Folge.
 *
 * Dieselbe Folge liegt oft zweimal auf der Platte: eine Wiederholung wurde
 * mitgeschnitten, oder eine Aufnahme brach ab und wurde neu angesetzt. Der
 * Receiver haengt in dem Fall "_001" an oder legt eine zweite Datei mit
 * identischem Namen in einem anderen Ordner an.
 *
 * Vorschlagen heisst hier NICHT loeschen. Diese Klasse liefert eine Liste mit
 * Begruendung; was damit geschieht, entscheidet das Modul hinter seinem Gate.
 *
 * Die Auswahl, welche Datei bleibt, faellt bewusst nach GROESSE und nicht nach
 * Datum: die groessere Datei ist im Regelfall die vollstaendige Aufnahme,
 * waehrend die kleinere ein Abbruch oder eine gekuerzte Wiederholung ist. Bei
 * gleicher Groesse gewinnt die aeltere - sie ist die Erstaufnahme, auf die
 * moeglicherweise schon etwas verweist.
 */
final class Duplikate
{
    /** @param string $bestandsdatei Liste aus dem Bestandsscan */
    public function __construct(private string $bestandsdatei)
    {
    }

    /**
     * @return array{
     *   gruppen:list<array{serie:string,nummer:string,titel:string,behalten:array,loeschen:list<array>,gewinnt:string}>,
     *   dateien:int, gruppen_anzahl:int, ueberfluessig:int, bytes:int,
     *   unerreichbar:int, verlaesslich:bool
     * }
     */
    public function finde(): array
    {
        $nach = [];
        $zeilen = 0;
        $fh = @fopen($this->bestandsdatei, 'r');
        if ($fh === false) {
            return ['gruppen' => [], 'dateien' => 0, 'gruppen_anzahl' => 0, 'ueberfluessig' => 0,
                    'bytes' => 0, 'unerreichbar' => 0, 'verlaesslich' => false];
        }
        while (($z = fgets($fh)) !== false) {
            if (!mb_check_encoding($z, 'UTF-8')) {
                $z = mb_convert_encoding($z, 'UTF-8', 'ISO-8859-1');
            }
            $f = explode('|', rtrim($z, "\r\n"));
            if (count($f) < 5) {
                continue;
            }
            $zeilen++;
            $pfad = (string) array_pop($f);
            $serie = (string) ($f[1] ?? '');
            $nummer = '';
            $titel = '';
            foreach (array_slice($f, 2) as $feld) {
                if ($nummer === '' && preg_match('/^\s*S\d{1,4}E\d{1,4}\s*$/i', (string) $feld)) {
                    $nummer = strtolower(trim((string) $feld));
                } else {
                    $titel = (string) $feld;
                }
            }
            // Ohne Nummer ist keine sichere Gruppe zu bilden: zwei Folgen koennen
            // denselben Titel tragen ("Teil 1"), und ein Fehlgriff loescht hier
            // eine echte Aufnahme.
            if ($nummer === '' || $nummer === 's00e00') {
                continue;
            }
            $s = Bestand::form($serie);
            if ($s === '') {
                continue;
            }
            $nach[$s . '|' . $nummer][] = [
                'serie' => $serie, 'nummer' => $nummer, 'titel' => $titel,
                // Beim Einlesen NOCH KEINE Groesse holen. Der Satz einer Aufnahme
                // umfasst neun Dateien, und ihn zu vermessen heisst, das Verzeichnis
                // zu lesen - bei 12.500 Zeilen ueber eine Netzwerkfreigabe dauert das
                // Minuten. Gebraucht wird die Groesse nur dort, wo es wirklich
                // mehrere Aufnahmen derselben Folge gibt: rund 160 Dateien.
                'pfad' => $pfad, 'groesse' => 0, 'zeit' => 0,
            ];
        }
        fclose($fh);

        $gruppen = [];
        $ueberfluessig = 0;
        $bytes = 0;
        $unerreichbar = 0;
        foreach ($nach as $liste) {
            if (count($liste) < 2) {
                continue;
            }
            // Derselbe Pfad zweimal ist KEIN Duplikat, sondern eine kaputte
            // Bestandszeile - und der gefaehrlichste Fall ueberhaupt: die Gruppe
            // saehe aus wie zwei Aufnahmen, "behalten" und "loeschen" zeigten auf
            // dieselbe Datei, und ein Klick loeschte die einzige Kopie. Genau das
            // stand nach dem Entzaehlern in der Liste.
            $einmalig = [];
            foreach ($liste as $x) {
                $einmalig[$x['pfad']] = $x;
            }
            $liste = array_values($einmalig);
            if (count($liste) < 2) {
                continue;
            }
            // Eine Gruppe zaehlt nur, wenn ALLE ihre Dateien wirklich da sind.
            // Haengt die Netzwerkfreigabe nicht, liest die Bestandsliste sich
            // unveraendert weiter, waehrend filesize() ueberall 0 liefert - dann
            // waere die Auswahl 'groesste bleibt' geraten und der Vorschlag ein
            // Blindflug. Lieber gar kein Vorschlag als ein falscher.
            $fehlt = false;
            foreach ($liste as $x) {
                if (!is_file($x['pfad'])) {
                    $fehlt = true;
                    break;
                }
            }
            if ($fehlt) {
                $unerreichbar++;
                continue;
            }
            // Jetzt erst vermessen - fuer die wenigen Faelle, die es angeht.
            foreach ($liste as $k => $x) {
                $liste[$k]['groesse'] = Dateisatz::groesse($x['pfad']);
                $liste[$k]['zeit'] = (int) @filemtime($x['pfad']);
            }
            // Groesste zuerst; bei Gleichstand die aeltere.
            usort($liste, static function (array $a, array $b): int {
                return ($b['groesse'] <=> $a['groesse']) ?: ($a['zeit'] <=> $b['zeit']);
            });
            $behalten = array_shift($liste);
            $ueberfluessig += count($liste);
            foreach ($liste as $w) {
                $bytes += $w['groesse'];
            }
            $gruppen[] = [
                'serie'    => $behalten['serie'],
                'nummer'   => strtoupper($behalten['nummer']),
                'titel'    => $behalten['titel'],
                'behalten' => $behalten,
                'loeschen' => array_values($liste),
                'gewinnt'  => $behalten['groesse'] > 0
                    ? 'groesste Datei (' . self::mb($behalten['groesse']) . ')'
                    : 'einzige lesbare Datei',
            ];
        }
        usort($gruppen, static fn(array $a, array $b): int => strcmp($a['serie'] . $a['nummer'], $b['serie'] . $b['nummer']));

        return [
            'gruppen' => $gruppen, 'dateien' => $zeilen,
            'gruppen_anzahl' => count($gruppen), 'ueberfluessig' => $ueberfluessig, 'bytes' => $bytes,
            'unerreichbar' => $unerreichbar,
            // Sind mehr Gruppen unerreichbar als erreichbar, stimmt etwas Grundsaetzliches
            // nicht - dann ist das Ergebnis kein Vorschlag, sondern eine Warnung.
            'verlaesslich' => ($unerreichbar === 0 || $unerreichbar < count($gruppen)),
        ];
    }

    public static function mb(int $bytes): string
    {
        return $bytes >= 1073741824
            ? sprintf('%.1f GB', $bytes / 1073741824)
            : sprintf('%.0f MB', $bytes / 1048576);
    }

    /**
     * Fuehrt die Loeschungen aus. Wird NUR vom Modul hinter dem Gate gerufen.
     *
     * Jede Datei wird vorher noch einmal geprueft: die Bestandsliste kann alt
     * sein, und ein Pfad, der ins Leere zeigt, ist kein Grund zur Panik - aber
     * einer, ihn zu melden statt stillschweigend zu uebergehen. Genau hier ist
     * der Unterschied zum Altsystem wichtig, dessen Liste bei Dateinamen mit
     * Unterstrich falsche Pfade enthaelt.
     *
     * @param list<array{pfad:string,groesse:int}> $dateien
     * @return array{geloescht:int,fehlend:int,fehler:list<string>,bytes:int,begleiter:int}
     */
    public function loesche(array $dateien): array
    {
        $geloescht = 0;
        $fehlend = 0;
        $bytes = 0;
        $begleiter = 0;
        $fehler = [];
        foreach ($dateien as $d) {
            $p = (string) ($d['pfad'] ?? '');
            if ($p === '') {
                continue;
            }
            if (!is_file($p)) {
                $fehlend++;
                $fehler[] = 'nicht mehr da: ' . $p;
                continue;
            }
            // Nicht nur das Video: .eit, .nfo, .jpg, -thumb.jpg, .ts.ap, .ts.cuts,
            // .ts.meta und .ts.sc gehoeren dazu. Wer sie stehen laesst, sammelt mit
            // jeder Loeschung sieben verwaiste Dateien an.
            $satz = Dateisatz::geschwister($p);
            $gr = 0;
            $weg = 0;
            foreach ($satz as $einzeln) {
                $gr += (int) @filesize($einzeln);
                if (@unlink($einzeln)) {
                    $weg++;
                } else {
                    $fehler[] = 'nicht loeschbar: ' . $einzeln;
                }
            }
            if ($weg > 0) {
                $geloescht++;
                $bytes += $gr;
                $begleiter += max(0, $weg - 1);
            }
        }
        return ['geloescht' => $geloescht, 'fehlend' => $fehlend, 'fehler' => $fehler,
                'bytes' => $bytes, 'begleiter' => $begleiter];
    }
}
