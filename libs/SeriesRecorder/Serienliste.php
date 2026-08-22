<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Die Liste der Serien, die aufgenommen werden sollen.
 *
 * Bisher war das die Datei von wunschliste.de, und zwar ausschliesslich: was
 * dort nicht stand, wurde nicht aufgenommen. Verbraucht wird davon aber nur der
 * NAME - Sendetermine kommen aus dem XMLTV, Nummern aus Katalog/TMDB/TheTVDB.
 * Die Abhaengigkeit war also duenn, aber vollstaendig: faellt die Webseite aus
 * oder aendert sie ihre Anmeldestrecke, kann man keine Serie mehr hinzufuegen.
 *
 * Deshalb fuehrt jetzt das Modul die Liste, und die Wunschliste wird HINEIN
 * konsolidiert. Wer dort weiter Serien merkt, findet sie beim naechsten Bezug
 * hier wieder - nur haengt der Betrieb nicht mehr daran.
 *
 * Eine Zeile hat drei Angaben: den Namen, die Herkunft und den Schalter.
 * Die Herkunft entscheidet, was der Abgleich mit der Datei anfassen darf:
 *
 *   wunschliste - Spiegel der Webseite. Kommt und geht mit ihr.
 *   eigen       - unsere. Der Abgleich fasst sie nie an.
 *
 * Der Schalter bleibt in beiden Faellen bestehen: eine Serie auf "aus" wird
 * nicht aufgenommen, auch wenn sie auf der Webseite steht. Das ist der Weg,
 * eine Serie stillzustellen, ohne sie dort zu entfernen.
 */
final class Serienliste
{
    /** Vergleichsform: Gross/Klein und Randweiss sind keine Unterscheidung. */
    public static function form(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }

    /**
     * Liest die Tabelle aus der Eigenschaft und raeumt sie auf: leere Namen
     * fliegen raus, Doppelte auch - sonst zaehlt eine Serie zweimal und der
     * Abgleich haette zwei Zeilen zu pflegen.
     *
     * @return list<array{serie:string,quelle:string,aktiv:bool}>
     */
    public static function ausJson(string $json): array
    {
        $roh = json_decode($json, true);
        $out = [];
        $gesehen = [];
        foreach (is_array($roh) ? $roh : [] as $z) {
            if (!is_array($z)) {
                continue;
            }
            $name = trim((string) ($z['serie'] ?? ''));
            if ($name === '') {
                continue;
            }
            $f = self::form($name);
            if (isset($gesehen[$f])) {
                continue;
            }
            $gesehen[$f] = true;
            $out[] = [
                'serie'  => $name,
                'quelle' => ((string) ($z['quelle'] ?? 'eigen')) === 'wunschliste' ? 'wunschliste' : 'eigen',
                'aktiv'  => !isset($z['aktiv']) || (bool) $z['aktiv'],
            ];
        }
        return $out;
    }

    /** Die Namen, die der Zuordnung vorgelegt werden - also nur die aktiven.
     *
     * @param list<array{serie:string,quelle:string,aktiv:bool}> $tabelle
     * @return list<string>
     */
    public static function namen(array $tabelle): array
    {
        $out = [];
        foreach ($tabelle as $z) {
            if ($z['aktiv']) {
                $out[] = $z['serie'];
            }
        }
        return $out;
    }

