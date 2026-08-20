<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/EpisodenQuelle.php';
require_once __DIR__ . '/TvdbQuelle.php';           // TvdbProtokoll: derselbe Log-Anschluss
require_once __DIR__ . '/../fremd/serienrecorder.class.api.php';

/**
 * TMDB als Episodenquelle.
 *
 * Aufbau wie bei TheTVDB - uebernommener Handler, Log-Adapter, Gate, Deckel.
 * Der Unterschied liegt in der Rangfolge: TheTVDB hat seine Seriensuche
 * abgeschaltet (v3 antwortet auf /search/series mit 404, gemessen am
 * 20.08.2026), TMDB sucht weiterhin. Damit ist DIESE Quelle der Weg fuer
 * Serien, die noch in keinem Cache stehen; TheTVDB bleibt fuer die, deren ID
 * schon bekannt ist.
 *
 * Auch hier gilt: ohne Freigabe wird das Objekt nicht gebaut. Der Handler
 * prueft zwar zuerst seine Dateiablage, geht aber bei einer Luecke von sich aus
 * ins Netz - die Zusicherung "kein Netzverkehr" haengt am Nichtvorhandensein
 * der Quelle, nicht an einem Aufrufweg.
 */
final class TmdbQuelle implements EpisodenQuelle
{
    private ?\SerienRecorderAPI $handler = null;
    private TvdbProtokoll $protokoll;

    private int $gefragt = 0;
    private int $gefunden = 0;
    private int $abgelehnt = 0;

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
        // Ohne Episodentitel kann auch TMDB nichts zuordnen: gesucht wird ueber
        // den Titel (ersatzweise das Sendedatum, das wir hier nicht mitgeben).
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

        $broadcast = [
            'title'       => $serie,
            'episodeName' => $episodentitel,
            'season'      => 0,
            'episode'     => 0,
        ];
        try {
            $ok = (bool) $h->fetchMissingEpisodeInfo($broadcast);
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
            return 'TMDB: kein Schluessel oder Verzeichnis fehlt';
        }
        return sprintf('TMDB: %d gefragt, %d gefunden%s',
            $this->gefragt, $this->gefunden,
            $this->abgelehnt > 0 ? sprintf(', %d nach dem Deckel von %d uebersprungen', $this->abgelehnt, $this->deckel) : '');
    }

    /** @return list<string> */
    public function protokollzeilen(): array
    {
        return $this->protokoll->zeilen();
    }

    private function handler(): ?\SerienRecorderAPI
    {
        if ($this->handler !== null) {
            return $this->handler;
        }
        if (!class_exists(\SerienRecorderAPI::class)) {
            return null;
        }
        $config = [
            'debug'       => false,
            'debug_level' => 0,
            'cache_dir'   => rtrim($this->verzeichnis, '/'),
            'tmdb'        => [
                'api_key'          => $this->apiKey,
                'metadata_dir'     => rtrim($this->verzeichnis, '/'),
                'cache_lifetime'   => $this->cacheStunden,
                // Specials (Staffel 0) mitnehmen: manche Reihen fuehren
                // Weihnachtsfolgen und Pilotfilme genau dort.
                'include_specials' => true,
            ],
        ];
        $this->handler = new \SerienRecorderAPI($config, $this->protokoll);
        return $this->handler;
    }
}
