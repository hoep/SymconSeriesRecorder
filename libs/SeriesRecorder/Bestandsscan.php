<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Durchsucht die Aufnahmeverzeichnisse und schreibt die Bestandsliste.
 *
 * Ablage und Zeilenformat sind vom Altsystem uebernommen, damit beide Fassungen
 * dieselbe Liste lesen koennen:
 *
 *     lfd|Serie|S01E01|Episodentitel|/mnt/Aufnahmen/Serie/Season 1/….ts
 *
 * Die Angaben stammen aus dem Pfad: erster Ordner ist die Serie, im Dateinamen
 * stehen hinter dem Serienpraefix Nummer und Titel, getrennt durch " - ".
 *
 * EIN UNTERSCHIED ZUM ORIGINAL, UND ZWAR EIN WICHTIGER: dort wird die alte
 * Liste als erstes geloescht und dann neu geschrieben. Ist die Netzwerkfreigabe
 * in dem Moment nicht eingebunden, bleibt eine leere Datei zurueck - und eine
 * leere Bestandsliste heisst fuer die Entscheidung "nichts ist vorhanden".
 * Der naechste Lauf wuerde alles noch einmal aufnehmen. Hier wird deshalb in
 * eine Nebendatei geschrieben, das Ergebnis geprueft und erst dann uebernommen;
 * bei zu wenigen Funden bleibt die alte Liste unangetastet.
 *
 * Was hier ebenfalls NICHT passiert: `mount -a`. Das Original ruft es auf, wenn
 * der Scan fast nichts findet. Eine Freigabe einzuhaengen ist ein Eingriff ins
 * Betriebssystem und gehoert nicht in einen Lesevorgang - das Modul meldet den
 * Verdacht und ueberlaesst die Entscheidung.
 */
final class Bestandsscan
{
    /** @var list<string> */
    private array $serien = [];

    private int $dateien = 0;
    private int $uebersprungen = 0;

    /**
     * @param list<string> $verzeichnisse Wurzeln der Aufnahmen
     * @param list<string> $endungen      zu beruecksichtigende Dateiendungen
     * @param int          $mindestens    weniger Funde = Verdacht auf fehlende Freigabe
     */
    public function __construct(
        private array $verzeichnisse,
        private array $endungen = ['ts', 'mkv', 'mp4'],
        private int $mindestens = 50,
    ) {
    }

    /**
     * @return array{ok:bool,dateien:int,serien:int,uebersprungen:int,dauerMs:int,meldung:string}
     */
    public function lauf(string $zieldatei, string $serienDatei = ''): array
    {
        $t0 = microtime(true);
        $fertig = fn(bool $ok, string $meldung) => [
            'ok' => $ok, 'dateien' => $this->dateien, 'serien' => count(array_unique($this->serien)),
            'uebersprungen' => $this->uebersprungen,
            'dauerMs' => (int) round((microtime(true) - $t0) * 1000), 'meldung' => $meldung,
        ];

        $wurzeln = array_values(array_filter($this->verzeichnisse, static fn(string $v): bool => is_dir($v)));
        if ($wurzeln === []) {
            return $fertig(false, 'Kein Aufnahmeverzeichnis erreichbar - Freigabe eingebunden?');
        }

        $temp = $zieldatei . '.teil';
        $fh = @fopen($temp, 'w');
        if ($fh === false) {
            return $fertig(false, 'Kann nicht schreiben: ' . $temp);
        }
        foreach ($wurzeln as $wurzel) {
            $this->durchsuche(rtrim($wurzel, '/'), rtrim($wurzel, '/'), $fh);
        }
        fclose($fh);

        // Die Pruefung ist der eigentliche Zweck der Nebendatei.
        if ($this->dateien < $this->mindestens) {
            @unlink($temp);
            return $fertig(false, sprintf(
                'Nur %d Aufnahmen gefunden (erwartet mindestens %d) - alte Liste bleibt stehen. '
                . 'Das deutet auf eine nicht eingebundene Freigabe hin.',
                $this->dateien, $this->mindestens));
        }
        if (!@rename($temp, $zieldatei)) {
            @unlink($temp);
            return $fertig(false, 'Konnte die neue Liste nicht uebernehmen');
        }

        if ($serienDatei !== '') {
            $namen = array_values(array_unique($this->serien));
            sort($namen, SORT_NATURAL | SORT_FLAG_CASE);
            @file_put_contents($serienDatei . '.teil', implode("\n", $namen) . "\n");
            @rename($serienDatei . '.teil', $serienDatei);
        }
        return $fertig(true, 'Bestand aufgenommen');
    }

    /** @param resource $fh */
    private function durchsuche(string $ordner, string $wurzel, $fh): void
    {
        $eintraege = @scandir($ordner);
        if ($eintraege === false) {
            return;
        }
        foreach ($eintraege as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $pfad = $ordner . '/' . $e;
            if (is_dir($pfad)) {
                $this->durchsuche($pfad, $wurzel, $fh);
                continue;
            }
            $endung = strtolower((string) pathinfo($e, PATHINFO_EXTENSION));
            if (!in_array($endung, $this->endungen, true)) {
                continue;
            }
            $zeile = $this->zerlege($pfad, $wurzel);
            if ($zeile === null) {
                $this->uebersprungen++;
                continue;
            }
            fwrite($fh, $this->dateien . '|' . $zeile . "\n");
            $this->dateien++;
        }
    }

    /**
     * Baut aus einem Pfad die Bestandszeile ohne die laufende Nummer.
     * Erwartet <Wurzel>/<Serie>/<irgendein Unterordner>/<Datei>.
     */
    private function zerlege(string $pfad, string $wurzel): ?string
    {
        $rel = ltrim(substr($pfad, strlen($wurzel)), '/');
        $teile = explode('/', $rel);
        if (count($teile) < 2) {
            return null;   // Datei liegt lose in der Wurzel, ohne Serienordner
        }
        $serie = $teile[0];
        $datei = (string) end($teile);

        // Der Dateiname wiederholt ueblicherweise den Serienname: "Serie - S01E01 - Titel.ts".
        $rest = pathinfo($datei, PATHINFO_FILENAME);
        if (str_starts_with($rest, $serie . ' - ')) {
            $rest = substr($rest, strlen($serie) + 3);
        }
        $stuecke = explode(' - ', $rest);
        if (count($stuecke) < 2) {
            return null;   // ohne Nummer UND Titel ist die Zeile wertlos
        }
        $nummer = trim($stuecke[0]);
        $titel  = trim(implode(' - ', array_slice($stuecke, 1)));
        if ($nummer === '' || $titel === '') {
            return null;
        }
        $this->serien[] = $serie;
        return $serie . '|' . $nummer . '|' . $titel . '|' . $pfad;
    }
}
