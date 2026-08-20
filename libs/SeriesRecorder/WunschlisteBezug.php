<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/../fremd/serienrecorder.class.wunschliste.php';

/**
 * Log-Anschluss fuer den Wunschliste-Handler.
 *
 * Er braucht eine Methode mehr als die Metadaten-Handler: shouldRefreshFile()
 * entscheidet, ob die gespeicherte Favoritenliste alt genug ist, um neu geholt
 * zu werden. Die Antwort ist eine Altersfrage, keine Netzfrage - deshalb steht
 * sie hier und nicht im Handler.
 */
final class WunschlisteProtokoll
{
    /** @var list<string> */
    private array $zeilen = [];

    public function log($nachricht, $ebene = 1): void
    {
        $this->zeilen[] = (string) $nachricht;
        if (count($this->zeilen) > 300) {
            array_shift($this->zeilen);
        }
    }

    /** Ist die Datei aelter als $stunden - oder gar nicht da? */
    public function shouldRefreshFile($datei, $stunden = 24): bool
    {
        $t = @filemtime((string) $datei);
        if ($t === false) {
            return true;
        }
        return (time() - $t) > max(1, (int) $stunden) * 3600;
    }

    /** @return list<string> */
    public function zeilen(): array
    {
        return $this->zeilen;
    }
}

/**
 * Holt die Wunschliste (Favoriten) von wunschliste.de.
 *
 * Der Handler des Altsystems ist unveraendert uebernommen: er meldet sich an,
 * haelt die Sitzung ueber einen Cookie-Speicher und schreibt die Liste als
 * JSON. Das nachzubauen hiesse, eine fremde Anmeldestrecke ein zweites Mal zu
 * erraten - mit dem Ergebnis, dass beide Fassungen bei der naechsten Aenderung
 * der Webseite brechen statt einer.
 *
 * Geschrieben wird in eine EIGENE Datei. Das Altsystem holt die Liste alle zwei
 * Stunden in seine; wuerden beide dieselbe schreiben, haette man je nach
 * Reihenfolge zwei verschiedene Wahrheiten.
 *
 * Zugangsdaten kommen von aussen und stehen im Modul als Eigenschaften - im
 * Altskript liegen sie im Klartext zwischen den Ablaufzeilen.
 */
final class WunschlisteBezug
{
    private WunschlisteProtokoll $protokoll;

    public function __construct(
        private string $benutzer,
        private string $passwort,
        private string $ziel,
        private string $arbeitsordner,
    ) {
        $this->protokoll = new WunschlisteProtokoll();
    }

    public function einsatzbereit(): bool
    {
        return $this->benutzer !== '' && $this->passwort !== '' && $this->ziel !== '';
    }

    /**
     * @return array{ok:bool,anzahl:int,dauerMs:int,meldung:string}
     */
    public function hole(): array
    {
        $t0 = microtime(true);
        $fertig = fn(bool $ok, string $meldung, int $anzahl = 0) => [
            'ok' => $ok, 'anzahl' => $anzahl,
            'dauerMs' => (int) round((microtime(true) - $t0) * 1000), 'meldung' => $meldung,
        ];

        if (!$this->einsatzbereit()) {
            return $fertig(false, 'Zugangsdaten oder Zieldatei fehlen');
        }
        if (!class_exists(\SerienRecorderWunschliste::class)) {
            return $fertig(false, 'Handler nicht geladen');
        }

        // In eine Nebendatei schreiben lassen und erst nach der Pruefung
        // uebernehmen: eine leere oder halbe Liste wuerde sonst die gute
        // ersetzen, und ohne Favoriten ordnet das Modul gar nichts mehr zu.
        $temp = $this->ziel . '.teil';
        $config = [
            'debug'       => false,
            'debug_level' => 0,
            'cache_dir'   => rtrim($this->arbeitsordner, '/'),
            'base_url'    => 'https://www.wunschliste.de',
            'username'    => $this->benutzer,
            'password'    => $this->passwort,
            'timeout'     => 30,
            'favorites'   => ['file' => $temp, 'refresh' => true, 'refresh_interval' => 1],
        ];

        try {
            $h = new \SerienRecorderWunschliste($config, $this->protokoll);
            if (!$h->login()) {
                @unlink($temp);
                return $fertig(false, 'Anmeldung fehlgeschlagen');
            }
            $favoriten = $h->getFavorites(true);
        } catch (\Throwable $e) {
            @unlink($temp);
            return $fertig(false, 'Abbruch: ' . $e->getMessage());
        }

        $anzahl = is_array($favoriten) ? count($favoriten) : 0;
        if ($anzahl === 0) {
            @unlink($temp);
            return $fertig(false, 'Liste kam leer zurueck - alte Datei bleibt');
        }
        if (!is_file($temp)) {
            return $fertig(false, 'Handler hat nichts geschrieben');
        }
        if (!@rename($temp, $this->ziel)) {
            @unlink($temp);
            return $fertig(false, 'Konnte die neue Liste nicht uebernehmen');
        }
        return $fertig(true, 'geholt', $anzahl);
    }

    /** @return list<string> */
    public function protokollzeilen(): array
    {
        return $this->protokoll->zeilen();
    }
}
