<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/EpisodenQuelle.php';

/**
 * Fragt mehrere Episodenquellen der Reihe nach, die billigste zuerst.
 *
 * Die Reihenfolge ist die ganze Aussage: der Dateikatalog kostet nichts und
 * beantwortet die allermeisten Faelle, eine TheTVDB-Abfrage kostet eine halbe
 * Sekunde Wartezeit und ein Stueck API-Kontingent. Wer die Kette umdreht,
 * bekommt dieselben Antworten - nur langsamer und auf fremde Verfuegbarkeit
 * angewiesen.
 *
 * Gefundene Antworten werden fuer den laufenden Durchgang gemerkt. Bei einer
 * Serie, die zwanzigmal in der Woche laeuft, spart das neunzehn Abfragen.
 */
final class Quellenkette implements EpisodenQuelle
{
    /** @var array<string,array{staffel:int,folge:int}|false> */
    private array $merker = [];

    /** @var list<EpisodenQuelle> */
    private array $quellen;

    public function __construct(EpisodenQuelle ...$quellen)
    {
        $this->quellen = array_values($quellen);
    }

    public function finde(string $serie, string $episodentitel): ?array
    {
        $k = $serie . '|' . $episodentitel;
        if (array_key_exists($k, $this->merker)) {
            return $this->merker[$k] === false ? null : $this->merker[$k];
        }
        foreach ($this->quellen as $q) {
            $t = $q->finde($serie, $episodentitel);
            if ($t !== null) {
                $this->merker[$k] = $t;
                return $t;
            }
        }
        $this->merker[$k] = false;
        return null;
    }

    public function bericht(): string
    {
        return implode(' | ', array_map(static fn(EpisodenQuelle $q): string => $q->bericht(), $this->quellen));
    }
}
