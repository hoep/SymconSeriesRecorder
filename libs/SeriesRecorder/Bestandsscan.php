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
 * ZWEITE ART VON WURZEL: die Ablagen der von Hand mitgeschnittenen Filme
 * ("02 - Filme Sabina", "03 - Filme Peter") liegen FLACH - eine Datei je
 * Aufnahme, kein Serienordner, keine Folgennummer. Sie werden getrennt
 * uebergeben und als Filmzeile geschrieben:
 *
 *     lfd|#FILM|Titel|Kurzbeschreibung|/mnt/Aufnahmen_Sabina/….ts
 *
 * Der Titel kommt aus der `.meta`-Datei des Receivers (zweite Zeile), denn im
 * Dateinamen sind ':' und '/' durch '_' ersetzt; erst ohne Meta zaehlt der
 * Dateiname. Die dritte Metazeile kommt als Kurzbeschreibung mit - sie ist das
 * EINZIGE, was zwei gleichnamige Aufnahmen unterscheidet ("Das Traumschiff"
 * liegt vierzehnmal da, jedes Mal mit anderem Reiseziel in dieser Zeile). Steht in der dritten Metazeile eine Folgenangabe, ist es keine
 * Filmaufnahme, sondern eine Folge - die wird uebersprungen, sonst gaelte die
 * ganze Reihe als vorhanden, sobald ein einziger Teil auf der Platte liegt.
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
    private int $filme = 0;
    private int $uebersprungen = 0;

    /** Ordner, die in den Filmablagen nichts zu suchen haben. */
    private const AUS = ['movie_trash', '.grab', 'images', 'template', 'snippets'];

    /**
     * @param list<string> $verzeichnisse Wurzeln der Aufnahmen
     * @param list<string> $endungen      zu beruecksichtigende Dateiendungen
     * @param int          $mindestens    weniger Funde = Verdacht auf fehlende Freigabe
     * @param list<string> $filmordner    flache Ablagen ohne Serienstruktur
     */
    public function __construct(
        private array $verzeichnisse,
        private array $endungen = ['ts', 'mkv', 'mp4'],
        private int $mindestens = 50,
        private array $filmordner = [],
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
            'filme' => $this->filme, 'uebersprungen' => $this->uebersprungen,
            'dauerMs' => (int) round((microtime(true) - $t0) * 1000), 'meldung' => $meldung,
        ];

        $wurzeln = array_values(array_filter($this->verzeichnisse, static fn(string $v): bool => is_dir($v)));
        $filmwurzeln = array_values(array_filter($this->filmordner, static fn(string $v): bool => is_dir($v)));
        // Sind Serienverzeichnisse eingetragen, muss mindestens eines tragen -
        // sonst stuende gleich die halbe Liste nicht mehr drin. Nur wenn gar
        // keines eingetragen ist, entscheiden die Filmablagen allein.
        if ($this->verzeichnisse !== [] ? $wurzeln === [] : $filmwurzeln === []) {
            return $fertig(false, 'Kein Aufnahmeverzeichnis erreichbar - Freigabe eingebunden?');
        }
        $fehlend = array_values(array_diff($this->filmordner, $filmwurzeln));

        $temp = $zieldatei . '.teil';
        $fh = @fopen($temp, 'w');
        if ($fh === false) {
            return $fertig(false, 'Kann nicht schreiben: ' . $temp);
        }
        foreach ($wurzeln as $wurzel) {
            $this->durchsuche(rtrim($wurzel, '/'), rtrim($wurzel, '/'), $fh);
        }
        // Die Filmablagen kommen NACH den Serien in dieselbe Liste. Sie zaehlen
        // aber nicht in die Untergrenze: haengt die Serienfreigabe, waehrend die
        // Filmfreigabe traegt, sollen tausend Filme den Fehlschlag nicht zudecken.
        foreach ($filmwurzeln as $ordner) {
            $this->durchsucheFilme(rtrim($ordner, '/'), $fh);
        }
        fclose($fh);

        // Die Pruefung ist der eigentliche Zweck der Nebendatei. Gemessen wird
        // an der Serienablage; nur wenn gar keine eingetragen ist, an den Filmen.
        $gemessen = $this->verzeichnisse !== [] ? $this->dateien : $this->filme;
        if ($gemessen < $this->mindestens) {
            @unlink($temp);
            return $fertig(false, sprintf(
                'Nur %d Aufnahmen gefunden (erwartet mindestens %d) - alte Liste bleibt stehen. '
                . 'Das deutet auf eine nicht eingebundene Freigabe hin.',
                $gemessen, $this->mindestens));
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
        // Eine nicht erreichbare Filmablage haelt den Scan nicht auf - die Serien
        // sind das Wichtigere -, sie muss aber dastehen: sonst verschwinden die
        // Filmmarken aus dem Programm, ohne dass jemand den Grund sieht.
        return $fertig(true, $fehlend === []
            ? 'Bestand aufgenommen'
            : 'Bestand aufgenommen, Filmablage fehlt: ' . implode(', ', array_map('basename', $fehlend)));
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

    /**
     * Flache Filmablage: jede Videodatei ist eine Aufnahme, der Titel steht im
     * Namen. Unterordner werden mitgenommen (dort liegen Sammlungen), die
     * Arbeitsordner des Receivers nicht.
     *
     * @param resource $fh
     */
    private function durchsucheFilme(string $ordner, $fh): void
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
                if (!in_array($e, self::AUS, true)) {
                    $this->durchsucheFilme($pfad, $fh);
                }
                continue;
            }
            if (!in_array(strtolower((string) pathinfo($e, PATHINFO_EXTENSION)), $this->endungen, true)) {
                continue;
            }
            $film = $this->filmzeile($pfad, (string) pathinfo($e, PATHINFO_FILENAME));
            if ($film === null) {
                $this->uebersprungen++;
                continue;
            }
            fwrite($fh, ($this->dateien + $this->filme) . '|#FILM|' . $film['titel'] . '|'
                . $film['beschreibung'] . '|' . $pfad . "\n");
            $this->filme++;
        }
    }

    /**
     * Titel und Kurzbeschreibung einer Filmaufnahme, oder null, wenn es gar
     * keine ist.
     *
     * Die `.meta` des Receivers traegt in Zeile 2 den Titel so, wie er im
     * Programm stand, in Zeile 3 die Beschreibung. Steht dort eine Folgenangabe,
     * gehoert die Datei zu einer Reihe und darf nicht als Film gelten.
     *
     * @return array{titel:string,beschreibung:string}|null
     */
    private function filmzeile(string $pfad, string $dateiname): ?array
    {
        $titel = '';
        $besch = '';
        $meta = @file_get_contents($pfad . '.meta', false, null, 0, 512);
        if ($meta !== false) {
            $zeilen = explode("\n", $meta);
            $titel = trim($zeilen[1] ?? '');
            $besch = trim($zeilen[2] ?? '');
            if (preg_match('/^\s*(Folge|Teil|Staffel)\s*\d/ui', $besch) === 1) {
                return null;
            }
        }
        if ($titel === '') {
            // Ohne Meta bleibt der Dateiname. Der Receiver haengt an eine zweite
            // Aufnahme desselben Titels "_001" an - das gehoert nicht zum Namen.
            $titel = (string) preg_replace('/_\d{3}$/', '', $dateiname);
        }
        $sauber = static fn(string $x, int $laenge): string
            => trim(mb_substr(str_replace(['|', "\r", "\t"], ' ', $x), 0, $laenge));
        $titel = $sauber($titel, 200);
        return $titel === '' ? null : ['titel' => $titel, 'beschreibung' => $sauber($besch, 80)];
    }
}
