<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/TitelResolver.php';
require_once __DIR__ . '/KanalMapper.php';
require_once __DIR__ . '/XmltvLeser.php';

/**
 * Der lesende Durchlauf: welche Ausstrahlungen der Wunschliste stehen an?
 *
 * Bewusst ohne jede Nebenwirkung - kein Receiver, keine Timer, keine Dateien.
 * Genau dafuer ist der Schattenbetrieb da: das Ergebnis laesst sich Sendung fuer
 * Sendung gegen das Altsystem halten, bevor irgendetwas programmiert wird.
 *
 * Jede verworfene Sendung wird gezaehlt und begruendet. In der Skript-Fassung
 * verschwanden sie lautlos - dass 26 Ausstrahlungen an einem falsch
 * geschriebenen Sendernamen haengen blieben, sah man nirgends.
 */
final class Analyse
{
    /** @param string[] $favoriten */
    public function __construct(
        private array $favoriten,
        private array $aliase,
        private array $ablagenamen,
        private array $empfangbar,
        private array $kanalTabelle,
    ) {
    }

    /**
     * @return array{
     *   sendungen:list<array{kanal:string,sender:string,serie:string,titel:string,start:int,ende:int,folge:string}>,
     *   kennzahlen:array<string,int|string>,
     *   offeneSender:list<string>,
     *   dauerMs:int
     * }
     */
    public function lauf(XmltvLeser $leser, int $von = 0, int $bis = 0): array
    {
        $t0 = microtime(true);
        $resolver = new TitelResolver($this->favoriten, $this->aliase);
        $resolver->setAblagenamen($this->ablagenamen);

        $sender = $leser->sender();
        $mapper = new KanalMapper($this->empfangbar, $this->kanalTabelle);

        // Sender einmal vorab aufloesen statt je Sendung: bei 16.000 Sendungen auf
        // 63 Sendern ist alles andere Verschwendung.
        $kanal = [];
        foreach ($sender as $id => $name) {
            $t = $mapper->finde($name);
            $kanal[$id] = $t === null ? null : $t['kanal'];
        }

        $treffer = [];
        $z = ['geprueft' => 0, 'zugeordnet' => 0, 'ohne Favorit' => 0, 'Sender nicht empfangbar' => 0];
        $verworfeneSender = [];

        foreach ($leser->sendungen($von, $bis) as $s) {
            $z['geprueft']++;
            $t = $resolver->bestimme($s['titel']);
            if ($t === null) {
                $z['ohne Favorit']++;
                continue;
            }
            if (($kanal[$s['kanal']] ?? null) === null) {
                $z['Sender nicht empfangbar']++;
                $name = $sender[$s['kanal']] ?? $s['kanal'];
                $verworfeneSender[$name] = ($verworfeneSender[$name] ?? 0) + 1;
                continue;
            }
            $z['zugeordnet']++;
            $treffer[] = [
                'kanal'  => $kanal[$s['kanal']],
                'sender' => $sender[$s['kanal']] ?? $s['kanal'],
                'serie'  => $t['ablage'],
                'titel'  => $s['untertitel'] !== '' ? $s['untertitel'] : $t['zusatz'],
                'start'  => $s['start'],
                'ende'   => $s['ende'],
                'folge'  => $s['folge'],
                'regel'  => $t['regel'],
            ];
        }
        usort($treffer, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        // Verworfene Sender nach Haeufigkeit: oben steht, was am meisten kostet.
        arsort($verworfeneSender);
        $offen = [];
        foreach ($verworfeneSender as $name => $anzahl) {
            $offen[] = $name . ' (' . $anzahl . ')';
        }

        return [
            'sendungen'    => $treffer,
            'kennzahlen'   => $z + ['Serien mit Ausstrahlung' => count(array_unique(array_column($treffer, 'serie')))],
            'offeneSender' => $offen,
            'dauerMs'      => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Ergebnis als Zeilen-Array fuer die Tabellen-Variable (Zeile 0 = Kopf).
     *
     * @param list<array<string,mixed>> $sendungen
     * @return list<list<string>>
     */
    public static function alsTabelle(array $sendungen): array
    {
        $out = [['_ts', 'Datum', 'Start', 'Ende', 'Serie', 'Titel', 'Sender', 'Regel']];
        foreach ($sendungen as $s) {
            $out[] = [
                (string) $s['start'],
                date('d.m.', (int) $s['start']),
                date('H:i', (int) $s['start']),
                $s['ende'] > 0 ? date('H:i', (int) $s['ende']) : '',
                (string) $s['serie'],
                (string) $s['titel'],
                (string) $s['sender'],
                (string) $s['regel'],
            ];
        }
        return $out;
    }
}
