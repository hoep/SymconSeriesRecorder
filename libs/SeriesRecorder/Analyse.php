<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/TitelResolver.php';
require_once __DIR__ . '/KanalMapper.php';
require_once __DIR__ . '/XmltvLeser.php';
require_once __DIR__ . '/Entscheidung.php';
require_once __DIR__ . '/Receiver.php';

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
        private ?Bestand $bestand = null,
        private ?Bedingungen $bedingungen = null,
        private ?EpisodenQuelle $katalog = null,
        private ?Receiver $receiver = null,
        private ?Staffelregeln $staffelregeln = null,
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

        // Ohne Bestandsliste bleibt es beim "was laeuft" - die Entscheidung, ob eine
        // Folge fehlt, braucht die Platte. Beides getrennt, damit der Lauf auch dann
        // etwas liefert, wenn der Scanner gerade nichts geschrieben hat.
        $urteiler = $this->bestand !== null
            ? new Entscheidung($this->bestand, $this->bedingungen, $this->katalog, $this->receiver, $this->staffelregeln)
            : null;
        $urteiler?->beginneLauf();

        $treffer = [];
        $fast = [];
        $z = ['geprueft' => 0, 'zugeordnet' => 0, 'ohne Favorit' => 0, 'Sender nicht empfangbar' => 0];
        $verworfeneSender = [];

        foreach ($leser->sendungen($von, $bis) as $s) {
            $z['geprueft']++;
            $t = $resolver->bestimme($s['titel']);
            if ($t === null) {
                $z['ohne Favorit']++;
                // Unzugeordnete Titel sammeln - nach BASISNAMEN, nicht je
                // Ausstrahlung. Zwoelftausend Zeilen sind keine Auskunft; die
                // paar hundert verschiedenen Namen dahinter sind eine.
                [$b, ] = TitelResolver::zerlege($s['titel']);
                $b = trim($b);
                if ($b !== '') {
                    if (!isset($fast[$b])) {
                        $fast[$b] = ['titel' => $b, 'sender' => $sender[$s['kanal']] ?? $s['kanal'], 'anzahl' => 0];
                    }
                    $fast[$b]['anzahl']++;
                }
                continue;
            }
            if (($kanal[$s['kanal']] ?? null) === null) {
                $z['Sender nicht empfangbar']++;
                $name = $sender[$s['kanal']] ?? $s['kanal'];
                $verworfeneSender[$name] = ($verworfeneSender[$name] ?? 0) + 1;
                continue;
            }
            $z['zugeordnet']++;
            $u = $urteiler?->fuer([
                'serie'      => $t['ablage'],
                'titel'      => $s['titel'],
                'untertitel' => $s['untertitel'],
                'folgeNum'   => $s['folge'],
                'kanal'      => $kanal[$s['kanal']],
                'start'      => $s['start'],
                'ende'       => $s['ende'],
            ]);
            if ($u !== null) {
                $z[$u['urteil']] = ($z[$u['urteil']] ?? 0) + 1;
            }
            $treffer[] = [
                'kanal'  => $kanal[$s['kanal']],
                'sender' => $sender[$s['kanal']] ?? $s['kanal'],
                'serie'  => $t['ablage'],
                'titel'  => $s['untertitel'] !== '' ? $s['untertitel'] : $t['zusatz'],
                'start'  => $s['start'],
                'ende'   => $s['ende'],
                'folge'  => $s['folge'],
                'regel'  => $t['regel'],
                'urteil' => $u['urteil'] ?? '',
                'grund'  => $u['grund'] ?? '',
                'staffelFolge' => ($u !== null && ($u['staffel'] > 0 || $u['folge'] > 0))
                                    ? Bestand::nummer($u['staffel'], $u['folge']) : '',
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
            'quellen'      => trim(($this->katalog?->bericht() ?? '')
                                . ($this->receiver !== null ? ' | ' . $this->receiver->bericht() : '')),
            'fastTreffer'  => self::naheDran($fast, $this->favoriten),
            'dauerMs'      => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Welcher Favorit liegt einem nicht zugeordneten Titel am naechsten?
     *
     * Die Punktevergabe des Resolvers taugt dafuer nicht: unterhalb ihrer
     * Schwelle liegt nichts: entweder eine Regel trifft (mindestens 55 Punkte)
     * oder gar nichts. Ein "knapp daneben" gibt es dort nicht - deshalb blieb
     * die Matching-Seite leer.
     *
     * Hier wird deshalb wirklich verglichen: Editierabstand auf der
     * Vergleichsform, in Prozent der Namenslaenge. Gerechnet wird nur ueber die
     * verschiedenen BASISNAMEN (ein paar hundert statt zwoelftausend Zeilen) und
     * nur gegen Favoriten aehnlicher Laenge - sonst waere es eine Million
     * Vergleiche fuer nichts.
     *
     * @param array<string,array<string,mixed>> $fast
     * @param list<string> $favoriten
     * @return list<array<string,mixed>>
     */
    private static function naheDran(array $fast, array $favoriten): array
    {
        // Haeufigste zuerst - wer oft laeuft, faellt auch oft auf.
        uasort($fast, static fn(array $a, array $b): int => $b['anzahl'] <=> $a['anzahl']);
        $fast = array_slice($fast, 0, 600, true);

        $favNorm = [];
        foreach ($favoriten as $f) {
            $n = TitelResolver::normalisiere($f);
            if ($n !== '') {
                $favNorm[$f] = $n;
            }
        }

        $out = [];
        foreach ($fast as $e) {
            $n = TitelResolver::normalisiere((string) $e['titel']);
            if ($n === '' || mb_strlen($n) < 4) {
                continue;
            }
            $besterName = ''; $besteNaehe = 0; $bestesVorn = false;
            foreach ($favNorm as $name => $fn) {
                // Laengenfenster: was sich um mehr als ein Drittel unterscheidet,
                // ist kein Schreibfehler mehr.
                $la = strlen($n); $lb = strlen($fn);
                if ($lb < $la * 0.66 || $lb > $la * 1.5) {
                    continue;
                }
                $d = levenshtein($n, $fn);
                $naehe = (int) round((1 - $d / max($la, $lb)) * 100);
                // Steckt der kuerzere Name vorn im laengeren, ist das ein starkes
                // Zeichen: "Magnum" gegen "Magnum P.I.". Reine Zeichenaehnlichkeit
                // wuerde auch "Wetter" und "Dexter" zusammenbringen - beides sechs
                // Buchstaben, zwei Unterschiede, und nichts miteinander zu tun.
                $vorn = str_starts_with($fn, $n) || str_starts_with($n, $fn);
                if ($vorn) {
                    $naehe = max($naehe, 72);
                }
                if ($naehe > $besteNaehe) {
                    $besteNaehe = $naehe;
                    $besterName = $name;
                    $bestesVorn = $vorn;
                }
            }
            // 72 Prozent ist die Grenze, an der aus Zufall Absicht wird. Darunter
            // stand im Versuch nur Rauschen ("Kommissar Rex" gegen "Kommissar
            // Cain", "Inspector Barnaby" gegen "Inspektor Jury").
            if ($besterName === '' || $besteNaehe < 72) {
                continue;
            }
            $out[] = ['titel' => (string) $e['titel'], 'favorit' => $besterName,
                      'naehe' => $besteNaehe, 'sender' => (string) $e['sender'],
                      'anzahl' => (int) $e['anzahl']];
        }
        usort($out, static fn(array $a, array $b): int =>
            ($b['naehe'] <=> $a['naehe']) ?: ($b['anzahl'] <=> $a['anzahl']));
        return array_slice($out, 0, 200);
    }

    /**
     * Fast-Treffer als Tabelle (Zeile 0 = Kopf).
     *
     * @param list<array<string,mixed>> $fast
     * @return list<list<string>>
     */
    public static function fastAlsTabelle(array $fast, int $schwelle): array
    {
        $out = [['XMLTV-Titel', 'naechster Favorit', 'Naehe', 'Sender', 'Ausstrahlungen']];
        foreach ($fast as $f) {
            $out[] = [
                (string) $f['titel'],
                (string) $f['favorit'],
                ((int) $f['naehe']) . ' %',
                (string) $f['sender'],
                (string) $f['anzahl'],
            ];
        }
        return $out;
    }

    /**
     * Ergebnis als Zeilen-Array fuer die Tabellen-Variable (Zeile 0 = Kopf).
     *
     * @param list<array<string,mixed>> $sendungen
     * @return list<list<string>>
     */
    public static function alsTabelle(array $sendungen): array
    {
        $out = [['_ts', 'Datum', 'Start', 'Ende', 'Serie', 'Folge', 'Titel', 'Sender', 'Urteil', 'Grund']];
        foreach ($sendungen as $s) {
            $out[] = [
                (string) $s['start'],
                date('d.m.', (int) $s['start']),
                date('H:i', (int) $s['start']),
                $s['ende'] > 0 ? date('H:i', (int) $s['ende']) : '',
                (string) $s['serie'],
                (string) ($s['staffelFolge'] ?? ''),
                (string) $s['titel'],
                (string) $s['sender'],
                (string) ($s['urteil'] ?? ''),
                (string) ($s['grund'] ?? ''),
            ];
        }
        return $out;
    }
}
