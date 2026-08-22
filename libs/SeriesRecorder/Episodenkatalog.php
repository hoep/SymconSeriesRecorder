<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';
require_once __DIR__ . '/EpisodenQuelle.php';

/**
 * Staffel und Folge nachschlagen, wenn das EPG sie nicht liefert.
 *
 * Die Skript-Fassung fragt dafuer TheTVDB und TMDB ab (EpisodeManager::
 * enrichBroadcast) und legt jede Antwort auf der Platte ab. Dieser Cache ist
 * betraechtlich - 419 Serienordner, dazu elf vollstaendige Serien-Dumps im
 * Hauptverzeichnis, allein Tatort mit 1418 Episoden. Er wird hier gelesen,
 * nicht neu beschafft: das Nachschlagen kostet damit weder Netz noch
 * API-Kontingent, und es funktioniert auch, wenn TheTVDB gerade nicht mag.
 *
 * Zwei Formate liegen nebeneinander, beide werden gelesen:
 *
 *   A  <Serie>.json            {"Series":…, "Episode":[{SeasonNumber, EpisodeNumber, EpisodeName}]}
 *   B  tvdb/series_<id>/episodes.json  [{airedSeason, airedEpisodeNumber, episodeName}]
 *      dazu tvdb/series_search_<hash>.json  {id, seriesName}  fuer die Zuordnung
 *   C  tmdb/series_<id>/series_info.json  {name}
 *      tmdb/series_<id>/season_<n>/episode_<m>.json  {episode_number, name}
 *      Die Staffel steht NUR im Ordnernamen - in der Datei fehlt sie.
 *
 * Warum das wichtig ist: fuer Tatort liefert das EPG weder Staffel noch Folge.
 * Der Katalog kennt "Aus dem Dunkel" als S2023E26 - genau die Zaehlung, unter
 * der die Folge auch im Aufnahmebestand liegt. Erst damit greifen Bestands-
 * abgleich und Serien-Schranken bei dieser Serie ueberhaupt.
 */
final class Episodenkatalog implements EpisodenQuelle
{
    /** @var array<string,array<string,array{staffel:int,folge:int}>> Serie => Episodentitel => Nummer */
    private array $katalog = [];

    private int $serien = 0;
    private int $episoden = 0;

    public function __construct(private string $verzeichnis, private bool $mitTmdb = true)
    {
        $this->lade();
    }

    public function bericht(): string
    {
        return sprintf('Katalog: %d Serien, %d Episoden aus der Ablage', $this->serien, $this->episoden);
    }

    public function serien(): int
    {
        return $this->serien;
    }

    public function episoden(): int
    {
        return $this->episoden;
    }

    /**
     * @return array{staffel:int,folge:int}|null
     */
    public function finde(string $serie, string $episodentitel): ?array
    {
        $s = Bestand::form($serie);
        $t = Bestand::form($episodentitel);
        if ($s === '' || $t === '' || !isset($this->katalog[$s])) {
            return null;
        }
        if (isset($this->katalog[$s][$t])) {
            return $this->katalog[$s][$t];
        }
        // Der Dumps traegt bei manchen Reihen das Ermittlerteam im Episodentitel
        // ("Faber - 22 - Liebe mich"), das EPG nur den Folgentitel. Deshalb auch
        // auf das Ende eines Katalogtitels pruefen - aber nur bei ausreichender
        // Laenge, sonst treffen sich Allerweltsworte.
        if (mb_strlen($t) >= 8) {
            foreach ($this->katalog[$s] as $kt => $nr) {
                if (str_ends_with($kt, ' ' . $t)) {
                    return $nr;
                }
            }
        }
        // Klammerzusaetze am Ende weglassen. Beide Seiten haengen dort gern etwas
        // an, und zwar Verschiedenes: das EPG zaehlt Teile ("Weihnachtsmaenner in
        // Gefahr (2)"), der Katalog nennt das Jahr ("Weihnachtsmaenner in Gefahr
        // (2024)"). Ohne diesen Schritt blieb genau so ein Fall bei S00E00 stehen,
        // obwohl die Folge im Katalog steht - gefunden am 22.08.2026.
        $tOhne = self::ohneKlammer($episodentitel);
        if ($tOhne !== '' && mb_strlen($tOhne) >= 6) {
            foreach ($this->katalog[$s] as $kt => $nr) {
                if ($kt === $tOhne || self::ohneKlammer($kt) === $tOhne) {
                    return $nr;
                }
            }
            // Zuletzt: der Katalogtitel faengt mit dem EPG-Titel an. "Christmas
            // Special" trifft damit "Christmas Special 2025", aber ein kurzer
            // Allerweltstitel nicht jede Folge - deshalb erst ab sechs Zeichen.
            foreach ($this->katalog[$s] as $kt => $nr) {
                if (str_starts_with($kt, $tOhne)) {
                    return $nr;
                }
            }
        }
        return null;
    }

