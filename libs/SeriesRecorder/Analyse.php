<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/TitelResolver.php';
require_once __DIR__ . '/KanalMapper.php';
require_once __DIR__ . '/XmltvLeser.php';
require_once __DIR__ . '/Entscheidung.php';
require_once __DIR__ . '/Receiver.php';
require_once __DIR__ . '/EpisodenNummer.php';

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
     *   marken:array<string,int>,
     *   dauerMs:int
     * }
     *
     * $markenVon zieht NUR das Nachschlagen im Bestand weiter zurueck, nicht das
     * Urteil: der Programmfuehrer zeigt den ganzen laufenden Tag, entschieden
     * wird aber weiterhin erst ab $von. Sonst stuende die halbe Vormittagsware
     * mit dem Urteil "aufnehmen" da - und der Programmierlauf, der genau danach
     * greift, wuerde Timer fuer Vergangenes setzen.
     */
    public function lauf(XmltvLeser $leser, int $von = 0, int $bis = 0, int $markenVon = 0): array
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
        $marken = [];
        // Filmtreffer werden erst am Ende uebernommen: sie haengen daran, wie oft
        // derselbe Titel im gelesenen Zeitraum ueberhaupt vorkommt.
        $filmMarken = [];
        $titelZahl = [];
        $z = ['geprueft' => 0, 'zugeordnet' => 0, 'ohne Favorit' => 0, 'Sender nicht empfangbar' => 0];
        $verworfeneSender = [];

        $vonLesen = ($markenVon > 0 && $markenVon < $von) ? $markenVon : $von;
        foreach ($leser->sendungen($vonLesen, $bis) as $s) {
            // Der Filmbestand kennt nur Titel. Deshalb wird HIER gezaehlt, fuer
            // jede Ausstrahlung, auch die vor dem Fenster: ein Titel, der im
            // Zeitraum dutzendfach laeuft, ist eine Reihe und kein Film.
            $tk = Bestand::form((string) $s['titel']);
            if ($tk !== '') {
                $titelZahl[$tk] = ($titelZahl[$tk] ?? 0) + 1;
                $this->merkeFilm($filmMarken, $s, $tk);
            }
            // Vor dem Entscheidungsfenster wird nur nachgeschlagen. Kein Urteil,
            // keine Kennzahl, keine Zeile in der Tabelle - was gelaufen ist, ist
            // gelaufen; interessant bleibt allein, ob es auf der Platte liegt.
            if ($s['start'] < $von) {
                $this->merkeBestand($marken, $s, $resolver->bestimme($s['titel']));
                continue;
            }
            $z['geprueft']++;
            $t = $resolver->bestimme($s['titel']);
            if ($t === null) {
                $z['ohne Favorit']++;
                // Auch was nicht auf der Wunschliste steht, kann laengst auf der
                // Platte liegen - eine abgesetzte Serie, eine, die man von Hand
                // mitgenommen hat. Fuer das Raster ist das dieselbe Auskunft.
                $this->merkeBestand($marken, $s, null);
                // Unzugeordnete Titel sammeln - nach BASISNAMEN, nicht je
                // Ausstrahlung. Zwoelftausend Zeilen sind keine Auskunft; die
                // paar hundert verschiedenen Namen dahinter sind eine.
                [$b, ] = TitelResolver::zerlege($s['titel']);
                $b = trim($b);
                if ($b !== '') {
                    if (!isset($fast[$b])) {
                        // Der Kanal der BOX kommt mit, nicht nur der Anzeigename:
                        // nur ueber ihn findet sich spaeter das Senderlogo.
                        $fast[$b] = ['titel' => $b, 'sender' => $sender[$s['kanal']] ?? $s['kanal'],
                                     'kanal' => (string) ($kanal[$s['kanal']] ?? ''), 'anzahl' => 0];
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
            // Was hinter dem Serienamen im Titel steht, kommt als EIGENES Feld mit:
            // bei den Krimireihen klebt der Episodentitel dort ("Tatort: Trotzdem")
            // und das Untertitel-Feld bleibt leer. Der Entscheider darf es aber nur
            // benutzen, wenn der Katalog es als Episodentitel bestaetigt - sonst
            // wuerde aus "Lethal Weapon - Zwei stahlharte Profis" (dem Kinofilm)
            // eine Folge der Serie.
            $u = $urteiler?->fuer([
                'serie'      => $t['ablage'],
                'titel'      => $s['titel'],
                'untertitel' => $s['untertitel'],
                'zusatz'     => $t['zusatz'],
                'folgeNum'   => $s['folge'],
                'kanal'      => $kanal[$s['kanal']],
                'start'      => $s['start'],
                'ende'       => $s['ende'],
            ]);
            if ($u !== null) {
                $z[$u['urteil']] = ($z[$u['urteil']] ?? 0) + 1;
                // "ausgeschlossen", "unklar" und "programmiert" sagen nichts
                // darueber, ob die Folge auf der Platte liegt - der Entscheider
                // steigt vorher aus. Also nachschlagen, und zwar mit SEINER
                // Nummer: der Tatort von 2019 faellt aus der Schranke
                // "season >= 2024" und liegt trotzdem im Bestand.
                if (!in_array($u['urteil'], ['vorhanden', 'mehrfach', 'aufnehmen'], true)) {
                    $this->merkeBestand($marken, $s, $t, $u);
                }
            }
            // Der Episodentitel fuer Tabelle und Timername, in dieser Reihenfolge:
            // der des Katalogs (so heisst die Folge auch auf der Platte), sonst der
            // Untertitel des EPG, sonst das, was im Titel hinter dem Serienamen
            // steht - ohne den Trenner, sonst hiesse die Aufnahme ": Trotzdem".
            $anzeige = (string) ($u['eptitel'] ?? '');
            if ($anzeige === '') {
                $anzeige = $s['untertitel'] !== ''
                    ? (string) $s['untertitel']
                    : trim((string) preg_replace('/^\s*(?::|[–—-])\s*/u', '', (string) $t['zusatz']));
            }
            // Inhaltsangabe nachreichen, wo das EPG keine hat. Fuenfzehn Prozent der
            // Ausstrahlungen kommen ohne Text daher; beim Anklicken stand dort ein
            // leeres Feld, obwohl TheTVDB die Handlung kennt. Nur DANN - eine
            // vorhandene Angabe des Senders bleibt, sie beschreibt genau diese
            // Ausstrahlung (Erstausstrahlung, Fassung, Laenge).
            $inhalt = '';
            if ($u !== null && $this->katalog !== null
                && trim((string) ($s['beschreibung'] ?? '')) === ''
                && method_exists($this->katalog, 'inhalt')) {
                $inhalt = (string) $this->katalog->inhalt($t['ablage'], (int) $u['staffel'], (int) $u['folge']);
                if (mb_strlen($inhalt) > 600) {
                    $inhalt = rtrim(mb_substr($inhalt, 0, 599)) . '…';
                }
            }
            $treffer[] = [
                'kanal'  => $kanal[$s['kanal']],
                // Die XMLTV-Kennung des Senders unveraendert mitnehmen. Der
                // Programmfuehrer fuehrt seine Sender genau darunter; ueber den
                // Namen zu gehen hiesse, drei Schreibweisen gegeneinander zu
                // normalisieren ("ORF 1HD", "ORF1 HD", "ORF 1 AT").
                'kanalId' => (string) $s['kanal'],
                'sender' => $sender[$s['kanal']] ?? $s['kanal'],
                'serie'  => $t['ablage'],
                'titel'  => $anzeige,
                'start'  => $s['start'],
                'ende'   => $s['ende'],
                'folge'  => $s['folge'],
                'regel'  => $t['regel'],
                'urteil' => $u['urteil'] ?? '',
                'grund'  => $u['grund'] ?? '',
                'staffelFolge' => ($u !== null && ($u['staffel'] > 0 || $u['folge'] > 0))
                                    ? Bestand::nummer($u['staffel'], $u['folge']) : '',
                'inhalt' => $inhalt,
            ];
        }
        usort($treffer, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        // Jetzt erst die Filme: was im Zeitraum haeufig laeuft, ist eine Reihe,
        // deren Titel zufaellig auch ueber einer einzelnen Aufnahme steht
        // ("Silvia kocht"). Solche Treffer waeren keine Auskunft, sondern ein
        // Dauerhaken ueber dem halben Raster.
        foreach ($filmMarken as $schluessel => $titelSchluessel) {
            if (!isset($marken[$schluessel]) && ($titelZahl[$titelSchluessel] ?? 0) <= self::FILM_HOECHSTENS) {
                // Eigener Wert, nicht die 1 der Serien: der Fund kommt aus einer
                // flachen Filmablage und steht allein auf dem Titel. Das Raster
                // faerbt ihn anders, damit man beim Hinsehen weiss, worauf die
                // Aussage beruht.
                $marken[$schluessel] = 3;
            }
        }

        // Verworfene Sender nach Haeufigkeit: oben steht, was am meisten kostet.
        arsort($verworfeneSender);
        $offen = [];
        foreach ($verworfeneSender as $name => $anzahl) {
            $offen[] = $name . ' (' . $anzahl . ')';
        }

        return [
            'sendungen'    => $treffer,
            'marken'       => $marken,
            'kennzahlen'   => $z + ['Serien mit Ausstrahlung' => count(array_unique(array_column($treffer, 'serie')))],
            'offeneSender' => $offen,
            'quellen'      => trim(($this->katalog?->bericht() ?? '')
                                . ($this->receiver !== null ? ' | ' . $this->receiver->bericht() : '')),
            'fastTreffer'  => self::naheDran($fast, $this->favoriten),
            'dauerMs'      => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Wie oft ein Titel im gelesenen Zeitraum hoechstens laufen darf, damit ein
     * Fund in der Filmablage noch als derselbe Film gilt. Vier Wiederholungen
     * schafft ein Spielfilm ueber die Hauptsender leicht; ein Magazin liegt weit
     * darueber.
     */
    private const FILM_HOECHSTENS = 4;

    /**
     * Liegt diese Ausstrahlung als Film in einer der flachen Ablagen?
     *
     * Anders als bei den Serien gibt es hier NUR den Titel zum Vergleichen.
     * Darum zwei Schranken: die Ausstrahlung darf keine Folge sein - weder
     * Untertitel noch Folgennummer - und der Titel darf im Zeitraum nicht
     * staendig wiederkehren (das prueft der Aufrufer am Ende, siehe oben).
     * Beides zusammen haelt "Terra X" und "Mein wunderbarer Kochsalon" drausen,
     * deren einzelne Folgen jemand von Hand mitgeschnitten hat.
     *
     * @param array<string,string> $filmMarken "kanal|start" => Titelschluessel
     * @param array<string,mixed>  $s
     */
    private function merkeFilm(array &$filmMarken, array $s, string $titelSchluessel): void
    {
        if ($this->bestand === null) {
            return;
        }
        if (trim((string) $s['untertitel']) !== '' || trim((string) $s['folge']) !== '') {
            return;
        }
        $kanal = trim((string) $s['kanal']);
        if ($kanal === '' || !$this->bestand->sucheFilm((string) $s['titel'])['da']) {
            return;
        }
        $filmMarken[$kanal . '|' . (int) $s['start']] = $titelSchluessel;
    }

    /**
     * Nachschlagen statt urteilen: liegt diese Ausstrahlung schon auf der Platte?
     *
     * Das Urteil des Entscheiders ist die bessere Auskunft - es kennt den
     * Episodenkatalog, die Staffelregeln und die Duplikate. Es gibt es aber nur
     * fuer Wunschserien im Entscheidungsfenster. Ueberall sonst bleibt der
     * direkte Griff in den Bestand: Serienname plus Nummer, ersatzweise plus
     * Episodentitel.
     *
     * Geraten wird auch hier nicht. Ohne Nummer UND ohne Episodentitel gibt es
     * keine Marke - ein Spielfilm, der zufaellig so heisst wie eine Serie im
     * Bestand, waere sonst "schon aufgenommen".
     *
     * @param array<string,int> $marken
     * @param array<string,mixed> $s Ausstrahlung aus dem XMLTV-Leser
     * @param ?array{ablage:string} $t Auflösung des Resolvers, null = kein Favorit
     * @param ?array{staffel:int,folge:int} $u Urteil des Entscheiders, wenn es eines gibt -
     *        seine Nummer ist die bessere, sie kommt aus dem Episodenkatalog
     */
    private function merkeBestand(array &$marken, array $s, ?array $t, ?array $u = null): void
    {
        if ($this->bestand === null) {
            return;
        }
        $kanal = trim((string) $s['kanal']);
        if ($kanal === '') {
            return;
        }
        $schluessel = $kanal . '|' . (int) $s['start'];
        if (isset($marken[$schluessel])) {
            return;
        }
        // Der Ablagename, nicht der Anzeigename: "CSI: Miami" liegt unter
        // "CSI Miami". Ohne Favorit bleibt der Basisname des Titels.
        $serie = $t !== null ? (string) $t['ablage'] : trim(TitelResolver::zerlege((string) $s['titel'])[0]);
        if ($serie === '') {
            return;
        }
        if ($u !== null) {
            $st = (int) $u['staffel'];
            $fo = (int) $u['folge'];
        } else {
            $n = EpisodenNummer::bestimme((string) $s['folge'], (string) $s['titel'], (string) $s['untertitel']);
            $st = $n['staffel'];
            $fo = $n['folge'];
        }
        $eptitel = $s['untertitel'] !== '' ? (string) $s['untertitel'] : ($t !== null ? (string) $t['zusatz'] : '');
        if ($this->bestand->suche($serie, $st, $fo, $eptitel)['da']) {
            $marken[$schluessel] = 1;
        }
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
    public static function fastAlsTabelle(array $fast, int $schwelle, ?callable $senderZelle = null): array
    {
        $out = [['XMLTV-Titel', 'naechster Favorit', 'Naehe', 'Sender', 'Ausstrahlungen']];
        foreach ($fast as $f) {
            $out[] = [
                (string) $f['titel'],
                (string) $f['favorit'],
                ((int) $f['naehe']) . ' %',
                $senderZelle ? (string) $senderZelle($f) : (string) $f['sender'],
                (string) $f['anzahl'],
            ];
        }
        return $out;
    }

    /**
     * Ergebnis als Zeilen-Array fuer die Tabellen-Variable (Zeile 0 = Kopf).
     *
     * @param list<array<string,mixed>>          $sendungen
     * @param (callable(array<string,mixed>):string)|null $senderZelle Baut die
     *        Senderspalte; ohne sie steht dort der blosse Name.
     * @return list<list<string>>
     */
    public static function alsTabelle(array $sendungen, ?callable $senderZelle = null): array
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
                // Der Sender darf ein Bild sein - das Altsystem hat hier das
                // Senderlogo gezeigt, und ohne es sieht die Tabelle nackt aus.
                // Wer keins liefert, bekommt weiter den Namen; die Spaltenzahl
                // bleibt gleich, denn die Seiten stellen Breite, Ausrichtung und
                // Suche ueber die POSITION ein.
                $senderZelle ? (string) $senderZelle($s) : (string) $s['sender'],
                (string) ($s['urteil'] ?? ''),
                (string) ($s['grund'] ?? ''),
            ];
        }
        return $out;
    }
}
