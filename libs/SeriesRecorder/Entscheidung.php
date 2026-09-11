<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';
require_once __DIR__ . '/Staffelregeln.php';
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
        private ?Staffelregeln $staffelregeln = null,
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
    /** Ein Episodentitel, der keiner ist: leer oder nur die Nummer ("(S03/E09)", "3x07"). */
    private static function nurNummer(string $t): bool
    {
        $t = trim($t);
        return $t === ''
            || preg_match('/^\(?\s*S\s*\d{1,4}\s*[\/ ]?\s*E\s*\d{1,4}\s*\)?$/i', $t) === 1
            || preg_match('/^\(?\s*\d{1,2}\s*x\s*\d{1,3}\s*\)?$/i', $t) === 1;
    }

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

        // Die Wiederholungs-Kennzeichnung des TV-Planers gilt unabhaengig von der
        // Nummer - sie ist eine Aussage ueber die AUSSTRAHLUNG, nicht ueber die Folge.
        $planerWdh = null;
        if (array_key_exists('planerWdh', $sendung) && $sendung['planerWdh'] !== null) {
            $planerWdh = (bool) $sendung['planerWdh'];
        }

        // Liefert das EPG keine brauchbare Nummer, im Episodenkatalog nachschlagen.
        // Das ist derselbe Weg, den die Skript-Fassung ueber TheTVDB geht - nur aus
        // dem Cache, den sie dabei angelegt hat. Ohne diesen Schritt bleibt eine
        // ganze Serie unentscheidbar: fuer Tatort liefert das EPG NIE Staffel und
        // Folge, im Bestand liegt sie aber unter S2023E26.
        // Der Titel der QUELLE wird mitgenommen: der Aufnahmebestand ist danach
        // benannt ("Eisner - 45 - Glueck allein"), das EPG nennt nur den
        // Folgentitel. Ohne ihn traegt der Timer eine zweite Namensform.
        $katalogtitel = '';
        if ($this->katalog !== null && $st === 0 && $eptitel !== '') {
            $k = $this->katalog->finde($serie, $eptitel);
            if ($k !== null) {
                $st = $k['staffel'];
                $fo = $k['folge'];
                $quelle = 'katalog';
                $katalogtitel = (string) ($k['titel'] ?? '');
            }
        }

        // Zweiter Versuch mit dem, was im TITEL hinter dem Serienamen steht.
        // "Tatort: Trotzdem" traegt den Episodentitel dort, das Untertitel-Feld
        // bleibt leer - ohne diesen Griff bliebe die Reihe bei S00, obwohl der
        // Katalog die Folge kennt.
        //
        // Der Katalog muss es BESTAETIGEN. Findet er nichts, bleibt der Zusatz
        // aussen vor: "Lethal Weapon - Zwei stahlharte Profis" ist der Kinofilm
        // und keine Folge, und ein unbestaetigter Zusatz wuerde aus dem
        // ehrlichen "unklar" ein "aufnehmen" machen.
        $zusatz = trim((string) ($sendung['zusatz'] ?? ''));
        if ($this->katalog !== null && $st === 0 && $eptitel === '' && $zusatz !== '') {
            $k = $this->katalog->finde($serie, $zusatz);
            if ($k !== null) {
                $st = $k['staffel'];
                $fo = $k['folge'];
                $quelle = 'katalog';
                $eptitel = $zusatz;
                $katalogtitel = (string) ($k['titel'] ?? '');
            }
        }

        // Umgekehrter Weg: Nummer bekannt, Titel unbrauchbar. Das EPG schreibt bei
        // manchen Serien als Untertitel die Nummer noch einmal ("(S03/E09)"),
        // waehrend die Aufnahme "Walking on Sunshine - S03E09 - Folge 29" heisst.
        //
        // NUR dann. Einen vorhandenen Episodentitel durch den des Katalogs zu
        // ersetzen waere ein Rueckschritt: bei den alten Serien zaehlen EPG und
        // Katalog verschieden, und aus "Der Fluch von Kairo" wuerde "Hinter der
        // Mauer" - eine andere Folge, unter falschem Namen abgelegt. Gemessen am
        // 23.08.2026: 78 Titel haetten sich geaendert, gut zwanzig davon in eine
        // fremde Folge.
        if ($katalogtitel === '' && $this->katalog !== null && ($st > 0 || $fo > 0)
            && self::nurNummer($eptitel) && method_exists($this->katalog, 'titelZuNummer')) {
            $kt = $this->katalog->titelZuNummer($serie, $st, $fo);
            if ($kt !== '') {
                $katalogtitel = $kt;
            }
        }

        // Der TV-Planer - NACH dem Katalog, nicht davor.
        //
        // Er ist die einzige Quelle, die je Ausstrahlung sagt, welche Folge dort
        // wirklich laeuft: am 11.09.2026 fuehrte das XMLTV vier Ausstrahlungen von
        // "The Voice of Germany" als S16E01, obwohl auf ProSieben Folge 2 lief.
        //
        // Ihn ueber den KATALOG zu stellen war aber ein Fehler, und zwar ein teurer.
        // Beide zaehlen dieselbe Serie verschieden: der Planer fuehrt Tatort flach
        // (S00E1339), der Katalog nach Jahr (S2024E21) - und genau so liegt die
        // Aufnahme auf der Platte. Mit dem Planer als hoechster Instanz fand der
        // Bestandsabgleich 36 Aufnahmen nicht mehr und die Schranke "season >= 2024"
        // schloss sieben Serien zusaetzlich aus. Gemessen am 11.09.2026, eine Stunde
        // nach dem Einbau. Der Katalog bleibt die Zaehlung des Hauses; der Planer
        // spricht nur dort, wo der Katalog schweigt.
        //
        // Und noch eine Schranke: eine Staffel 0 des Planers darf eine ECHTE Staffel
        // des EPG nicht verdraengen. Der Planer fuehrt manche Reihen flach - "Blind
        // ermittelt" als S00E10, "Hundertdreizehn" als S00E01 -, waehrend EPG und
        // Ablage sie als S01 kennen. Ohne diese Schranke fanden sieben Marken ihre
        // Aufnahme nicht mehr (gemessen 11.09.2026). Er ergaenzt also nach unten, aber
        // er verschlechtert nicht.
        $pst = (int) ($sendung['planerStaffel'] ?? 0);
        $pfo = (int) ($sendung['planerFolge'] ?? 0);
        if ($pst === 0 && $st > 0) {
            $pst = 0;
            $pfo = 0;
        }
        if ($quelle !== 'katalog' && ($pst > 0 || $pfo > 0)) {
            $st = $pst;
            $fo = $pfo;
            $quelle = ($quelle === '' ? 'planer' : $quelle . '+planer');
            $pt = trim((string) ($sendung['planerTitel'] ?? ''));
            if ($pt !== '' && self::nurNummer($eptitel)) {
                $eptitel = $pt;
            }
        }

        // Staffel berichtigen, BEVOR irgendetwas verglichen wird.
        //
        // Die Reihenfolge ist der ganze Punkt: der Bestand auf der Platte liegt
        // unter der berichtigten Nummer ("Season 1"), die Serien-Schranken rechnen
        // mit ihr, und der Timer traegt sie spaeter im Namen. Wer erst entscheidet
        // und dann berichtigt, sucht S00E04 im Bestand und findet die laengst
        // aufgenommene S01E04 nicht - und nimmt sie ein zweites Mal auf.
        if ($this->staffelregeln !== null) {
            $neu = $this->staffelregeln->fuer($serie, $st);
            if ($neu !== null) {
                $st = $neu;
                $quelle = ($quelle === '' ? 'staffelregel' : $quelle . '+staffelregel');
            }
        }

        $ergebnis = fn(string $u, string $grund, array $dateien = []) => [
            'urteil' => $u, 'staffel' => $st, 'folge' => $fo, 'eptitel' => $katalogtitel,
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

        // Wiederholung im Zeitraum? Das entscheidet weiterhin der gesehen-Merker, und
        // zwar aus gutem Grund NICHT die "(Wdh.)"-Kennzeichnung des Planers.
        //
        // Sie sagt, dass die Ausstrahlung eine Wiederholung IST - nicht, dass man die
        // Folge schon hat. Laeuft die Erstausstrahlung ausserhalb des Vorschaufensters,
        // ist die Wiederholung die einzige Gelegenheit, und genau die haette der Riegel
        // weggeworfen. Beim Einbau am 11.09.2026 fielen darueber 34 Ausstrahlungen von
        // "vorhanden" auf "mehrfach", bevor es auffiel.
        //
        // Der Planer wirkt hier trotzdem, nur an der richtigen Stelle: mit SEINER Nummer
        // im Schluessel unterscheidet der Merker endlich zwei Folgen, die das XMLTV
        // gleich nennt. Genau daran ist der Voice-Fall gescheitert.
        $schluessel = Bestand::form($serie) . '|' . $st . '|' . $fo . '|' . Bestand::form($eptitel);
        if (isset($this->gesehen[$schluessel])) {
            return $ergebnis(self::MEHRFACH, 'laeuft in diesem Zeitraum erneut'
                . ($planerWdh === true ? ' (Planer: Wdh.)' : ''));
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