    /** Vergleichsform ohne abschliessenden Klammerzusatz. */
    private static function ohneKlammer(string $titel): string
    {
        $t = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', trim($titel)));
        return Bestand::form($t);
    }

    private function lade(): void
    {
        $v = rtrim($this->verzeichnis, '/');

        // --- Format A: vollstaendige Serien-Dumps im Hauptverzeichnis
        foreach (glob($v . '/*.json') ?: [] as $datei) {
            $name = basename($datei, '.json');
            // Die Betriebsdateien liegen im selben Ordner und sind keine Serien.
            if (in_array($name, ['channels', 'favorites', 'favorites_with_broadcasts',
                                 'duplicate_recordings', 'broken_broadcasts'], true)) {
                continue;
            }
            $d = self::json($datei);
            $liste = $d['Episode'] ?? null;
            if (!is_array($liste)) {
                continue;
            }
            foreach ($liste as $e) {
                $this->merke($name, (string) ($e['EpisodeName'] ?? ''),
                    (int) ($e['SeasonNumber'] ?? 0), (int) ($e['EpisodeNumber'] ?? 0));
            }
        }

        // --- Format B: TVDB-Cache, Name steckt in den Suchantworten daneben
        $namen = [];
        foreach (glob($v . '/tvdb/series_search_*.json') ?: [] as $datei) {
            $d = self::json($datei);
            $id = (string) ($d['id'] ?? '');
            $name = (string) ($d['seriesName'] ?? $d['name'] ?? '');
            if ($id !== '' && $name !== '') {
                $namen[$id] ??= $name;
            }
        }
        foreach (glob($v . '/tvdb/series_*/episodes.json') ?: [] as $datei) {
            if (!preg_match('#series_(\d+)/episodes\.json$#', $datei, $m)) {
                continue;
            }
            $name = $namen[$m[1]] ?? '';
            if ($name === '') {
                continue;   // ohne Serienname ist der Eintrag nicht zuzuordnen
            }
            $liste = self::json($datei);
            if (!is_array($liste)) {
                continue;
            }
            foreach ($liste as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $this->merke($name, (string) ($e['episodeName'] ?? ''),
                    (int) ($e['airedSeason'] ?? 0), (int) ($e['airedEpisodeNumber'] ?? 0));
            }
        }

        // --- Format C: TMDB legt je Episode eine eigene Datei an. Das sind
        // ueber 5000 Dateien; sie einzeln zu oeffnen kostet Zeit, deshalb wird
        // dieser Teil nur gelesen, wenn er verlangt ist. Ohne ihn bliebe alles,
        // was TMDB frisch holt, fuer den Katalog unsichtbar - beim naechsten
        // Lauf muesste dieselbe Folge erneut ueber die Schnittstelle geholt
        // werden, obwohl sie laengst auf der Platte liegt.
        if ($this->mitTmdb) {
            foreach (glob($v . '/tmdb/series_*/series_info.json') ?: [] as $datei) {
                $info = self::json($datei);
                $name = trim((string) ($info['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $ordner = dirname($datei);
                foreach (glob($ordner . '/season_*/episode_*.json') ?: [] as $ep) {
                    if (!preg_match('#/season_(\d+)/episode_(\d+)\.json$#', $ep, $m)) {
                        continue;
                    }
                    $e = self::json($ep);
                    $titel = (string) ($e['name'] ?? '');
                    // Die Folgennummer aus der Datei ist massgeblich, der Dateiname
                    // nur der Notnagel: bei Doppelfolgen weichen sie voneinander ab.
                    $nr = (int) ($e['episode_number'] ?? $m[2]);
                    $this->merke($name, $titel, (int) $m[1], $nr);
                }
            }
        }

        $this->serien = count($this->katalog);
    }

    private function merke(string $serie, string $titel, int $staffel, int $folge): void
    {
        if ($titel === '' || ($staffel === 0 && $folge === 0)) {
            return;
        }
        $s = Bestand::form($serie);
        $t = Bestand::form($titel);
        if ($s === '' || $t === '') {
            return;
        }
        // Erster Eintrag gewinnt: Wiederholungen und Zweitverwertungen stehen
        // spaeter in den Dumps, die Erstausstrahlung ist die gesuchte Nummer.
        if (!isset($this->katalog[$s][$t])) {
            $this->katalog[$s][$t] = ['staffel' => $staffel, 'folge' => $folge];
            $this->episoden++;
        }
    }

    private static function json(string $datei): array
    {
        $roh = @file_get_contents($datei);
        if ($roh === false) {
            return [];
        }
        $d = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $roh) ?? $roh, true);
        return is_array($d) ? $d : [];
    }
}
