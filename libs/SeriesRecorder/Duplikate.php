<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

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
     *   dateien:int, gruppen_anzahl:int, ueberfluessig:int, bytes:int
     * }
     */
    public function finde(): array
    {
        $nach = [];
        $zeilen = 0;
        $fh = @fopen($this->bestandsdatei, 'r');
        if ($fh === false) {
            return ['gruppen' => [], 'dateien' => 0, 'gruppen_anzahl' => 0, 'ueberfluessig' => 0, 'bytes' => 0];
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
                'pfad' => $pfad, 'groesse' => (int) @filesize($pfad), 'zeit' => (int) @filemtime($pfad),
            ];
        }
        fclose($fh);

        $gruppen = [];
        $ueberfluessig = 0;
        $bytes = 0;
        foreach ($nach as $liste) {
            if (count($liste) < 2) {
                continue;
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
     * @return array{geloescht:int,fehlend:int,fehler:list<string>,bytes:int}
     */
    public function loesche(array $dateien): array
    {
        $geloescht = 0;
        $fehlend = 0;
        $bytes = 0;
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
            $gr = (int) @filesize($p);
            if (@unlink($p)) {
                $geloescht++;
                $bytes += $gr;
            } else {
                $fehler[] = 'nicht loeschbar: ' . $p;
            }
        }
        return ['geloescht' => $geloescht, 'fehlend' => $fehlend, 'fehler' => $fehler, 'bytes' => $bytes];
    }
}
