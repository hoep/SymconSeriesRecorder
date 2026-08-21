<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

/**
 * Staffelnummern je Serie berichtigen.
 *
 * Das EPG kennt fuer manche Reihen keine Staffel. "Das Traumschiff" laeuft seit
 * 1981 und hat trotzdem keine, Rosamunde-Pilcher-Filme haben keine, der Tatort
 * zaehlt durch (".1244." statt "Staffel.Folge"). Aus solchen Ausstrahlungen wird
 * S00 - und damit ein Ordner "Season 0", in dem alles landet.
 *
 * Diese Klasse setzt das gerade. Eine Regel besteht aus drei Angaben:
 *
 *     Serie                 von      nach
 *     Das Traumschiff       0        1        nur S00 wird zu S01
 *     Rosamunde Pilcher     *        1        jede Staffel wird zu S01
 *     Der Bozen-Krimi       (leer)   1        wie *
 *
 * Uebernommen aus dem Altsystem, aber an drei Stellen anders:
 *
 *  1. Die Regeln stehen in der Instanz, nicht als PHP-Literal im Skript. Im
 *     Altbestand lagen sie in SECHS Skripten mit unterschiedlichem Inhalt; in
 *     vieren stand "Rosamund Pilcher" ohne e, und diese Regel hat nie gegriffen.
 *  2. Verglichen wird der aufgeloeste SERIENNAME (der Favorit), nicht der rohe
 *     EPG-Titel. Im Altsystem scheiterte "Rosamunde Pilcher" an jeder
 *     Ausstrahlung, die "Rosamunde Pilcher: Das Meer in dir" hiess - der
 *     Namensvergleich bricht bei unterschiedlicher Wortzahl ab.
 *  3. "von = 0" heisst ausdruecklich "nur wenn keine Staffel bekannt ist". Das
 *     ist die sichere Form: eine Reihe wie CSI: Vegas liefert im EPG meistens
 *     eine echte Staffel und nur gelegentlich keine (gemessen am 21.08.2026:
 *     30 Ausstrahlungen mit Staffel, 4 ohne). Ein Platzhalter wuerde dort auch
 *     die richtigen Staffeln platt auf 1 setzen.
 */
final class Staffelregeln
{
    /** @var array<string,list<array{von:?int,nach:int}>> Serie (Vergleichsform) => Regeln */
    private array $regeln = [];

    /** @param list<array{serie:string,von:string|int,nach:string|int}> $liste */
    public function __construct(array $liste)
    {
        foreach ($liste as $z) {
            $serie = trim((string) ($z['serie'] ?? ''));
            $nach  = (int) ($z['nach'] ?? 0);
            // "nach 0" waere keine Berichtigung, sondern das Gegenteil: 0 heisst
            // "keine Staffel bekannt", und genau davon soll die Regel wegfuehren.
            // Eine Zeile ohne Ziel ist eine halb ausgefuellte Zeile.
            if ($serie === '' || $nach < 1) {
                continue;
            }
            $vonRoh = trim((string) ($z['von'] ?? ''));
            // Leer, "*" und -1 bedeuten dasselbe: jede Staffel.
            $von = ($vonRoh === '' || $vonRoh === '*' || $vonRoh === '-1') ? null : (int) $vonRoh;
            $this->regeln[Bestand::form($serie)][] = ['von' => $von, 'nach' => $nach];
        }
    }

    public function vorhanden(): bool
    {
        return $this->regeln !== [];
    }

    public function anzahl(): int
    {
        $n = 0;
        foreach ($this->regeln as $r) {
            $n += count($r);
        }
        return $n;
    }

    /**
     * Die berichtigte Staffel - oder null, wenn keine Regel greift.
     *
     * Bewusst KEIN stiller Standardwert: der Aufrufer soll unterscheiden koennen,
     * ob eine Regel gegriffen hat (dann steht es auch im Bericht) oder ob die
     * Staffel einfach so ist, wie sie ist.
     */
    public function fuer(string $serie, int $staffel): ?int
    {
        foreach ($this->regeln[Bestand::form($serie)] ?? [] as $r) {
            if ($r['von'] === null || $r['von'] === $staffel) {
                return $r['nach'] === $staffel ? null : $r['nach'];
            }
        }
        return null;
    }
}
