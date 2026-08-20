<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/EpisodenQuelle.php';
require_once __DIR__ . '/../fremd/serienrecorder.class.tvdb.php';

/**
 * Log-Anschluss fuer die uebernommene TVDB-Klasse.
 *
 * Sie erwartet von ihrer Umgebung genau eine Methode. Statt den ganzen
 * Util-Handler des Altsystems mitzuschleppen, gibt es hier diese zwanzig
 * Zeilen - die Meldungen landen in einem Puffer, den das Modul ausliest.
 */
final class TvdbProtokoll
{
    /** @var list<string> */
    private array $zeilen = [];

    public function __construct(private int $ebene = 1)
    {
    }

    public function log($nachricht, $ebene = 1): void
    {
        if ((int) $ebene > $this->ebene) {
            return;
        }
        $this->zeilen[] = (string) $nachricht;
        // Deckel gegen Dauerlaeufe: ein haengender Abruf soll nicht den Speicher
        // fuellen. Die letzten Meldungen sind die interessanten.
        if (count($this->zeilen) > 300) {
            array_shift($this->zeilen);
        }
    }

    /** @return list<string> */
    public function zeilen(): array
    {
        return $this->zeilen;
    }
}

/**
 * TheTVDB als Episodenquelle - hinter einem Gate.
 *
 * Diese Quelle wird NUR gebaut, wenn der Netzzugriff ausdruecklich freigegeben
 * ist. Der naheliegende Weg - die Klasse im Nur-Cache-Modus zu betreiben - ist
 * eine Falle: ihr Einstieg `enrichBroadcastFromCache()` traegt zwar den Namen,
 * ruft aber `searchSeries()`, und das geht bei unbekannter Serie ueber
 * `apiRequest('/search/series')` doch ins Netz. Die Zusicherung "kein
 * Netzverkehr" darf nicht an einem Methodennamen haengen, sondern daran, dass
 * das Objekt gar nicht erst existiert.
 *
 * Den Dateicache deckt ohnehin der Episodenkatalog ab, und zwar vollstaendig
 * und nachweislich ohne Verbindung. Diese Quelle ist der Schritt DAHINTER.
 *
 * Der Deckel je Lauf ist kein Schmuck: die Klasse wartet 500 ms zwischen zwei
 * Anfragen und bis zu 30 s auf eine Antwort. Eine Serie mit vielen Luecken
 * dehnt einen Lauf damit von zwei Sekunden auf Minuten.
 */
final class TvdbQuelle implements EpisodenQuelle
{
    private ?\SerienRecorderTVDB $handler = null;
    private TvdbProtokoll $protokoll;

    private int $gefragt = 0;
    private int $gefunden = 0;
    private int $abgelehnt = 0;   // durch den Deckel verhindert

    /**
     * @param string $verzeichnis Metadaten-Ablage (dieselbe wie im Altsystem)
     * @param string $apiKey      leer = die Quelle bleibt stumm
     * @param int    $deckel      hoechstens so viele Abfragen je Lauf
     */
    public function __construct(
        private string $verzeichnis,
        private string $apiKey,
        private int $deckel = 25,
        private int $cacheStunden = 168,
    ) {
        $this->protokoll = new TvdbProtokoll();
    }

    public function einsatzbereit(): bool
    {
        return $this->apiKey !== '' && is_dir($this->verzeichnis);
    }

    public function finde(string $serie, string $episodentitel): ?array
    {
        if ($serie === '' || $episodentitel === '' || !$this->einsatzbereit()) {
            return null;
        }
        if ($this->gefragt >= $this->deckel) {
            $this->abgelehnt++;
            return null;
        }
        $h = $this->handler();
        if ($h === null) {
            return null;
        }
        $this->gefragt++;

        // Die Klasse arbeitet auf einem Broadcast-Array und schreibt ihr Ergebnis
        // hinein. Wir bauen das Minimum, das sie braucht, und lesen es zurueck.
        $broadcast = [
            'title'       => $serie,
            'episodeName' => $episodentitel,
            'season'      => 0,
            'episode'     => 0,
        ];
        try {
            $ok = (bool) $h->enrichBroadcast($broadcast);
        } catch (\Throwable $e) {
            $this->protokoll->log('Abbruch bei "' . $serie . '": ' . $e->getMessage());
            return null;
        }
        $st = (int) ($broadcast['season'] ?? 0);
        $fo = (int) ($broadcast['episode'] ?? 0);
        if (!$ok || ($st === 0 && $fo === 0)) {
            return null;
        }
        $this->gefunden++;
        return ['staffel' => $st, 'folge' => $fo];
    }

    public function bericht(): string
    {
        if (!$this->einsatzbereit()) {
            return 'TheTVDB: kein Schluessel oder Verzeichnis fehlt';
        }
        return sprintf('TheTVDB: %d gefragt, %d gefunden%s',
            $this->gefragt, $this->gefunden,
            $this->abgelehnt > 0 ? sprintf(', %d nach dem Deckel von %d uebersprungen', $this->abgelehnt, $this->deckel) : '');
    }

    /** @return list<string> */
    public function protokollzeilen(): array
    {
        return $this->protokoll->zeilen();
    }

    private function handler(): ?\SerienRecorderTVDB
    {
        if ($this->handler !== null) {
            return $this->handler;
        }
        if (!class_exists(\SerienRecorderTVDB::class)) {
            return null;
        }
        // Die Konfiguration entspricht der des Altsystems; abweichende Schluessel
        // wuerden die Klasse still auf Vorgabewerte zurueckfallen lassen.
        $config = [
            'debug'       => false,
            'debug_level' => 0,
            'tvdb'        => [
                'api_key'              => $this->apiKey,
                'metadata_dir'         => rtrim($this->verzeichnis, '/'),
                'cache_lifetime'       => $this->cacheStunden,
                'prefer_german_titles' => true,
            ],
        ];
        $this->handler = new \SerienRecorderTVDB($config, $this->protokoll);
        return $this->handler;
    }
}
