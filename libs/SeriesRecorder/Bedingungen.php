<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

/**
 * Zusaetzliche Schranken je Serie: "nur ab Staffel 2024", "nur mit Folgennummer".
 *
 * In der Skript-Fassung stand das als PHP-Literal mitten im Ablauf:
 *
 *     $conditions = [['title' => 'Tatort', 'conditions' => [
 *         'season'  => ['operator' => '>=', 'value' => 2024],
 *         'episode' => ['operator' => '>',  'value' => 0]]]];
 *
 * Genau deshalb ist auch nie aufgefallen, was die Regel wirklich tut: das EPG
 * liefert fuer Tatort weder Staffel noch Folge (S00E00, die Nummer steckt als
 * laufende Zaehlung woanders), also scheitert "season >= 2024" ausnahmslos - die
 * Regel verwirft JEDE Tatort-Ausstrahlung, auch die neuen. Als Liste im Formular
 * steht dieselbe Regel sichtbar da und laesst sich pruefen.
 *
 * Mehrere Zeilen zur selben Serie gelten UND-verknuepft, wie im Original.
 */
final class Bedingungen
{
    private const OPERATOREN = ['>=', '<=', '!=', '==', '=', '>', '<'];

    /** @var array<string,list<array{feld:string,op:string,wert:float}>> Serie (Vergleichsform) => Regeln */
    private array $regeln = [];

    /**
     * @param list<array{serie:string,feld:string,op:string,wert:string|float|int}> $liste
     */
    public function __construct(array $liste)
    {
        foreach ($liste as $z) {
            $serie = trim((string) ($z['serie'] ?? ''));
            $feld  = mb_strtolower(trim((string) ($z['feld'] ?? '')), 'UTF-8');
            $op    = trim((string) ($z['op'] ?? ''));
            if ($serie === '' || $feld === '' || !in_array($op, self::OPERATOREN, true)) {
                continue;
            }
            $this->regeln[Bestand::form($serie)][] = [
                'feld' => $feld,
                'op'   => $op === '=' ? '==' : $op,
                'wert' => (float) $z['wert'],
            ];
        }
    }

    public function vorhanden(): bool
    {
        return $this->regeln !== [];
    }

    /**
     * Darf diese Ausstrahlung aufgenommen werden?
     *
     * @return array{erlaubt:bool,grund:string}
     */
    public function pruefe(string $serie, int $staffel, int $folge): array
    {
        $r = $this->regeln[Bestand::form($serie)] ?? [];
        if ($r === []) {
            return ['erlaubt' => true, 'grund' => ''];
        }
        foreach ($r as $b) {
            $ist = match ($b['feld']) {
                'season', 'staffel' => (float) $staffel,
                'episode', 'folge'  => (float) $folge,
                default             => null,
            };
            if ($ist === null) {
                // Unbekanntes Feld: die Regel schweigt, statt stillschweigend zu
                // verwerfen. Ein Tippfehler im Formular soll nicht eine Serie
                // unsichtbar machen.
                continue;
            }
            if (!self::trifft($ist, $b['op'], $b['wert'])) {
                return [
                    'erlaubt' => false,
                    'grund'   => sprintf('Regel %s %s %s nicht erfuellt (ist %s)',
                        $b['feld'], $b['op'], self::zahl($b['wert']), self::zahl($ist)),
                ];
            }
        }
        return ['erlaubt' => true, 'grund' => ''];
    }

    private static function trifft(float $ist, string $op, float $soll): bool
    {
        return match ($op) {
            '>='    => $ist >= $soll,
            '<='    => $ist <= $soll,
            '>'     => $ist > $soll,
            '<'     => $ist < $soll,
            '=='    => abs($ist - $soll) < 0.000001,
            '!='    => abs($ist - $soll) >= 0.000001,
            default => true,
        };
    }

    private static function zahl(float $f): string
    {
        return (abs($f - round($f)) < 0.000001) ? (string) (int) round($f) : (string) $f;
    }
}
