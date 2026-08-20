<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Woher Staffel und Folge kommen, wenn das EPG sie schuldig bleibt.
 *
 * Es gibt mehrere Wege dorthin, und sie unterscheiden sich vor allem im Preis:
 * der Dateicache kostet nichts, eine Abfrage bei TheTVDB kostet Wartezeit,
 * Kontingent und die Annahme, dass gerade jemand antwortet. Deshalb dieselbe
 * Schnittstelle fuer alle - die Reihenfolge legt die Kette fest, nicht der
 * Aufrufer.
 */
interface EpisodenQuelle
{
    /**
     * @return array{staffel:int,folge:int}|null null = diese Quelle weiss nichts
     */
    public function finde(string $serie, string $episodentitel): ?array;

    /** Kurzbericht fuer das Protokoll: was hat diese Quelle beigetragen? */
    public function bericht(): string;
}