    /**
     * Traegt die Wunschliste in die Tabelle ein.
     *
     * Neue Namen kommen als "wunschliste" dazu. Namen, die dort verschwunden
     * sind, fallen aus der Tabelle - aber NUR, wenn sie als "wunschliste"
     * gefuehrt sind. Wer eine Zeile auf "eigen" stellt, hat sie uebernommen;
     * dann bleibt sie, egal was die Webseite sagt.
     *
     * Eine LEERE Wunschliste aendert nichts. Sie bedeutet fast immer, dass die
     * Anmeldung nicht durchging - wuerde man sie ernst nehmen, waere nach einem
     * Ausfall der Webseite die ganze Aufnahmeplanung leer.
     *
     * @param list<array{serie:string,quelle:string,aktiv:bool}> $tabelle
     * @param list<string>                                       $ausDatei
     * @return array{tabelle:list<array{serie:string,quelle:string,aktiv:bool}>,neu:list<string>,weg:list<string>,geaendert:bool}
     */
    public static function konsolidiere(array $tabelle, array $ausDatei): array
    {
        $unveraendert = ['tabelle' => $tabelle, 'neu' => [], 'weg' => [], 'geaendert' => false];
        $datei = [];
        foreach ($ausDatei as $n) {
            $n = trim($n);
            if ($n !== '') {
                $datei[self::form($n)] = $n;
            }
        }
        if ($datei === []) {
            return $unveraendert;
        }

        $neu = [];
        $weg = [];
        $behalten = [];
        $haben = [];
        foreach ($tabelle as $z) {
            $f = self::form($z['serie']);
            $haben[$f] = true;
            if ($z['quelle'] === 'wunschliste' && !isset($datei[$f])) {
                $weg[] = $z['serie'];
                continue;
            }
            $behalten[] = $z;
        }
        foreach ($datei as $f => $name) {
            if (!isset($haben[$f])) {
                $behalten[] = ['serie' => $name, 'quelle' => 'wunschliste', 'aktiv' => true];
                $neu[] = $name;
            }
        }

        return [
            'tabelle'   => $behalten,
            'neu'       => $neu,
            'weg'       => $weg,
            'geaendert' => ($neu !== [] || $weg !== []),
        ];
    }

    /**
     * Traegt eine einzelne Serie ein - der Weg aus dem Programmfuehrer.
     *
     * Steht sie schon da, wird sie nur eingeschaltet. Das ist der haeufige
     * Fall: eine Serie, die man einmal stillgestellt hat, will man spaeter
     * wiederhaben, und ein zweiter Eintrag desselben Namens waere ein Fehler.
     *
     * @param list<array{serie:string,quelle:string,aktiv:bool}> $tabelle
     * @return array{tabelle:list<array{serie:string,quelle:string,aktiv:bool}>,zustand:string}
     */
    public static function eintragen(array $tabelle, string $serie): array
    {
        $serie = trim($serie);
        if ($serie === '') {
            return ['tabelle' => $tabelle, 'zustand' => 'leer'];
        }
        $f = self::form($serie);
        foreach ($tabelle as $i => $z) {
            if (self::form($z['serie']) !== $f) {
                continue;
            }
            if ($z['aktiv']) {
                return ['tabelle' => $tabelle, 'zustand' => 'stand schon drin'];
            }
            $tabelle[$i]['aktiv'] = true;
            return ['tabelle' => $tabelle, 'zustand' => 'wieder eingeschaltet'];
        }
        $tabelle[] = ['serie' => $serie, 'quelle' => 'eigen', 'aktiv' => true];
        return ['tabelle' => $tabelle, 'zustand' => 'neu aufgenommen'];
    }

    /**
     * Nimmt eine Serie aus der Aufnahme.
     *
     * Eigene Zeilen verschwinden, Wunschlisten-Zeilen werden nur ausgeschaltet.
     * Wuerde man sie loeschen, holte sie der naechste Abgleich sofort zurueck -
     * die Serie steht ja weiter auf der Webseite.
     *
     * @param list<array{serie:string,quelle:string,aktiv:bool}> $tabelle
     * @return array{tabelle:list<array{serie:string,quelle:string,aktiv:bool}>,zustand:string}
     */
    public static function austragen(array $tabelle, string $serie): array
    {
        $f = self::form($serie);
        foreach ($tabelle as $i => $z) {
            if (self::form($z['serie']) !== $f) {
                continue;
            }
            if ($z['quelle'] === 'wunschliste') {
                if (!$z['aktiv']) {
                    return ['tabelle' => $tabelle, 'zustand' => 'war schon aus'];
                }
                $tabelle[$i]['aktiv'] = false;
                return ['tabelle' => $tabelle, 'zustand' => 'ausgeschaltet (bleibt auf der Wunschliste)'];
            }
            array_splice($tabelle, $i, 1);
            return ['tabelle' => array_values($tabelle), 'zustand' => 'entfernt'];
        }
        return ['tabelle' => $tabelle, 'zustand' => 'stand nicht drin'];
    }
}
