<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

/**
 * Verweise auf TheTVDB und TMDB - damit man nachsehen kann, wie eine Serie dort
 * gefuehrt wird.
 *
 * Wozu: Ob eine Staffelregel noetig ist und welche, entscheidet sich an der
 * Frage, wie die Datenbank die Reihe zaehlt. "Rosamunde Pilcher" ist bei TMDB
 * EINE Serie mit fortlaufenden Staffeln, "Das Traumschiff" fuehrt jede
 * Ausstrahlung als Folge einer einzigen Staffel, und beim Tatort zaehlt TheTVDB
 * nach Jahren. Das sieht man in zehn Sekunden auf der Seite - wenn man den Link
 * hat.
 *
 * Woher die Kennungen kommen: aus den Ablagen, die der Serienrecorder beim
 * Nachschlagen ohnehin anlegt.
 *
 *     serienrecorder/tvdb/series_search_<hash>.json   seriesName, id, slug
 *     serienrecorder/tmdb/search_<hash>.json          name, id
 *     serienrecorder/tmdb/series_<id>/series_info.json
 *
 * Damit entsteht die Uebersicht OHNE einen einzigen Netzaufruf - weder zu den
 * Datenbanken noch zum Receiver. Was nicht in der Ablage steht, hat der
 * Recorder noch nie gebraucht; dort bleibt die Zeile eben leer.
 */
final class Katalogverweise
{
    /** @var array<string,array{name:string,id:int,slug:string}> Vergleichsform => TheTVDB */
    private array $tvdb = [];

    /** @var array<string,array{name:string,id:int}> Vergleichsform => TMDB */
    private array $tmdb = [];

    public function __construct(private string $verzeichnis)
    {
        $this->leseTvdb();
        $this->leseTmdb();
    }

    /**
     * Verweise zu einem Seriennamen.
     *
     * @return array{tvdb:string,tmdb:string,tvdbName:string,tmdbName:string}
     */
    public function fuer(string $serie): array
    {
        $k = Bestand::form($serie);
        $t = $this->tvdb[$k] ?? null;
        $m = $this->tmdb[$k] ?? null;
        return [
            'tvdb'     => $t === null ? '' : ($t['slug'] !== ''
                            ? 'https://thetvdb.com/series/' . rawurlencode($t['slug'])
                            : 'https://thetvdb.com/dereferrer/series/' . $t['id']),
            'tmdb'     => $m === null ? '' : 'https://www.themoviedb.org/tv/' . $m['id'],
            'tvdbName' => $t['name'] ?? '',
            'tmdbName' => $m['name'] ?? '',
        ];
    }

    /**
     * Suchadresse, wenn nichts in der Ablage steht.
     *
     * Besser als eine leere Zelle: ein Klick fuehrt zur Suche, und beim naechsten
     * Nachschlagen steht die Serie dann auch in der Ablage.
     */
    public static function suche(string $serie): array
    {
        $q = rawurlencode($serie);
        return [
            'tvdb' => 'https://thetvdb.com/search?query=' . $q,
            'tmdb' => 'https://www.themoviedb.org/search/tv?query=' . $q,
        ];
    }

    public function bericht(): string
    {
        return sprintf('TheTVDB: %d Serien, TMDB: %d Serien in der Ablage',
            count($this->tvdb), count($this->tmdb));
    }

    private function leseTvdb(): void
    {
        foreach ((array) @glob($this->verzeichnis . '/tvdb/series_search_*.json') as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (!is_array($j)) {
                continue;
            }
            // Die Ablage haelt mal ein einzelnes Ergebnis, mal eine Liste.
            foreach ((isset($j['id']) ? [$j] : $j) as $e) {
                if (!is_array($e) || !isset($e['id'])) {
                    continue;
                }
                $name = trim((string) ($e['seriesName'] ?? $e['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $this->merke($this->tvdb, $name, [
                    'name' => $name, 'id' => (int) $e['id'], 'slug' => trim((string) ($e['slug'] ?? '')),
                ]);
                // Aliasnamen zaehlen mit: "Chornobyl" fuehrt zu derselben Serie.
                foreach ((array) ($e['aliases'] ?? []) as $a) {
                    $a = trim((string) $a);
                    if ($a !== '') {
                        $this->merke($this->tvdb, $a, [
                            'name' => $name, 'id' => (int) $e['id'], 'slug' => trim((string) ($e['slug'] ?? '')),
                        ]);
                    }
                }
            }
        }
    }

    private function leseTmdb(): void
    {
        foreach ((array) @glob($this->verzeichnis . '/tmdb/search_*.json') as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (!is_array($j)) {
                continue;
            }
            foreach ((isset($j['id']) ? [$j] : $j) as $e) {
                if (!is_array($e) || !isset($e['id'])) {
                    continue;
                }
                foreach ([(string) ($e['name'] ?? ''), (string) ($e['original_name'] ?? '')] as $name) {
                    $name = trim($name);
                    if ($name !== '') {
                        $this->merke($this->tmdb, $name, ['name' => (string) ($e['name'] ?? $name), 'id' => (int) $e['id']]);
                    }
                }
            }
        }
        foreach ((array) @glob($this->verzeichnis . '/tmdb/series_*/series_info.json') as $f) {
            $j = json_decode((string) @file_get_contents($f), true);
            if (!is_array($j) || !isset($j['id'])) {
                continue;
            }
            $name = trim((string) ($j['name'] ?? ''));
            if ($name !== '') {
                $this->merke($this->tmdb, $name, ['name' => $name, 'id' => (int) $j['id']]);
            }
        }
    }

    /** @param array<string,array<string,mixed>> $ziel */
    private function merke(array &$ziel, string $name, array $eintrag): void
    {
        $k = Bestand::form($name);
        if ($k !== '' && !isset($ziel[$k])) {
            $ziel[$k] = $eintrag;
        }
    }
}
