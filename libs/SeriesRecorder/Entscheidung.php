<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';
require_once __DIR__ . '/EpisodenNummer.php';
require_once __DIR__ . '/Bedingungen.php';
require_once __DIR__ . '/Episodenkatalog.php';
require_once __DIR__ . '/Receiver.php';
require_once __DIR__ . '/Quellenkette.php';

/**
 * Was soll mit einer Ausstrahlung geschehen?
 *
 * Die Reihenfolge der Pruefungen ist die eigentliche Aussage - sie entscheidet,
 * welches Etikett eine Sendung bekommt, die mehrere Kriterien erfuellt:
 *
 *   1. Weder Nummer noch Titel?       -> UNKLAR (nicht wiedererkennbar)
 *   2. Serien-Schranke verletzt?      -> AUSGESCHLOSSEN
 *   3. Schon einmal in dieser Liste?  -> MEHRFACH (nur die erste Ausstrahlung zaehlt)
 *   4. Am Receiver schon eingeplant?  -> PROGRAMMIERT
 *   5. Liegt sie auf der Platte?      -> VORHANDEN
 *   6. sonst                          -> AUFNEHMEN
 *
 * Punkt 1 vor Punkt 2 ist wichtig und war beim ersten Anlauf vertauscht: laeuft
 * eine bereits vorhandene Folge zweimal, nennt das Altsystem die erste
 * "vorhanden" und die zweite "mehrfach". Andersherum haetten beide "vorhanden"
 * geheissen - fachlich fast dasselbe, aber die Zahlen im Bericht waeren nicht
 * mehr mit dem Altsystem vergleichbar gewesen, und genau darauf beruht der
 * Parallellauf.
 */
final class Entscheidung
{
    public const AUFNEHMEN = 'aufnehmen';
    public const VORHANDEN = 'vorhanden';
    public const MEHRFACH  = 'mehrfach';
    public const UNKLAR    = 'unklar';
    public const AUSGESCHLOSSEN = 'ausgeschlossen';
    public const PROGRAMMIERT   = 'programmiert';

    /** @var array<string,true> bereits gesehene Folgen dieses Laufs */
    private array $gesehen = [];

    public function __construct(
        private Bestand $bestand,
        private ?Bedingungen $bedingungen = null,
        private ?EpisodenQuelle $katalog = null,
        private ?Receiver $receiver = null,
    ) {
    }

    /** Vor jedem Durchlauf zuruecksetzen - "mehrfach" gilt je Lauf, nicht ewig. */
    public function beginneLauf(): void
    {
        $this->gesehen = [];
    }

    /**
     * @param array{serie:string,titel:string,untertitel?:string,folgeNum?:string,kanal?:string,start?:int,ende?:int} $sendung
     * @return array{urteil:string,staffel:int,folge:int,quelle:string,grund:string,dateien:list<string>}
     */
    public function fuer(array $sendung): array
    {
        $serie = (string) $sendung['serie'];
        $eptitel = trim((string) ($sendung['untertitel'] ?? ''));

        $n = EpisodenNummer::bestimme(
            (string) ($sendung['folgeNum'] ?? ''),
            (string) $sendung['titel'],
            $eptitel
        );
        $st = $n['staffel'];
        $fo = $n['folge'];
        $quelle = $n['quelle'];

        // Liefert das EPG keine brauchbare Nummer, im Episodenkatalog nachschlagen.
        // Das ist derselbe Weg, den die Skript-Fassung ueber TheTVDB geht - nur aus
        // dem Cache, den sie dabei angelegt hat. Ohne diesen Schritt bleibt eine
        // ganze Serie unentscheidbar: fuer Tatort liefert das EPG NIE Staffel und
        // Folge, im Bestand liegt sie aber unter S2023E26.
        if ($this->katalog !== null && $st === 0 && $eptitel !== '') {
            $k = $this->katalog->finde($serie, $eptitel);
            if ($k !== null) {
                $st = $k['staffel'];
                $fo = $k['folge'];
                $quelle = 'katalog';
            }
        }

        $ergebnis = fn(string $u, string $grund, array $dateien = []) => [
            'urteil' => $u, 'staffel' => $st, 'folge' => $fo,
            'quelle' => $quelle, 'grund' => $grund, 'dateien' => $dateien,
        ];

        // Ohne jede Kennung ist die Folge nicht wiedererkennbar. Sie hier
        // aufzunehmen hiesse, sie bei jeder Wiederholung erneut aufzunehmen.
        if ($st === 0 && $fo === 0 && $eptitel === '') {
            return $ergebnis(self::UNKLAR, 'weder Staffel/Folge noch Episodentitel im EPG');
        }

        // Serien-Schranken VOR allem anderen: was ausgeschlossen ist, soll auch
        // nicht als "mehrfach" oder "vorhanden" in den Zahlen auftauchen - es ist
        // schlicht kein Kandidat.
        if ($this->bedingungen !== null) {
            $b = $this->bedingungen->pruefe($serie, $st, $fo);
            if (!$b['erlaubt']) {
                return $ergebnis(self::AUSGESCHLOSSEN, $b['grund']);
            }
        }

        $schluessel = Bestand::form($serie) . '|' . $st . '|' . $fo . '|' . Bestand::form($eptitel);
        if (isset($this->gesehen[$schluessel])) {
            return $ergebnis(self::MEHRFACH, 'laeuft in diesem Zeitraum erneut');
        }
        $this->gesehen[$schluessel] = true;

        // Vor dem Bestand: was schon programmiert ist, muss nicht entschieden
        // werden. Der Receiver ist die einzige Stelle, die das weiss - ohne ihn
        // meldet das Modul 'fehlt', waehrend die Aufnahme laengst eingeplant ist.
        if ($this->receiver !== null && ($sendung['start'] ?? 0) > 0
            && $this->receiver->istProgrammiert((string) ($sendung['kanal'] ?? ''),
                (int) $sendung['start'], (int) ($sendung['ende'] ?? $sendung['start']))) {
            return $ergebnis(self::PROGRAMMIERT, 'am Receiver bereits eingeplant');
        }

        $t = $this->bestand->suche($serie, $st, $fo, $eptitel);
        if ($t['da']) {
            return $ergebnis(self::VORHANDEN, 'liegt bereits vor (' . $t['weg'] . ')', $t['dateien']);
        }

        return $ergebnis(self::AUFNEHMEN, 'fehlt im Bestand');
    }
}
