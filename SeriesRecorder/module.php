<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/SeriesRecorder/Analyse.php';
// Die Netzquellen haengen an keiner anderen Datei - ohne diese beiden Zeilen
// faellt erst der Lauf auf die Nase, und zwar nur, wenn jemand das Gate
// einschaltet. Genau so ist es passiert.
require_once __DIR__ . '/../libs/SeriesRecorder/TmdbQuelle.php';
require_once __DIR__ . '/../libs/SeriesRecorder/TvdbQuelle.php';
require_once __DIR__ . '/../libs/SeriesRecorder/XmltvBezug.php';
require_once __DIR__ . '/../libs/SeriesRecorder/WunschlisteBezug.php';
require_once __DIR__ . '/../libs/SeriesRecorder/WunschlistePlaner.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Serienliste.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Bestandsscan.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Receiver.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Duplikate.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Katalogverweise.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Katalogzuordnung.php';
require_once __DIR__ . '/../libs/SeriesRecorder/Dateisatz.php';

use Hoep\SeriesRecorder\Analyse;
use Hoep\SeriesRecorder\Bedingungen;
use Hoep\SeriesRecorder\Staffelregeln;
use Hoep\SeriesRecorder\Katalogverweise;
use Hoep\SeriesRecorder\Katalogzuordnung;
use Hoep\SeriesRecorder\Bestand;
use Hoep\SeriesRecorder\Bestandsscan;
use Hoep\SeriesRecorder\Dateisatz;
use Hoep\SeriesRecorder\Duplikate;
use Hoep\SeriesRecorder\Episodenkatalog;
use Hoep\SeriesRecorder\Quellenkette;
use Hoep\SeriesRecorder\Serienliste;
use Hoep\SeriesRecorder\Receiver;
use Hoep\SeriesRecorder\TmdbQuelle;
use Hoep\SeriesRecorder\TvdbQuelle;
use Hoep\SeriesRecorder\KanalMapper;
use Hoep\SeriesRecorder\TitelResolver;
use Hoep\SeriesRecorder\WunschlisteBezug;
use Hoep\SeriesRecorder\WunschlistePlaner;
use Hoep\SeriesRecorder\XmltvBezug;
use Hoep\SeriesRecorder\XmltvLeser;

/**
 * Serienrecorder.
 *
 * Das Modul ordnet die Ausstrahlungen des XMLTV den Serien zu, die aufgenommen
 * werden sollen, gleicht sie gegen den Bestand auf der Platte ab und schickt,
 * was fehlt, ueber die Instanz EnigmaReceiver an die Box. Der Weg zum Receiver
 * fuehrt ausschliesslich dort entlang - zwei Absender auf demselben Geraet
 * waeren doppelte Aufnahmen.
 *
 * Zwei Gates, absichtlich getrennt: "Scharf" erlaubt das Programmieren,
 * "LoeschenScharf" das Loeschen von Duplikaten. Wer aufnehmen will, soll damit
 * nicht nebenbei die Erlaubnis zum Loeschen erteilen.
 *
 * WELCHE Serien aufgenommen werden, steht in der Eigenschaft "Serienliste".
 * Sie ist der Master; die Wunschliste von wunschliste.de wird bei jedem Bezug
 * HINEIN konsolidiert. Von dort verbrauchen wir ohnehin nur Namen - aber sie
 * war frueher auch der einzige Weg, eine Serie hinzuzufuegen, und damit hing
 * der Betrieb an einer fremden Anmeldestrecke.
 */
class SeriesRecorder extends IPSModule
{
    // Je Aufgabe ein eigener Timer statt eines gemeinsamen. Die Aufgaben haben
    // verschiedene Kosten und verschiedene Halbwertszeiten: die Programmvorschau
    // aendert sich zweimal am Tag, die Zuordnung soll oefter laufen. Ein
    // gemeinsamer Takt muesste sich am teuersten Posten orientieren.
    private const TIMER_LAUF   = 'Lauf';
    private const TIMER_BEZUG  = 'Bezug';
    private const TIMER_WUNSCH = 'Wunschliste';
    private const TIMER_SCAN   = 'Bestandsscan';
    private const TIMER_DUP    = 'Duplikate';
    private const TIMER_PROG   = 'Programmieren';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyBoolean('Armed', false);
        // Loeschen ist ein EIGENES Gate.
        //
        // "Scharf" heisst: Aufnahmen programmieren. Dass damit zugleich Dateien
        // von der Platte verschwinden duerfen, waere eine Ueberraschung - und im
        // Zweifel eine teure: die Duplikatliste umfasste bei der ersten Messung
        // rund 30 GB. Wer loeschen will, sagt es hier ausdruecklich.
        $this->RegisterPropertyBoolean('LoeschenScharf', false);
        $this->RegisterPropertyInteger('Intervall', 60);          // Minuten, 0 = kein Timer
        $this->RegisterPropertyInteger('IntervallBezug', 0);      // Programmvorschau holen
        $this->RegisterPropertyString('XmltvUrl', '');
        // Eigene Zieldatei: solange das Altsystem laeuft, wuerden sich beide
        // gegenseitig die Datei ueberschreiben.
        $this->RegisterPropertyString('XmltvZiel', 'xmltv-sr.xml');
        // Zugang zur Wunschliste. Gehoert wie die API-Schluessel hierher und
        // nicht in eine Codezeile des Ablaufskripts.
        $this->RegisterPropertyInteger('IntervallWunsch', 0);
        $this->RegisterPropertyString('WunschBenutzer', '');
        $this->RegisterPropertyString('WunschPasswort', '');
        $this->RegisterPropertyString('WunschZiel', 'favorites-sr.xml');
        // Bestandsscan: welche Aufnahmen liegen auf der Platte?
        $this->RegisterPropertyInteger('IntervallScan', 0);
        $this->RegisterPropertyString('Aufnahmepfade', '/mnt/Aufnahmen');
        // Die von Hand mitgeschnittenen Filme liegen FLACH in eigenen Freigaben
        // ("02 - Filme Sabina", "03 - Filme Peter" aus Sicht der Box). Dort gibt
        // es keinen Serienordner und keine Folgennummer - der Scan liest sie
        // deshalb getrennt und schreibt Filmzeilen.
        $this->RegisterPropertyString('Filmpfade', '/mnt/Aufnahmen_Sabina,/mnt/Aufnahmen_Peter');
        $this->RegisterPropertyString('ScanZiel', 'recordings-sr.txt');
        // Untergrenze gegen den Fall einer nicht eingebundenen Freigabe: darunter
        // gilt der Scan als fehlgeschlagen und die alte Liste bleibt stehen.
        $this->RegisterPropertyInteger('ScanMindestens', 50);
        // Findet der Scan fast nichts, ist meist die Netzwerkfreigabe abgerissen.
        // Das Altsystem ruft dann 'mount -a'; hier ist es abschaltbar, weil es der
        // einzige Punkt ist, an dem das Modul ins Betriebssystem greift.
        $this->RegisterPropertyBoolean('FreigabeEinbinden', true);
        // Receiver: in diesem Stand NUR zum Lesen der Timerliste.
        $this->RegisterPropertyString('ReceiverIp', '');
        $this->RegisterPropertyString('ReceiverBouquet', '');
        $this->RegisterPropertyInteger('ReceiverTuner', 18);
        // Vor- und Nachlauf in Minuten. ACHTUNG bei der Uebernahme aus dem
        // Altskript: dort ist die Zuordnung vertauscht - 'preRecord' bekommt den
        // Wert der Nachlauf-Variablen und 'postRecord' den der Vorlauf-Variablen.
        // Weil beide auf 2 stehen, faellt es dort nicht auf.
        $this->RegisterPropertyInteger('Vorlauf', 2);
        $this->RegisterPropertyInteger('Nachlauf', 2);
        // Dieselbe Ablage aus zwei Blickwinkeln: der Receiver kennt sie unter
        // seinem Einhaengepunkt, Symcon unter dem eigenen. Beides wird gebraucht -
        // der erste Pfad geht als Aufnahmeverzeichnis an den Timer, im zweiten
        // wird der Serienordner angelegt. Enigma legt ihn NICHT selbst an; fehlt
        // er, laeuft die Aufnahme ins Leere. Genau deshalb hat es das Altskript
        // auch getan (30398: mkdir auf SymconDir vor jedem timeradd).
        $this->RegisterPropertyString('ReceiverAufnahmepfad', '/mnt/net/VUAufnahmen');
        $this->RegisterPropertyString('AufnahmepfadLokal', '/mnt/Aufnahmen');
        // Duplikate: Suchen ist harmlos, Loeschen haengt am Scharf-Gate.
        $this->RegisterPropertyInteger('IntervallDuplikate', 0);
        $this->RegisterPropertyString('Datenpfad', '/var/lib/symcon/serienrecorder/');
        $this->RegisterPropertyString('XmltvDatei', 'xmltv.xml');
        $this->RegisterPropertyString('FavoritenDatei', 'favorites.xml');
        $this->RegisterPropertyString('KanaeleDatei', 'channels.json');
        $this->RegisterPropertyString('BestandDatei', 'recordings.txt');
        $this->RegisterPropertyInteger('Vorschau', 14);           // Tage nach vorn
        $this->RegisterPropertyBoolean('Katalog', true);          // Episodennummern aus dem TVDB-Cache
        // TheTVDB als Rueckfallebene HINTER dem Cache. Standard aus: solange das
        // Modul nur mitliest, soll ein Lauf nichts nach draussen tun.
        $this->RegisterPropertyBoolean('TvdbNetz', false);
        $this->RegisterPropertyString('TvdbApiKey', '');
        $this->RegisterPropertyInteger('TvdbDeckel', 25);         // Abfragen je Lauf
        $this->RegisterPropertyInteger('TvdbCacheStunden', 168);
        // TMDB sucht noch, TheTVDB nicht mehr - deshalb steht es in der Kette VOR
        // TheTVDB und ist die Quelle fuer alles, was in keinem Cache steht.
        $this->RegisterPropertyBoolean('TmdbNetz', false);
        $this->RegisterPropertyString('TmdbApiKey', '');
        $this->RegisterPropertyInteger('TmdbDeckel', 25);
        $this->RegisterPropertyInteger('TmdbCacheStunden', 168);

        // Regeln als Daten, nicht als Code. In der Skript-Fassung standen sie als
        // PHP-Literale mitten im Ablauf - deshalb hat auch nie jemand bemerkt, dass
        // die Tatort-Bedingung faktisch jede Tatort-Ausstrahlung verwarf.
        $this->RegisterPropertyString('Kanaltabelle', '[]');      // XMLTV-Name => Empfangskanal
        $this->RegisterPropertyString('Titeltabelle', '[]');      // XMLTV-Titel => Favorit + Ablagename
        $this->RegisterPropertyString('Bedingungen', '[]');       // Serie + Feld + Vergleich + Wert
        $this->RegisterPropertyString('Staffeltabelle', '[]');    // Serie + von + nach
        // Selbst vergebene Kennungen bei TheTVDB und TMDB. Sie sind kein Luxus:
        // die Seriensuche von TheTVDB v3 ist tot, und ohne Kennung gibt es fuer
        // eine unbekannte Serie keinen Weg zu Staffel und Folge.
        $this->RegisterPropertyString('Katalogtabelle', '[]');    // Serie + TheTVDB-ID + TMDB-ID

        // Die Liste der Serien, die aufgenommen werden sollen. Sie ist der
        // Master - die Wunschliste wird HINEIN konsolidiert, nicht umgekehrt.
        // Warum nicht weiter die Datei allein: von ihr verbrauchen wir nur den
        // Namen, aber sie war der einzige Weg, eine Serie AUFZUNEHMEN. Faellt
        // die fremde Anmeldestrecke aus, ginge sonst gar nichts mehr.
        $this->RegisterPropertyString('Serienliste', '[]');       // Serie + Herkunft + Schalter

        // Der Weg zum Receiver fuehrt ueber das Modul EnigmaReceiver, nicht ueber
        // eine eigene Verbindung. Dort sitzt das Gate, dort stehen Vor- und
        // Nachlauf JE RECEIVER, und dort ist die Positivliste der Endpunkte.
        // Zwei Absender auf demselben Geraet waeren ein Rezept fuer doppelte
        // Aufnahmen - deshalb genau einer.
        $this->RegisterPropertyInteger('ErInstanz', 0);
        $this->RegisterPropertyInteger('IntervallProgramm', 0);   // Minuten, 0 = kein Timer

        $this->RegisterTimer(self::TIMER_LAUF, 0, 'SR_Analyse($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_BEZUG, 0, 'SR_HoleProgramm($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_WUNSCH, 0, 'SR_HoleWunschliste($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_SCAN, 0, 'SR_ScanneBestand($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_DUP, 0, 'SR_PruefeDuplikate($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_PROG, 0, 'SR_Programmiere($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableInteger('LetzterLauf', 'Letzter Lauf', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('Dauer', 'Dauer (ms)', '', 30);
        $this->RegisterVariableInteger('Zugeordnet', 'Zugeordnete Ausstrahlungen', '', 40);
        $this->RegisterVariableInteger('OhneEmpfang', 'Verworfen (Sender fehlt)', '', 50);
        $this->RegisterVariableInteger('Aufnehmen', 'Fehlt im Bestand', '', 55);
        $this->RegisterVariableInteger('Ausgeschlossen', 'Durch Schranke verworfen', '', 56);
        $this->RegisterVariableInteger('Programmiert', 'Am Receiver eingeplant', '', 57);
        $this->RegisterVariableString('Ausstrahlungen', 'Ausstrahlungen (JSON)', '', 60);
        $this->RegisterVariableString('OffeneSender', 'Sender ohne Empfangskanal', '', 70);
        $this->RegisterVariableString('Quellen', 'Episodenquellen', '', 80);
        $this->RegisterVariableString('PlanerAbweichungen', 'TV-Planer berichtigt', '~TextBox', 82);
        $this->RegisterVariableString('Bezug', 'Programmvorschau geholt', '', 90);
        $this->RegisterVariableString('Wunschliste', 'Wunschliste geholt', '', 100);
        $this->RegisterVariableString('Bestand', 'Bestand aufgenommen', '', 110);
        $this->RegisterVariableString('Duplikate', 'Duplikate geprueft', '', 120);
        $this->RegisterVariableString('DuplikateListe', 'Duplikate (JSON)', '', 130);
        $this->RegisterVariableString('Serien', 'Serien (JSON)', '', 140);
        $this->RegisterVariableString('Programmierung', 'Programmierung', '', 150);
        $this->RegisterVariableString('ProgrammListe', 'Programmierung (JSON)', '', 160);
        $this->RegisterVariableString('Matching', 'Matching (JSON)', '', 170);
        $this->RegisterVariableString('Protokoll', 'Protokoll (JSON)', '', 180);
        $this->RegisterVariableString('Kennzahlen', 'Kennzahlen (JSON)', '', 190);
        // Welche Ausstrahlungen schon auf der Platte liegen - fuer die Markierung
        // im Programmfuehrer. Bewusst eine eigene, schmale Variable: die grosse
        // Ausstrahlungstabelle traegt Anzeigetext (inzwischen sogar Bilder) und
        // taugt nicht als Nachschlagewerk.
        $this->RegisterVariableString('Marken', 'Bestandsmarken (JSON)', '', 195);

        // Handeintraege dorthin schreiben, wo die Handler ihre Suchergebnisse
        // erwarten - beim Speichern und damit sofort, nicht erst beim naechsten Lauf.
        $this->schreibeKatalogzuordnung();
        // Zwei Zahlen aus dem Bestandsscan als eigene Variablen - nicht nur als
        // Text. Nur so lassen sie sich aufzeichnen, und nur so kann die Historie
        // des Altsystems ("Aufnahmen in der Datenbank", zwei Jahre) hier
        // weiterlaufen statt bei null anzufangen.
        $this->RegisterVariableInteger('BestandAufnahmen', 'Aufnahmen im Bestand', '', 200);
        $this->RegisterVariableInteger('BestandSerien', 'Serien im Bestand', '', 210);

        $an = $this->ReadPropertyBoolean('Aktiv');
        $this->SetTimerInterval(self::TIMER_LAUF,
            ($an ? max(0, $this->ReadPropertyInteger('Intervall')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_BEZUG,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallBezug')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_WUNSCH,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallWunsch')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_SCAN,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallScan')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_DUP,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallDuplikate')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_PROG,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallProgramm')) : 0) * 60 * 1000);

        $fehlt = $this->fehlendeDateien();
        if ($fehlt !== []) {
            $this->SetStatus(200);   // Konfiguration unvollstaendig
            $this->SetValue('Status', 'Datei fehlt: ' . implode(', ', $fehlt));
            return;
        }
        $this->SetStatus(102);
    }

    // ==================================================================
    // Oeffentliche Funktionen
    // ==================================================================

    /** @var array<string,mixed>|null Rohergebnis des letzten Laufs in DIESEM Prozess */
    private ?array $letzterLauf = null;

    /** Lesender Durchlauf. Ergebnis steht in den Variablen; nichts wird geschaltet. */
    public function Analyse(): string
    {
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            return json_encode(['ok' => false, 'grund' => 'nicht aktiv']);
        }
        $fehlt = $this->fehlendeDateien();
        if ($fehlt !== []) {
            $this->SetValue('Status', 'Datei fehlt: ' . implode(', ', $fehlt));
            return json_encode(['ok' => false, 'grund' => 'Datei fehlt', 'dateien' => $fehlt]);
        }

        $vorschau = max(1, $this->ReadPropertyInteger('Vorschau'));
        $this->schreibeKatalogzuordnung();   // Handeintraege gueltig halten
        $a = $this->baueAnalyse();
        // Die selbst geholte Datei hat Vorrang; fehlt sie, wird die des
        // Altsystems gelesen. So laeuft das Modul in jeder Ausbaustufe.
        $eigen = $this->pfad('XmltvZiel');
        $quelle = is_readable($eigen) ? $eigen : $this->pfad('XmltvDatei');
        // Entschieden wird ab einer Stunde rueckwaerts, nachgeschlagen ab
        // gestern frueh: das Raster zeigt so weit zurueck, wie die XMLTV-Datei
        // reicht, und eine Sendung von gestern Abend ist genauso "schon
        // aufgenommen" wie eine von heute Abend. Programmiert wird davon
        // nichts - siehe Analyse::lauf. Deckung dadurch von 49 auf 95 Prozent
        // der Wunschserien-Ausstrahlungen im Raster, Kosten rund 300 ms.
        $e = $a->lauf(new XmltvLeser($quelle), time() - 3600, time() + $vorschau * 86400,
                      (int) strtotime('yesterday 00:00'));

        $this->SetValue('Zugeordnet', (int) ($e['kennzahlen']['zugeordnet'] ?? 0));
        $this->SetValue('OhneEmpfang', (int) ($e['kennzahlen']['Sender nicht empfangbar'] ?? 0));
        $this->SetValue('Aufnehmen', (int) ($e['kennzahlen']['aufnehmen'] ?? 0));
        $this->SetValue('Programmiert', (int) ($e['kennzahlen']['programmiert'] ?? 0));
        $this->SetValue('Ausgeschlossen', (int) ($e['kennzahlen']['ausgeschlossen'] ?? 0));
        $this->SetValue('Dauer', $e['dauerMs']);
        $this->SetValue('LetzterLauf', time());
        $karte = $this->piconKarte();
        $this->SetValue('Ausstrahlungen', json_encode(
            Analyse::alsTabelle($e['sendungen'], fn(array $s): string => $this->senderZelle(
                $karte, (string) ($s['kanal'] ?? ''), (string) ($s['sender'] ?? ''))),
            JSON_UNESCAPED_UNICODE));
        $this->SetValue('Marken', $this->bestandsmarken($e['sendungen'], (array) ($e['marken'] ?? [])));
        $this->SetValue('OffeneSender', implode("\n", $e['offeneSender']));
        $this->SetValue('Serien', $this->serientabelle($e['sendungen']));
        $this->SetValue('Matching', (string) json_encode(
            Analyse::fastAlsTabelle((array) ($e['fastTreffer'] ?? []), TitelResolver::schwelle(),
                fn(array $f): string => $this->senderZelle(
                    $karte, (string) ($f['kanal'] ?? ''), (string) ($f['sender'] ?? ''))),
            JSON_UNESCAPED_UNICODE));
        $this->SetValue('Kennzahlen', $this->kennzahlentabelle($e));
        $this->SetValue('Protokoll', $this->protokolltabelle());
        $this->SetValue('Quellen', (string) ($e['quellen'] ?? ''));
        // Die Faelle, in denen der TV-Planer die Nummer des EPG berichtigt hat. Sie
        // gehoeren vor Augen: es sind genau die Ausstrahlungen, bei denen frueher
        // still geraten wurde - und manchmal falsch.
        $abw = (array) ($e['planerAbweichungen'] ?? []);
        $this->SetValue('PlanerAbweichungen', $abw === []
            ? date('d.m. H:i') . ' · keine Abweichung zwischen EPG und TV-Planer'
            : date('d.m. H:i') . ' · ' . count($abw) . ' berichtigt' . "\n" . implode("\n", $abw));
        $this->SetValue('Status', sprintf('%d Ausstrahlungen, davon %d fehlend; %d Serien, %d ms%s',
            $e['kennzahlen']['zugeordnet'] ?? 0,
            $e['kennzahlen']['aufnehmen'] ?? 0,
            $e['kennzahlen']['Serien mit Ausstrahlung'] ?? 0,
            $e['dauerMs'],
            $this->ReadPropertyBoolean('Armed') ? '' : ' (nur lesend)'));
        $this->SendDebug('SR.quelle', 'gelesen aus ' . basename($quelle), 0);

        // Fuer die Programmierung im selben Durchlauf: sie braucht Kanalnamen und
        // Sekunden, die Anzeigetabelle hat nur Datum und Uhrzeit.
        $this->letzterLauf = $e;

        return json_encode(['ok' => true] + $e['kennzahlen'] + ['dauerMs' => $e['dauerMs']], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Aufnahmen programmieren - ueber das Modul EnigmaReceiver.
     *
     * Das ist der Schritt, der den Serienrecorder vom Beobachter zum Betreiber
     * macht. Er geht bewusst NICHT selbst an die Box:
     *
     *  - Vor- und Nachlauf gehoeren dem Receiver. Jede Box hat eigene Werte
     *    (Anlaufzeit des Tuners, Umschaltdauer), und ER_PlaneAufnahme rechnet sie
     *    aus IHRER Instanz dazu. Genau deshalb schickt diese Stelle die
     *    ROHEN Sendezeiten - wer hier schon rechnet, rechnet doppelt.
     *  - Die verbindlichen Zeiten kommen aus dem EPG der Box, nicht aus XMLTV.
     *    Das erledigt ER_PlaneAufnahme ueber seine eigene Suche.
     *  - Das Gate sitzt dort. Diese Funktion hat ein zweites (Armed): erst wenn
     *    BEIDE offen sind, wird geschrieben. Ist eines zu, entsteht eine Liste
     *    mit dem, was passiert waere.
     *
     * Der Timername folgt dem Altsystem Zeichen fuer Zeichen
     * ("Serie - S01E02 - Episodentitel"): an ihm haengen der Bestandsscan und
     * die Duplikaterkennung. Ein anderer Name hiesse, dass jede Aufnahme als neu
     * gilt und beim naechsten Lauf noch einmal programmiert wird.
     */
    public function Programmiere(): string
    {
        if (!$this->ReadPropertyBoolean('Aktiv')) {
            return json_encode(['ok' => false, 'grund' => 'nicht aktiv']);
        }
        $er = $this->ReadPropertyInteger('ErInstanz');
        if ($er <= 0 || !@IPS_InstanceExists($er)) {
            $this->SetValue('Programmierung', date('d.m. H:i') . ' · keine Receiver-Instanz gewaehlt');
            return json_encode(['ok' => false, 'grund' => 'keine Receiver-Instanz gewaehlt']);
        }
        if (!function_exists('ER_PlaneAufnahme') || !function_exists('ER_FuehreAus')) {
            $this->SetValue('Programmierung', date('d.m. H:i') . ' · Modul EnigmaReceiver nicht geladen');
            return json_encode(['ok' => false, 'grund' => 'ER-Funktionen fehlen']);
        }

        $roh = $this->Analyse();                       // frischer Lauf; Urteile stehen danach in den Variablen
        $kz  = json_decode($roh, true);
        if (empty($kz['ok'])) {
            return $roh;
        }
        $sendungen = $this->letzteSendungen();
        if ($sendungen === []) {
            $this->SetValue('Programmierung', date('d.m. H:i') . ' · nichts zu programmieren');
            return json_encode(['ok' => true, 'programmiert' => 0, 'hinweis' => 'keine Sendungen']);
        }
        $scharf = $this->ReadPropertyBoolean('Armed');

        $karte = $this->piconKarte();
        $gesetzt = 0; $schon = 0; $konflikt = 0; $fehler = 0; $vorschlag = 0;
        $zeilen = [['Datum', 'Zeit', 'Serie', 'Folge', 'Sender', 'Ergebnis', 'Meldung']];
        foreach ($sendungen as $x) {
            if (($x['urteil'] ?? '') !== 'aufnehmen') {
                continue;
            }
            $auftrag = [
                'sender' => (string) $x['kanal'],      // Kanalname der Box; ER loest ihn zur Referenz auf
                'start'  => (int) $x['start'],         // ROHE Sendezeit - Vor-/Nachlauf legt der Receiver dazu
                'ende'   => (int) $x['ende'],
                'titel'  => $this->timername($x),
                'kurz'   => (string) ($x['titel'] ?? ''),
            ];
            // Ablageort je Serie und Staffel. Ohne ihn landet alles im
            // Vorgabeordner der Box - und der Bestandsscan, der nach
            // "<Serie>/Season <n>" sucht, findet die Aufnahme nie wieder.
            $verz = $this->aufnahmeverzeichnis($x);
            if ($verz !== '') {
                $auftrag['verzeichnis'] = $verz;
                $this->legeOrdnerAn($x);
            }
            $v = json_decode(ER_PlaneAufnahme($er, json_encode($auftrag)), true);
            $zeile = [date('d.m.', (int) $x['start']), date('H:i', (int) $x['start']),
                      (string) $x['serie'], (string) ($x['staffelFolge'] ?? ''),
                      $this->senderZelle($karte, (string) ($x['kanal'] ?? ''), (string) $x['sender'])];

            if (empty($v['ok'])) {
                $fehler++;
                $zeilen[] = array_merge($zeile, ['Fehler', (string) ($v['fehler'] ?? 'unbekannt')]);
                continue;
            }
            if (!empty($v['schonDa'])) {
                $schon++;
                $zeilen[] = array_merge($zeile, ['steht schon', (string) $v['lesbar']]);
                continue;
            }
            if (!$scharf || empty($v['scharf'])) {
                $vorschlag++;
                $zeilen[] = array_merge($zeile, ['Vorschlag', (string) $v['lesbar'] . ' · ' . (string) $v['hinweis']]);
                continue;
            }
            $a = json_decode(ER_FuehreAus($er, json_encode($v['vorschlag'])), true);
            if (!empty($a['ok'])) {
                $gesetzt++;
                $zeilen[] = array_merge($zeile, ['programmiert', (string) $v['lesbar']]);
            } elseif (!empty($a['konflikte'])) {
                $konflikt++;
                $zeilen[] = array_merge($zeile, ['Konflikt', implode(' / ', (array) $a['konflikte'])]);
            } else {
                $fehler++;
                $zeilen[] = array_merge($zeile, ['abgelehnt', (string) ($a['fehler'] ?? 'unbekannt')]);
            }
        }

        $this->SetValue('ProgrammListe', (string) json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        $text = sprintf('%s · %d programmiert, %d standen schon, %d Vorschlaege, %d Konflikte, %d Fehler%s',
            date('d.m. H:i'), $gesetzt, $schon, $vorschlag, $konflikt, $fehler,
            $scharf ? '' : ' (nicht scharf)');
        $this->SetValue('Programmierung', $text);
        return json_encode(['ok' => true, 'programmiert' => $gesetzt, 'schonDa' => $schon,
                            'vorschlaege' => $vorschlag, 'konflikte' => $konflikt, 'fehler' => $fehler,
                            'scharf' => $scharf], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Serienuebersicht: was laeuft, welche Staffelregel greift, und wie fuehren
     * die Datenbanken die Reihe.
     *
     * Die Verweise sind der eigentliche Zweck. Ob eine Staffelregel noetig ist,
     * entscheidet sich daran, wie TheTVDB oder TMDB zaehlen - und das sieht man
     * in zehn Sekunden auf deren Seite, wenn man den Link hat. Gebaut wird die
     * Tabelle aus den lokalen Ablagen, ohne einen einzigen Netzaufruf.
     */
    public function Serienuebersicht(): string
    {
        // Die Zaehlspalten aus dem LETZTEN Lauf uebernehmen, statt sie leer zu
        // lassen: die Uebersicht wird zwischen zwei Laeufen gelesen, und "keine
        // Ausstrahlung" waere dort eine falsche Aussage statt einer fehlenden.
        $tab = json_decode($this->GetValue('Ausstrahlungen'), true);
        $sendungen = [];
        if (is_array($tab) && count($tab) > 1) {
            $kopf = array_flip(array_map('strval', $tab[0]));
            $iSerie = $kopf['Serie'] ?? null;
            $iFolge = $kopf['Folge'] ?? null;
            foreach (array_slice($tab, 1) as $z) {
                if ($iSerie === null || !isset($z[$iSerie])) {
                    continue;
                }
                $sendungen[] = [
                    'serie'        => (string) $z[$iSerie],
                    'staffelFolge' => $iFolge !== null ? (string) ($z[$iFolge] ?? '') : '',
                ];
            }
        }
        return $this->serientabelle($sendungen);
    }

    /** Diagnose: welchem Favoriten wuerde dieser Titel zugeordnet? */
    public function TitelProbe(string $Titel): string
    {
        $r = new TitelResolver($this->favoriten(), $this->titeltabelle()['aliase']);
        $r->setAblagenamen($this->titeltabelle()['ablage']);
        $t = $r->bestimme($Titel);
        return json_encode($t ?? ['favorit' => null, 'grund' => 'kein Kandidat ueber der Schwelle'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Holt die Programmvorschau vom Anbieter. Schreibt in die EIGENE Datei -
     * das Altsystem behaelt seine, solange beide laufen.
     */
    public function HoleProgramm(): string
    {
        $url = trim($this->ReadPropertyString('XmltvUrl'));
        if ($url === '') {
            $this->SetValue('Bezug', 'keine Quelle eingetragen');
            return json_encode(['ok' => false, 'grund' => 'keine URL']);
        }
        $b = new XmltvBezug($url, $this->pfad('XmltvZiel'), 180);
        $e = $b->hole();
        $this->SetValue('Bezug', sprintf('%s · %s · %.1f MB · %.1f s',
            date('d.m. H:i'), $e['meldung'], $e['groesse'] / 1048576, $e['dauerMs'] / 1000));
        return json_encode($e, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sucht mehrfach vorhandene Aufnahmen. Loescht NUR, wenn scharf geschaltet
     * ist - sonst bleibt es beim Vorschlag in der Variablen.
     *
     * Die Trennung ist Absicht: eine Loeschliste laesst sich vorher lesen, und
     * ein Fehlgriff kostet hier eine Aufnahme, die es nicht mehr gibt.
     */
    public function PruefeDuplikate(): string
    {
        // Rechnen und anzeigen ist der Normalfall - Loeschen die Ausnahme.
        $e = $this->aktualisiereDuplikatliste();
        $d = new Duplikate($this->bestandsdatei());

        $scharf = $this->ReadPropertyBoolean('LoeschenScharf');
        $ergebnis = null;
        if ($scharf && $e['ueberfluessig'] > 0 && ($e['verlaesslich'] ?? true)) {
            $weg = [];
            foreach ($e['gruppen'] as $g) {
                // Filmgruppen sind ueber den Titel gebildet und koennen zwei
                // verschiedene Filme gleichen Namens enthalten. Sie stehen in der
                // Liste zum Nachsehen, werden aber nie von selbst geloescht.
                if (!empty($g['unsicher'])) {
                    continue;
                }
                foreach ($g['loeschen'] as $w) {
                    $weg[] = $w;
                }
            }
            $ergebnis = $d->loesche($weg);
            foreach ($weg as $x) {
                $this->entferneAusBestand((string) $x['pfad']);
            }
            // Bestand gepflegt und Liste neu gerechnet: die geloeschten Zeilen
            // sind damit sofort weg, nicht erst nach dem naechsten Scan.
            $this->aktualisiereDuplikatliste();
        }

        // Unerreichbare Gruppen zuerst melden: sie sind der Hinweis auf eine
        // fehlende Freigabe, und der ist wichtiger als jede Vorschlagszahl.
        $warnung = ((int) ($e['unerreichbar'] ?? 0) > 0)
            ? sprintf(' · ACHTUNG: %d Gruppen nicht lesbar - Freigabe eingebunden?', $e['unerreichbar'])
            : '';
        $this->SetValue('Duplikate', sprintf('%s · %d Gruppen, %d ueberfluessig (%s)%s%s',
            date('d.m. H:i'), $e['gruppen_anzahl'], $e['ueberfluessig'], Duplikate::mb($e['bytes']),
            $ergebnis === null
                ? ' · nur Vorschlag'
                : sprintf(' · %d geloescht, %d nicht gefunden', $ergebnis['geloescht'], $ergebnis['fehlend']),
            $warnung));

        return json_encode(['ok' => true] + $e + ['geloescht' => $ergebnis], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Rechnet die Duplikatliste und schreibt sie in die Anzeigevariable.
     * Loescht NICHTS - genau deshalb darf sie auch nach einer Einzelloeschung
     * gerufen werden, ohne dass ein scharf geschaltetes Modul gleich alles
     * andere mitnimmt.
     *
     * @return array<string,mixed> Ergebnis von Duplikate::finde()
     */
    private function aktualisiereDuplikatliste(): array
    {
        $d = new Duplikate($this->bestandsdatei());
        $e = $d->finde();
        // _Pfad ist der Loeschkandidat, _Bleibt die Kopie, die bleiben soll. Beide
        // versteckt: die Tabelle zeigt Dateinamen, der Knopf braucht Pfade - und
        // die Pruefung beim Klick braucht den Beleg, dass die Kopie noch da ist.
        $zeilen = [['Serie', 'Folge', 'Titel', 'Behalten', 'Groesse', 'Loeschen', 'Frei', '_Pfad', '_Bleibt']];
        foreach ($e['gruppen'] as $g) {
            foreach ($g['loeschen'] as $w) {
                $zeilen[] = [
                    (string) $g['serie'] . (!empty($g['unsicher']) ? ' · pruefen' : ''),
                    strtoupper((string) $g['nummer']), (string) $g['titel'],
                    self::kurzerName((string) $g['behalten']['pfad'], (string) $g['serie']),
                    Duplikate::mb((int) $g['behalten']['groesse']),
                    self::kurzerName((string) $w['pfad'], (string) $g['serie']),
                    Duplikate::mb((int) $w['groesse']),
                    (string) $w['pfad'],
                    (string) $g['behalten']['pfad'],
                ];
            }
        }
        $this->SetValue('DuplikateListe', json_encode($zeilen, JSON_UNESCAPED_UNICODE));
        return $e;
    }

    /**
     * Streicht eine Aufnahme aus der eigenen Bestandsliste.
     *
     * Ohne das haengt die geloeschte Datei bis zum naechsten Scan ueberall
     * nach: in der Duplikatliste als Vorschlag, in der Zuordnung als
     * 'vorhanden'. Der Scan laeuft stuendlich - so lange soll niemand eine
     * Aufnahme angeboten bekommen, die es nicht mehr gibt.
     */
    private function entferneAusBestand(string $pfad): bool
    {
        $datei = $this->bestandsdatei();
        $fh = @fopen($datei, 'r');
        if ($fh === false) {
            return false;
        }
        $temp = $datei . '.teil';
        $out = @fopen($temp, 'w');
        if ($out === false) {
            fclose($fh);
            return false;
        }
        $weg = false;
        while (($z = fgets($fh)) !== false) {
            $roh = $z;
            if (!mb_check_encoding($z, 'UTF-8')) {
                $z = mb_convert_encoding($z, 'UTF-8', 'ISO-8859-1');
            }
            $p = trim((string) substr($z, (int) strrpos($z, '|') + 1));
            if ($p === $pfad) {
                $weg = true;
                continue;
            }
            fwrite($out, $roh);
        }
        fclose($fh);
        fclose($out);
        if (!$weg) {
            @unlink($temp);
            return false;
        }
        return @rename($temp, $datei);
    }

    /**
     * Loescht EINE Aufnahme - die aus der Duplikatliste.
     *
     * Der Pfad kommt aus dem Formular und wird nicht geglaubt: vor dem Loeschen
     * rechnet das Modul die Vorschlagsliste NEU und prueft, ob die Datei dort
     * als ueberfluessig steht. Ein Formular kann Stunden offen sein, in der Zeit
     * kann der Bestand ein anderer sein - ohne diese Probe wuerde ein alter
     * Knopfdruck eine Aufnahme treffen, die inzwischen die einzige ist.
     *
     * Das Scharf-Gate gilt hier bewusst NICHT: dies ist kein Automatismus,
     * sondern ein einzelner, ausdruecklicher Klick auf eine benannte Datei.
     */
    /**
     * Eine einzelne doppelte Aufnahme loeschen (Knopf in der Zeile).
     *
     * Nur eine Huelle um die Sammelfassung: es gibt genau EINEN Weg, auf dem
     * geloescht wird, und damit genau eine Stelle, an der die Liste geschrieben
     * wird. Sie nimmt auch ein JSON-Array entgegen und bleibt damit gueltig,
     * solange eine Gegenstelle den alten Namen kennt - eine neue Prefix-Funktion
     * steht erst nach einem Neustart des Dienstes zur Verfuegung.
     */
    public function LoescheDatei(string $Pfad): string
    {
        return $this->LoescheDateien($Pfad);
    }

    /**
     * Mehrere doppelte Aufnahmen in EINEM Aufruf loeschen.
     *
     * Der Sammelaufruf ist kein Tempo-Kniff, sondern eine Notwendigkeit. Feuert
     * die Oberflaeche je Haekchen eine eigene Anfrage ab, laufen die Aufrufe
     * nebenlaeufig: jeder liest dieselbe Liste, streicht SEINE Zeile und schreibt
     * die Liste zurueck. Der letzte Schreiber gewinnt, und die uebrigen
     * Streichungen sind verloren - die Dateien sind weg, die Zeilen stehen noch
     * da. Genau so sah es aus, als "die Tabelle sich nicht aktualisiert".
     *
     * Hier wird die Liste einmal gelesen, alles geprueft und geloescht, und am
     * Ende einmal geschrieben.
     *
     * @param string $Pfade JSON-Array der zu loeschenden Pfade
     */
    public function LoescheDateien(string $Pfade): string
    {
        $pfade = json_decode($Pfade, true);
        if (!is_array($pfade)) {
            $pfade = [trim($Pfade)];
        }
        $pfade = array_values(array_unique(array_filter(array_map(
            static fn($p): string => trim((string) $p), $pfade
        ), static fn(string $p): bool => $p !== '')));
        if ($pfade === []) {
            return json_encode(['ok' => false, 'grund' => 'kein Pfad']);
        }

        // Die Liste liegt vor - sie noch einmal komplett durchzurechnen kostete
        // acht Sekunden ueber die Netzwerkfreigabe, und in der Zeit sieht der
        // Anwender nichts passieren. Gebraucht wird ohnehin nur zweierlei: steht
        // die Datei als ueberfluessig in der Liste, und ist die Kopie, die sie
        // ueberfluessig macht, wirklich noch da?
        $liste = json_decode((string) @$this->GetValue('DuplikateListe'), true);
        if (!is_array($liste) || count($liste) < 2) {
            return json_encode(['ok' => false, 'grund' => 'keine Liste vorhanden - bitte einmal pruefen lassen'],
                JSON_UNESCAPED_UNICODE);
        }
        // Pfad -> Zeilennummer, einmal aufgebaut statt je Datei durchsucht.
        $wo = [];
        foreach ($liste as $n => $z) {
            if ($n > 0 && is_array($z) && count($z) >= 9) {
                $wo[(string) $z[7]] = $n;
            }
        }

        $d = new Duplikate($this->bestandsdatei());
        $streichen = [];   // Zeilennummern
        $bleiber   = [];   // zu pruefende Restaufnahmen, ohne Dopplung
        $abgelehnt = [];
        $geloescht = 0;
        $begleiter = 0;
        $bytes     = 0;
        $letzter   = '';

        foreach ($pfade as $pfad) {
            if (!isset($wo[$pfad])) {
                $abgelehnt[] = basename($pfad) . ': steht nicht als ueberfluessig in der Liste';
                continue;
            }
            $index  = $wo[$pfad];
            $bleibt = (string) $liste[$index][8];
            // Zeigen beide Spalten auf DIESELBE Datei, ist die Zeile kaputt und
            // nicht etwa ein Duplikat - ein Klick loeschte hier die einzige Kopie.
            // So sah die Liste aus, nachdem eine entzaehlerte Aufnahme eine
            // veraltete Bestandszeile hinterlassen hatte.
            if ($pfad === $bleibt
                || (($a = realpath($pfad)) !== false && $a === realpath($bleibt))) {
                $abgelehnt[] = basename($pfad) . ': waere dieselbe Datei, die behalten werden soll';
                continue;
            }
            // Die zu behaltende Kopie muss da sein. Fehlt sie - etwa weil die
            // Freigabe abgerissen ist -, waere dies die letzte Aufnahme der Folge.
            if ($bleibt === '' || !is_file($bleibt)) {
                $abgelehnt[] = basename($pfad) . ': die zu behaltende Kopie fehlt';
                continue;
            }
            if (!is_file($pfad)) {
                // Schon weg: Zeile trotzdem streichen, damit sie nicht stehen bleibt.
                $streichen[$index] = $pfad;
                $bleiber[$bleibt]  = true;
                continue;
            }
            // Der ganze Satz geht: Video plus .eit, .nfo, .jpg, -thumb.jpg und die
            // vier .ts.*-Begleiter. Sonst bleiben je Loeschung sieben Waisen liegen.
            $r = $d->loesche([['pfad' => $pfad, 'groesse' => 0]]);
            if ($r['geloescht'] !== 1) {
                $abgelehnt[] = basename($pfad) . ': liess sich nicht loeschen';
                continue;
            }
            $geloescht++;
            $begleiter += (int) $r['begleiter'];
            $bytes     += (int) $r['bytes'];
            $letzter    = $pfad;
            $streichen[$index] = $pfad;
            $bleiber[$bleibt]  = true;
        }

        // Erst jetzt entzaehlern: bei drei Kopien derselben Folge fallen zwei
        // Zeilen an, und solange die zweite noch liegt, waere der Name ohne
        // Zaehler gar nicht frei.
        $umbenannt = [];
        foreach (array_keys($bleiber) as $bleibt) {
            $neu = $this->entzaehlere($bleibt);
            if ($neu !== null) {
                $umbenannt[$bleibt] = $neu;
            }
        }

        // Ein einziger Schreibvorgang auf die Liste - siehe Kopf der Methode.
        if ($streichen !== []) {
            foreach (array_keys($streichen) as $index) {
                unset($liste[$index]);
            }
            $this->SetValue('DuplikateListe', json_encode(array_values($liste), JSON_UNESCAPED_UNICODE));
            foreach ($streichen as $pfad) {
                $this->entferneAusBestand($pfad);
            }
        }

        $meldung = $geloescht === 1 && $abgelehnt === []
            ? sprintf('geloescht: %s (%s, %d Begleitdateien)%s', basename($letzter),
                Duplikate::mb($bytes), $begleiter,
                $umbenannt === [] ? '' : ' · bleibt jetzt ' . basename((string) reset($umbenannt)))
            : sprintf('%d von %d geloescht (%s, %d Begleitdateien)%s%s',
                $geloescht, count($pfade), Duplikate::mb($bytes), $begleiter,
                $umbenannt === [] ? '' : ' · ' . count($umbenannt) . ' entzaehlert',
                $abgelehnt === [] ? '' : ' · ' . count($abgelehnt) . ' abgelehnt');
        $this->SetValue('Duplikate', date('d.m. H:i') . ' · ' . $meldung);

        return json_encode([
            'ok'           => $geloescht > 0 || ($streichen !== [] && $abgelehnt === []),
            'geloescht'    => $geloescht,
            'begleiter'    => $begleiter,
            'freigeworden' => Duplikate::mb($bytes),
            'umbenannt'    => count($umbenannt),
            'abgelehnt'    => $abgelehnt,
            'grund'        => $abgelehnt === [] ? '' : implode(' | ', $abgelehnt),
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Nimmt der verbliebenen Aufnahme den Zaehler des Receivers ab.
     *
     * Bleibt nach dem Aufraeumen "…Alptraumpaar_001.ts" uebrig, waehrend es kein
     * "…Alptraumpaar.ts" mehr gibt, ist der Zaehler nur noch eine Narbe. Er stoert
     * beim Wiedererkennen (Bestand, Mediatheken) und laesst den Ordner unaufgeraeumt
     * aussehen. Umbenannt wird der GANZE Satz oder gar nichts.
     *
     * @return string|null neuer Pfad, oder null wenn nichts zu tun war
     */
    private function entzaehlere(string $pfad): ?string
    {
        if (!is_file($pfad)) {
            return null;
        }
        $ziel = Dateisatz::ohneZaehler($pfad);
        if ($ziel === null) {
            return null;
        }
        $r = Dateisatz::benenneUm($pfad, $ziel);
        if (!$r['ok']) {
            return null;
        }
        // Der Bestand kennt noch den alten Pfad - beide Zeilen ziehen mit.
        $this->ersetzeImBestand($pfad, $r['neu']);
        return $r['neu'];
    }

    /** Tauscht einen Pfad in der Bestandsliste gegen einen neuen. */
    private function ersetzeImBestand(string $alt, string $neu): void
    {
        $datei = $this->bestandsdatei();
        $fh = @fopen($datei, 'r');
        if ($fh === false) {
            return;
        }
        $temp = $datei . '.teil';
        $out = @fopen($temp, 'w');
        if ($out === false) {
            fclose($fh);
            return;
        }
        while (($z = fgets($fh)) !== false) {
            $u = $z;
            if (!mb_check_encoding($u, 'UTF-8')) {
                $u = mb_convert_encoding($u, 'UTF-8', 'ISO-8859-1');
            }
            $p = trim((string) substr($u, (int) strrpos($u, '|') + 1));
            if ($p === $alt) {
                // Spaltenweise umschreiben statt per Textersetzung: der Titel in
                // der vorletzten Spalte ist NICHT der Dateiname, sondern nur
                // dessen hinterer Teil ("CSI Unplugged_001"). Eine Ersetzung des
                // Basisnamens ging daran vorbei und liess den Zaehler im Titel
                // stehen - die Zeile log dann ueber die Datei, auf die sie zeigt.
                $f = explode('|', rtrim($u, "\r\n"));
                if (count($f) >= 2) {
                    $f[count($f) - 1] = $neu;
                    $f[count($f) - 2] = (string) preg_replace('/_\d{3}$/', '', $f[count($f) - 2]);
                    $u = implode('|', $f) . "\n";
                }
                fwrite($out, rtrim($u, "\r\n") . "\n");
                continue;
            }
            if ($p === $neu) {
                // Eine Zeile, die schon auf den Zielnamen zeigt, gehoert zu der
                // Aufnahme, die eben geloescht wurde. Bliebe sie stehen, saehen
                // zwei Zeilen auf dieselbe Datei - und die naechste Pruefung
                // hielte das fuer ein Duplikat.
                continue;
            }
            fwrite($out, $z);
        }
        fclose($fh);
        fclose($out);
        @rename($temp, $datei);
    }

    /**
     * Nimmt eine Zeile aus der Anzeigeliste und die Datei aus dem Bestand.
     * Beides sind reine Textoperationen - kein Dateisystemlauf, keine Wartezeit.
     */
    private function streicheAusListe(int $index, string $pfad): void
    {
        $liste = json_decode((string) @$this->GetValue('DuplikateListe'), true);
        if (is_array($liste) && isset($liste[$index])) {
            unset($liste[$index]);
            $this->SetValue('DuplikateListe', json_encode(array_values($liste), JSON_UNESCAPED_UNICODE));
        }
        $this->entferneAusBestand($pfad);
    }

    /**
     * Versucht, die Netzwerkfreigaben wieder einzuhaengen.
     *
     * Der einzige Punkt, an dem dieses Modul das Betriebssystem anfasst -
     * deshalb abschaltbar und mit festem Befehl ohne jede Eingabe von aussen.
     * Symcon laeuft hier als root; wo das nicht so ist, geht es ueber sudo,
     * das dann passwortlos erlaubt sein muss.
     *
     * @return array{ok:bool,meldung:string}
     */
    /** Kommaliste aus dem Formular in saubere Pfade. */
    private static function pfadliste(string $roh): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $roh))));
    }

    /**
     * Haengt die Aufnahmefreigaben ein, soweit noetig.
     *
     * Anders als das pauschale `mount -a` weiter unten arbeitet das hier
     * gezielt: eingehaengt wird nur, was in der fstab steht und in /proc/mounts
     * FEHLT. Ein Verzeichnis ohne fstab-Eintrag ist ein oertlicher Ordner und
     * wird in Ruhe gelassen - dort waere ein Einhaengeversuch nur eine
     * Fehlermeldung. Aufgerufen wird das VOR dem Scan, denn ein leerer
     * Einhaengepunkt liest sich wie eine geleerte Platte.
     *
     * @param list<string> $pfade
     * @return string Meldung fuer die Statuszeile, leer wenn alles schon hing
     */
    private function stelleFreigabenBereit(array $pfade): string
    {
        if ($pfade === [] || !$this->ReadPropertyBoolean('FreigabeEinbinden')) {
            return '';
        }
        $haengt = self::einhaengepunkte('/proc/mounts');
        $bekannt = self::einhaengepunkte('/etc/fstab');
        $getan = [];
        $offen = [];
        foreach ($pfade as $p) {
            $ziel = rtrim($p, '/');
            if ($ziel === '' || isset($haengt[$ziel])) {
                continue;
            }
            if (!isset($bekannt[$ziel])) {
                // Kein Einhaengepunkt: nur melden, wenn es den Ordner auch nicht gibt.
                if (!is_dir($ziel)) {
                    $offen[] = basename($ziel) . ' (kein Ordner, kein fstab-Eintrag)';
                }
                continue;
            }
            if (!function_exists('shell_exec')) {
                $offen[] = basename($ziel) . ' (shell_exec fehlt)';
                continue;
            }
            // Erst ohne sudo - als root ist das der Normalfall -, bei einem
            // Rechtefehler einmal mit. Dieselbe Reihenfolge wie bei 'mount -a'.
            $aus = trim((string) @shell_exec('mount ' . escapeshellarg($ziel) . ' 2>&1'));
            if (preg_match('/permission denied|not permitted|only root|must be superuser/i', $aus) === 1) {
                $aus = trim((string) @shell_exec('sudo -n mount ' . escapeshellarg($ziel) . ' 2>&1'));
            }
            sleep(1);
            $jetzt = self::einhaengepunkte('/proc/mounts');
            if (isset($jetzt[$ziel])) {
                $getan[] = basename($ziel);
            } else {
                $offen[] = basename($ziel) . ($aus !== '' ? ': ' . mb_substr($aus, 0, 80) : '');
            }
        }
        $m = [];
        if ($getan !== []) {
            $m[] = 'eingehaengt: ' . implode(', ', $getan);
        }
        if ($offen !== []) {
            $m[] = 'nicht eingehaengt: ' . implode(', ', $offen);
        }
        return implode(' · ', $m);
    }

    /**
     * Die Einhaengeziele aus /proc/mounts oder /etc/fstab.
     *
     * Beide Dateien haben dasselbe Spaltenformat und maskieren Leerzeichen im
     * Pfad als \040 - unmaskiert waere "/mnt/Filme Sabina" zwei Felder.
     *
     * @return array<string,true>
     */
    private static function einhaengepunkte(string $datei): array
    {
        $out = [];
        foreach (@file($datei, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $zeile) {
            $zeile = trim((string) $zeile);
            if ($zeile === '' || $zeile[0] === '#') {
                continue;
            }
            $f = preg_split('/\s+/', $zeile) ?: [];
            $ziel = rtrim(stripcslashes((string) ($f[1] ?? '')), '/');
            if ($ziel !== '' && str_starts_with($ziel, '/')) {
                $out[$ziel] = true;
            }
        }
        return $out;
    }

    private function bindeFreigabeEin(): array
    {
        if (!$this->ReadPropertyBoolean('FreigabeEinbinden')) {
            return ['ok' => false, 'meldung' => 'automatisches Einbinden ist abgeschaltet'];
        }
        if (!function_exists('shell_exec')) {
            return ['ok' => false, 'meldung' => 'shell_exec steht nicht zur Verfuegung'];
        }
        // Nicht raten, wer wir sind: 'posix_geteuid' fehlt in dieser PHP-Fassung,
        // und die Antwort waere ohnehin nur die halbe Wahrheit. Stattdessen erst
        // ohne sudo versuchen und nur bei einem Rechtefehler nachsetzen - das
        // funktioniert als root wie als normaler Benutzer mit sudo-Erlaubnis.
        $aus = trim((string) @shell_exec('mount -a 2>&1'));
        $rechte = preg_match('/permission denied|not permitted|only root|must be superuser/i', $aus) === 1;
        if ($rechte) {
            $aus .= ' | sudo: ' . trim((string) @shell_exec('sudo -n mount -a 2>&1'));
        }
        // Der Einhaengevorgang braucht einen Moment, bis das Verzeichnis traegt.
        sleep(2);
        return [
            'ok' => true,
            'meldung' => 'mount -a ausgefuehrt' . ($aus !== '' ? (': ' . mb_substr($aus, 0, 200)) : ''),
        ];
    }

    /**
     * Nimmt den Bestand auf der Platte auf. Rein lesend bis auf die eigene
     * Liste; loescht und benennt nichts um.
     */
    public function ScanneBestand(): string
    {
        $pfade = self::pfadliste($this->ReadPropertyString('Aufnahmepfade'));
        $filme = self::pfadliste($this->ReadPropertyString('Filmpfade'));
        // Erst schauen, ob die Freigaben ueberhaupt haengen. Ein Scan auf einem
        // leeren Einhaengepunkt findet null Dateien und sieht aus wie ein
        // geleerter Bestand - die Ursache steht dann nirgends.
        $bereit = $this->stelleFreigabenBereit(array_merge($pfade, $filme));
        $s = new Bestandsscan($pfade, ['ts', 'mkv', 'mp4'],
            max(1, $this->ReadPropertyInteger('ScanMindestens')), $filme);
        $serienDatei = rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/serien-sr.txt';
        $e = $s->lauf($this->pfad('ScanZiel'), $serienDatei);

        // Zu wenige Funde heisst fast immer: die Freigabe haengt nicht. Einmal
        // einbinden und genau EINMAL nachfassen - laeuft es wieder nicht, bleibt
        // es dabei, statt sich im Kreis zu drehen.
        $nachgefasst = '';
        if (!$e['ok'] && $this->ReadPropertyBoolean('FreigabeEinbinden')) {
            $m = $this->bindeFreigabeEin();
            if ($m['ok']) {
                $s2 = new Bestandsscan($pfade, ['ts', 'mkv', 'mp4'],
                    max(1, $this->ReadPropertyInteger('ScanMindestens')), $filme);
                $e = $s2->lauf($this->pfad('ScanZiel'), $serienDatei);
                $nachgefasst = ' · nach ' . $m['meldung'] . ' erneut versucht';
            } else {
                $nachgefasst = ' · ' . $m['meldung'];
            }
        }
        $this->SetValue('Bestand', sprintf('%s · %s · %d Aufnahmen, %d Serien, %d Filme · %.1f s%s%s',
            date('d.m. H:i'), $e['meldung'], $e['dateien'], $e['serien'], (int) ($e['filme'] ?? 0),
            $e['dauerMs'] / 1000, $bereit === '' ? '' : ' · ' . $bereit, $nachgefasst));
        // Nur bei einem gelungenen Scan schreiben: eine haengende Freigabe meldet
        // null Dateien, und eine Null in einer aufgezeichneten Reihe sieht spaeter
        // aus wie ein geleerter Bestand.
        if (!empty($e['ok'])) {
            $this->SetValue('BestandAufnahmen', (int) $e['dateien']);
            $this->SetValue('BestandSerien', (int) $e['serien']);
        }
        return json_encode($e, JSON_UNESCAPED_UNICODE);
    }

    /** Holt die Wunschliste. Schreibt in die EIGENE Datei. */
    public function HoleWunschliste(): string
    {
        $b = new WunschlisteBezug(
            $this->ReadPropertyString('WunschBenutzer'),
            $this->ReadPropertyString('WunschPasswort'),
            $this->pfad('WunschZiel'),
            rtrim($this->ReadPropertyString('Datenpfad'), '/')
        );
        $e = $b->hole();
        // Und sofort in die eigene Liste eintragen. Ohne diesen Schritt kaeme
        // eine auf der Webseite neu gemerkte Serie nirgends mehr an - die
        // Zuordnung liest ja die Liste, nicht die Datei.
        $k = $this->uebernimmWunschliste();
        $e['neu'] = $k['neu'];
        $e['weg'] = $k['weg'];
        $this->SetValue('Wunschliste', sprintf('%s · %s%s · %.1f s%s',
            date('d.m. H:i'), $e['meldung'],
            $e['anzahl'] > 0 ? (' (' . $e['anzahl'] . ' Serien)') : '',
            $e['dauerMs'] / 1000,
            $k['geaendert'] ? sprintf(' · %+d in der Liste', count($k['neu']) - count($k['weg'])) : ''));
        return json_encode($e, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Traegt die Wunschlisten-Datei in die eigene Serienliste ein.
     *
     * @return array{neu:list<string>,weg:list<string>,geaendert:bool}
     */
    private function uebernimmWunschliste(): array
    {
        $k = Serienliste::konsolidiere($this->aufnahmeliste(), $this->favoritenAusDatei());
        if ($k['geaendert']) {
            $this->serienlisteSchreiben($k['tabelle']);
        }
        return ['neu' => $k['neu'], 'weg' => $k['weg'], 'geaendert' => $k['geaendert']];
    }

    /**
     * Nimmt eine Serie in die Aufnahme - der Weg aus dem Programmfuehrer.
     *
     * Bewusst ueber den Namen und nicht ueber eine Kennung: was aus dem EPG
     * kommt, ist ein Titel, und derselbe Titel ist auch das, woran die
     * Zuordnung spaeter arbeitet.
     */
    public function SerieHinzufuegen(string $Serie): string
    {
        $e = Serienliste::eintragen($this->aufnahmeliste(), $Serie);
        $this->serienlisteSchreiben($e['tabelle']);
        return json_encode(['ok' => $e['zustand'] !== 'leer', 'serie' => trim($Serie),
                            'zustand' => $e['zustand'], 'anzahl' => count($e['tabelle'])],
                           JSON_UNESCAPED_UNICODE);
    }

    /** Nimmt eine Serie aus der Aufnahme. Wunschlisten-Zeilen werden nur ausgeschaltet. */
    public function SerieEntfernen(string $Serie): string
    {
        $e = Serienliste::austragen($this->aufnahmeliste(), $Serie);
        $this->serienlisteSchreiben($e['tabelle']);
        return json_encode(['ok' => true, 'serie' => trim($Serie),
                            'zustand' => $e['zustand'], 'anzahl' => count($e['tabelle'])],
                           JSON_UNESCAPED_UNICODE);
    }

    /**
     * Eine Kennung bei TheTVDB oder TMDB setzen - von aussen, ohne Formular.
     *
     * Dafuer da, dass die Zuordnung dort passieren kann, wo die Luecke auffaellt:
     * in der Serienuebersicht. Wer erst die Instanz oeffnen, die Tabelle finden
     * und den Namen abtippen muss, macht es nicht.
     *
     * @param string $Feld 'tvdb' oder 'tmdb'
     * @param int    $Id   die Nummer aus der Adresse der Serienseite; 0 loescht
     *                     den Eintrag wieder
     */
    public function KennungSetzen(string $Serie, string $Feld, int $Id): string
    {
        $Serie = trim($Serie);
        $Feld  = strtolower(trim($Feld));
        if ($Serie === '' || ($Feld !== 'tvdb' && $Feld !== 'tmdb')) {
            return json_encode(['ok' => false, 'fehler' => 'Serie und Feld (tvdb|tmdb) noetig']);
        }
        if ($Id < 0) {
            return json_encode(['ok' => false, 'fehler' => 'Nummer darf nicht negativ sein']);
        }
        // Die Serie muss auf der Liste stehen. Sonst legt ein Tippfehler eine
        // Zeile an, die nie wieder auffaellt.
        $bekannt = false;
        foreach ($this->favoriten() as $n) {
            if (Bestand::form($n) === Bestand::form($Serie)) {
                $Serie = $n;              // Schreibweise der Liste gewinnt
                $bekannt = true;
                break;
            }
        }
        if (!$bekannt) {
            return json_encode(['ok' => false, 'fehler' => 'Serie steht nicht auf der Aufnahmeliste'],
                               JSON_UNESCAPED_UNICODE);
        }

        $tab = Katalogzuordnung::ausJson($this->ReadPropertyString('Katalogtabelle'));
        $treffer = false;
        foreach ($tab as $i => $z) {
            if (Bestand::form($z['serie']) !== Bestand::form($Serie)) {
                continue;
            }
            $tab[$i][$Feld] = $Id;
            $treffer = true;
            break;
        }
        if (!$treffer) {
            $tab[] = ['serie' => $Serie, 'tvdb' => $Feld === 'tvdb' ? $Id : 0, 'tmdb' => $Feld === 'tmdb' ? $Id : 0];
        }
        // Zeilen ohne jede Kennung wieder wegraeumen.
        $tab = array_values(array_filter($tab, static fn(array $z): bool => $z['tvdb'] > 0 || $z['tmdb'] > 0));

        IPS_SetProperty($this->InstanceID, 'Katalogtabelle',
            (string) json_encode(array_values($tab), JSON_UNESCAPED_UNICODE));
        IPS_ApplyChanges($this->InstanceID);   // schreibt die Ablagen gleich mit

        // Die Uebersicht soll sofort stimmen - sie ist ja die Stelle, an der man
        // gerade steht. ABER nur, wenn dieser Prozess den letzten Lauf noch
        // kennt: sonst entstuende eine Tabelle ohne Ausstrahlungen, in der jede
        // Serie "ruht" - und die stuende dann bis zum naechsten Lauf da.
        $sendungen = $this->letzteSendungen();
        if ($sendungen === []) {
            // Ein Aufruf von aussen kennt den letzten Lauf nicht - der lebt nur
            // im Prozess, der ihn gerechnet hat. Die Zaehlungen stehen aber
            // ohnehin in der Ausstrahlungstabelle; von dort genuegt, was die
            // Uebersicht braucht. Ohne das saehe man seine Eintragung erst beim
            // naechsten Lauf, und das haelt niemand fuer "hat geklappt".
            $sendungen = $this->sendungenAusTabelle();
        }
        if ($sendungen !== []) {
            $this->SetValue('Serien', $this->serientabelle($sendungen));
        }

        return json_encode(['ok' => true, 'serie' => $Serie, 'feld' => $Feld, 'id' => $Id,
                            'anzahl' => count($tab)], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Die Liste, wie sie gerade gilt - fuer den Programmfuehrer und fuer jeden,
     * der wissen will, was aufgenommen wird.
     */
    public function Serienliste(): string
    {
        $t = $this->aufnahmeliste();
        return json_encode([
            'ok'     => true,
            'anzahl' => count($t),
            'aktiv'  => count(Serienliste::namen($t)),
            'serien' => $t,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Diagnose: welche Nummer kennt der Episoden-Cache zu dieser Folge? */
    public function KatalogProbe(string $Serie, string $Episodentitel): string
    {
        $k = new Episodenkatalog($this->ReadPropertyString('Datenpfad'));
        $t = $k->finde($Serie, $Episodentitel);
        return json_encode([
            'serien'   => $k->serien(),
            'episoden' => $k->episoden(),
            'treffer'  => $t,
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Diagnose: greift fuer diese Folge eine Serien-Schranke? */
    public function RegelProbe(string $Serie, int $Staffel, int $Folge): string
    {
        return json_encode($this->bedingungen()->pruefe($Serie, $Staffel, $Folge), JSON_UNESCAPED_UNICODE);
    }

    /** Diagnose: auf welchem Empfangskanal landet dieser XMLTV-Sender? */
    public function KanalProbe(string $Sender): string
    {
        $m = new KanalMapper($this->empfangbar(), $this->kanaltabelle());
        return json_encode($m->finde($Sender) ?? ['kanal' => null], JSON_UNESCAPED_UNICODE);
    }

    // ==================================================================
    // Formular
    // ==================================================================

    public function GetConfigurationForm(): string
    {
        $fehlt = $this->fehlendeDateien();
        $hinweis = $fehlt === []
            ? 'Alle Quelldateien gefunden.'
            : 'FEHLT: ' . implode(', ', $fehlt);

        // Die beiden Pfade beschreiben DIESELBE Ablage - einmal wie der Receiver
        // sie sieht, einmal wie Symcon sie sieht. Ob das Paar zusammenpasst,
        // sieht man nicht am Text, sondern nur daran, ob der Ordner da ist.
        // Deshalb steht es hier ausgerechnet statt nur beschrieben.
        $basisR = rtrim(trim($this->ReadPropertyString('ReceiverAufnahmepfad')), '/');
        $basisL = rtrim(trim($this->ReadPropertyString('AufnahmepfadLokal')), '/');
        $pfadText = $basisR === ''
            ? 'Basis leer - der Timer bekommt kein Verzeichnis, die Box nimmt ihre Vorgabe.'
            : sprintf('So sieht es der Timer: %s/Tatort/Season 2/ — und so Symcon: %s/Tatort/Season 2 (%s). '
                    . 'Passt das nicht zusammen, zeigen die beiden Felder auf verschiedene Ablagen.',
                $basisR, $basisL === '' ? '(nicht gesetzt)' : $basisL,
                ($basisL !== '' && is_dir($basisL)) ? 'Ablage gefunden' : 'ABLAGE NICHT GEFUNDEN');

        $liste = $this->aufnahmeliste();
        $ausWl = count(array_filter($liste, static fn(array $z): bool => $z['quelle'] === 'wunschliste'));
        $listeText = $liste === []
            ? 'Noch leer - bis zum ersten Abgleich gelten die Namen direkt aus der Wunschlisten-Datei. '
              . 'Der Knopf "Wunschliste jetzt holen" traegt sie ein.'
            : sprintf('%d Serien, davon %d aktiv · %d von der Wunschliste, %d eigene.',
                count($liste), count(Serienliste::namen($liste)), $ausWl, count($liste) - $ausWl);

        return json_encode([
            'elements' => [
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Aktiv'],
                ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf (programmiert Aufnahmen am Receiver)'],
                ['type' => 'CheckBox', 'name' => 'LoeschenScharf', 'caption' => 'Duplikate wirklich loeschen (eigenes Gate - sonst nur Vorschlag)'],
                ['type' => 'SelectInstance', 'name' => 'ErInstanz', 'caption' => 'Receiver-Instanz (EnigmaReceiver)'],
                ['type' => 'Label', 'caption' => 'Vor- und Nachlauf kommen aus DIESER Receiver-Instanz - je Box eigene Werte. Der Serienrecorder schickt die rohen Sendezeiten.'],
                ['type' => 'NumberSpinner', 'name' => 'IntervallProgramm', 'caption' => 'Programmieren alle ... Minuten (0 = aus)', 'minimum' => 0, 'maximum' => 1440],
                ['type' => 'Label', 'caption' => '— Zeitsteuerung: 0 schaltet die jeweilige Aufgabe ab —'],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Zuordnen und bewerten (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'NumberSpinner', 'name' => 'IntervallBezug', 'caption' => 'Programmvorschau holen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvUrl', 'caption' => 'Quelle der Programmvorschau (URL)'],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvZiel', 'caption' => 'Zieldatei (eigene, nicht die des Altsystems)'],
                ['type' => 'NumberSpinner', 'name' => 'IntervallWunsch', 'caption' => 'Wunschliste holen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'NumberSpinner', 'name' => 'IntervallScan', 'caption' => 'Bestand scannen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'NumberSpinner', 'name' => 'IntervallDuplikate', 'caption' => 'Duplikate pruefen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'ValidationTextBox', 'name' => 'Aufnahmepfade', 'caption' => 'Serienverzeichnisse (mit Komma trennen)'],
                ['type' => 'ValidationTextBox', 'name' => 'Filmpfade', 'caption' => 'Filmverzeichnisse, flach (mit Komma trennen)'],
                ['type' => 'Label', 'caption' => 'Serienverzeichnisse haben je Serie einen Ordner. Filmverzeichnisse sind flach: eine Datei je Aufnahme, '
                    . 'der Titel steht im Namen. Von dort gibt es keine Aufnahmeentscheidung - die Filme werden im Programm nur als vorhanden markiert '
                    . 'und in der Duplikatliste vorgeschlagen. Nicht eingehaengte Freigaben werden vor dem Scan eingehaengt, sofern sie in der fstab stehen.'],
                ['type' => 'ValidationTextBox', 'name' => 'ScanZiel', 'caption' => 'Bestandsliste (eigene)'],
                ['type' => 'NumberSpinner', 'name' => 'ScanMindestens', 'caption' => 'Weniger Funde = Scan gilt als fehlgeschlagen', 'minimum' => 1, 'maximum' => 100000],
                ['type' => 'CheckBox', 'name' => 'FreigabeEinbinden', 'caption' => 'Bei zu wenigen Funden "mount -a" versuchen und einmal nachfassen'],
                ['type' => 'Label', 'caption' => '— Receiver: wird in diesem Stand NUR gelesen (programmierte Aufnahmen) —'],
                ['type' => 'ValidationTextBox', 'name' => 'ReceiverIp', 'caption' => 'Adresse'],
                ['type' => 'ValidationTextBox', 'name' => 'ReceiverBouquet', 'caption' => 'Bouquet (optional)'],
                ['type' => 'NumberSpinner', 'name' => 'ReceiverTuner', 'caption' => 'Verfuegbare Tuner', 'minimum' => 1, 'maximum' => 64],
                ['type' => 'ValidationTextBox', 'name' => 'ReceiverAufnahmepfad', 'caption' => 'Aufnahmepfad auf dem Receiver (Basis)'],
                ['type' => 'ValidationTextBox', 'name' => 'AufnahmepfadLokal', 'caption' => 'Dieselbe Ablage aus Sicht von Symcon'],
                ['type' => 'Label', 'caption' => $pfadText],
                ['type' => 'Label', 'caption' => 'Der Timer bekommt "<Basis>/<Serie>/Season <n>/" - so lagen die Aufnahmen schon unter dem Altsystem, '
                    . 'und so sucht sie der Bestandsscan. Im zweiten Pfad wird der Ordner vorher angelegt: Enigma legt ein unbekanntes '
                    . 'Aufnahmeverzeichnis nicht selbst an. Basis leer = Vorgabe der Box.'],
                // Vor- und Nachlauf stehen bewusst NICHT hier. Sie gehoeren dem
                // Receiver: jede Box hat eigene Anlauf- und Umschaltzeiten, und
                // ER_PlaneAufnahme rechnet sie aus ihrer Instanz dazu. Zwei
                // Stellen fuer denselben Wert hiessen frueher oder spaeter, dass
                // er zweimal draufkommt - im Altsystem waren die beiden Felder
                // ueberdies vertauscht (preRecord bekam die Nachlaufvariable).
                ['type' => 'Label', 'caption' => 'Vor- und Nachlauf: siehe Receiver-Instanz oben - je Box eigene Werte.'],
                ['type' => 'ExpansionPanel', 'caption' => 'Zugang zur Wunschliste', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'WunschBenutzer', 'caption' => 'Benutzer'],
                    ['type' => 'PasswordTextBox', 'name' => 'WunschPasswort', 'caption' => 'Passwort'],
                    ['type' => 'ValidationTextBox', 'name' => 'WunschZiel', 'caption' => 'Zieldatei'],
                ]],
                ['type' => 'NumberSpinner', 'name' => 'Vorschau', 'caption' => 'Vorschau (Tage)', 'minimum' => 1, 'maximum' => 28],
                ['type' => 'CheckBox', 'name' => 'Katalog', 'caption' => 'Fehlende Staffel/Folge im Episoden-Cache nachschlagen (kein Netzzugriff)'],
                ['type' => 'ExpansionPanel', 'caption' => 'TMDB befragen, wenn der Cache nichts weiss', 'items' => [
                    ['type' => 'Label', 'caption' => 'Erste Wahl fuer unbekannte Serien: TMDB hat eine funktionierende Suche und liefert deutsche Titel.'],
                    ['type' => 'CheckBox', 'name' => 'TmdbNetz', 'caption' => 'Netzzugriff erlauben'],
                    ['type' => 'PasswordTextBox', 'name' => 'TmdbApiKey', 'caption' => 'API-Schluessel'],
                    ['type' => 'NumberSpinner', 'name' => 'TmdbDeckel', 'caption' => 'Hoechstens Abfragen je Lauf', 'minimum' => 1, 'maximum' => 500],
                    ['type' => 'NumberSpinner', 'name' => 'TmdbCacheStunden', 'caption' => 'Antworten gelten (Stunden)', 'minimum' => 1, 'maximum' => 8760],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'TheTVDB befragen (findet nur bereits bekannte Serien)', 'items' => [
                    ['type' => 'Label', 'caption' => 'Die Seriensuche von TheTVDB v3 ist abgeschaltet (404). Serien, deren ID noch nicht in der Ablage steht, findet diese Quelle nicht mehr.'],
                    ['type' => 'Label', 'caption' => 'Nur fuer Serien, die noch nicht in der Ablage stehen. Der Abruf wartet 500 ms zwischen zwei Anfragen und bis zu 30 s auf Antwort - deshalb der Deckel je Lauf.'],
                    ['type' => 'CheckBox', 'name' => 'TvdbNetz', 'caption' => 'Netzzugriff erlauben'],
                    ['type' => 'PasswordTextBox', 'name' => 'TvdbApiKey', 'caption' => 'API-Schluessel'],
                    ['type' => 'NumberSpinner', 'name' => 'TvdbDeckel', 'caption' => 'Hoechstens Abfragen je Lauf', 'minimum' => 1, 'maximum' => 500],
                    ['type' => 'NumberSpinner', 'name' => 'TvdbCacheStunden', 'caption' => 'Antworten gelten (Stunden)', 'minimum' => 1, 'maximum' => 8760],
                ]],
                ['type' => 'Label', 'caption' => '— Serien, die aufgenommen werden —'],
                ['type' => 'Label', 'caption' => $listeText],
                ['type' => 'List', 'name' => 'Serienliste', 'caption' => 'Herkunft "wunschliste" wird beim Abgleich gepflegt, "eigen" nie angefasst',
                 'rowCount' => 12, 'add' => ['serie' => '', 'quelle' => 'eigen', 'aktiv' => true],
                 'delete' => true, 'sort' => ['column' => 'serie', 'direction' => 'ascending'],
                 'columns' => [
                    ['caption' => 'Serie', 'name' => 'serie', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Herkunft', 'name' => 'quelle', 'width' => '150px', 'add' => 'eigen',
                     'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'eigen', 'value' => 'eigen'],
                        ['caption' => 'Wunschliste', 'value' => 'wunschliste'],
                     ]]],
                    ['caption' => 'Aufnehmen', 'name' => 'aktiv', 'width' => '110px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                 ]],
                ['type' => 'Label', 'caption' => 'Eine Zeile auf "aus" nimmt die Serie aus der Aufnahme, ohne sie auf wunschliste.de zu entfernen. '
                    . 'Wer eine Wunschlisten-Zeile auf "eigen" stellt, behaelt sie auch dann, wenn sie dort verschwindet.'],
                ['type' => 'Label', 'caption' => '— Quelldateien —'],
                ['type' => 'ValidationTextBox', 'name' => 'Datenpfad', 'caption' => 'Verzeichnis'],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvDatei', 'caption' => 'XMLTV'],
                ['type' => 'ValidationTextBox', 'name' => 'FavoritenDatei', 'caption' => 'Wunschliste'],
                ['type' => 'ValidationTextBox', 'name' => 'KanaeleDatei', 'caption' => 'Empfangbare Kanaele'],
                ['type' => 'ValidationTextBox', 'name' => 'BestandDatei', 'caption' => 'Aufnahmen auf der Platte'],
                ['type' => 'Label', 'caption' => $hinweis],
                ['type' => 'Label', 'caption' => '— Sender: XMLTV-Name auf Empfangskanal —'],
                ['type' => 'List', 'name' => 'Kanaltabelle', 'caption' => 'Nur Ausnahmen; gleiche Namen finden sich von selbst',
                 'add' => true, 'delete' => true, 'columns' => [
                    ['caption' => 'XMLTV-Sender', 'name' => 'xmltv', 'width' => '260px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Empfangskanal', 'name' => 'kanal', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                 ]],
                ['type' => 'Label', 'caption' => '— Titel: Schreibweise auf Favorit, und wie der Ordner heisst —'],
                ['type' => 'List', 'name' => 'Titeltabelle', 'caption' => 'Ablagename leer = wie der Favorit',
                 'add' => true, 'delete' => true, 'columns' => [
                    ['caption' => 'XMLTV-Titel', 'name' => 'titel', 'width' => '300px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Favorit', 'name' => 'favorit', 'width' => '240px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Ablagename', 'name' => 'ablage', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                 ]],
                ['type' => 'Label', 'caption' => '— Schranken je Serie: mehrere Zeilen zur selben Serie gelten alle zugleich —'],
                ['type' => 'List', 'name' => 'Bedingungen', 'caption' => 'Ohne Eintrag gilt keine Schranke',
                 'add' => true, 'delete' => true, 'columns' => [
                    ['caption' => 'Serie', 'name' => 'serie', 'width' => '240px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'Feld', 'name' => 'feld', 'width' => '140px', 'add' => 'season',
                     'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'Staffel', 'value' => 'season'],
                        ['caption' => 'Folge', 'value' => 'episode'],
                     ]]],
                    ['caption' => 'Vergleich', 'name' => 'op', 'width' => '110px', 'add' => '>=',
                     'edit' => ['type' => 'Select', 'options' => [
                        ['caption' => 'ist mindestens (>=)', 'value' => '>='],
                        ['caption' => 'ist groesser (>)', 'value' => '>'],
                        ['caption' => 'ist hoechstens (<=)', 'value' => '<='],
                        ['caption' => 'ist kleiner (<)', 'value' => '<'],
                        ['caption' => 'ist gleich (==)', 'value' => '=='],
                        ['caption' => 'ist ungleich (!=)', 'value' => '!='],
                     ]]],
                    ['caption' => 'Wert', 'name' => 'wert', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'NumberSpinner']],
                 ]],
                ['type' => 'Label', 'caption' => '— Kennungen von Hand: nur noetig, wo die Datenbanken die Serie nicht selbst finden —'],
                ['type' => 'List', 'name' => 'Katalogtabelle',
                 'caption' => 'Die Nummer steht in der Adresse der Serienseite. 0 = nichts eintragen.',
                 'add' => true, 'delete' => true, 'columns' => [
                    ['caption' => 'Serie', 'name' => 'serie', 'width' => '300px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'TheTVDB-ID', 'name' => 'tvdb', 'width' => '140px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 99999999]],
                    ['caption' => 'TMDB-ID', 'name' => 'tmdb', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 99999999]],
                 ]],
                ['type' => 'Label', 'caption' => 'Die Eintragung wirkt sofort: sie wird dorthin geschrieben, wo die Anreicherung ihre '
                    . 'Suchergebnisse erwartet - die Suche wird damit uebersprungen. Das ist bei TheTVDB der einzige Weg, denn dessen '
                    . 'Seriensuche antwortet seit einiger Zeit gar nicht mehr.'],
                ['type' => 'Label', 'caption' => '— Staffel berichtigen: was das EPG nicht kennt, landet sonst in "Season 0" —'],
                ['type' => 'List', 'name' => 'Staffeltabelle',
                 'caption' => 'von: "0" = nur wenn keine Staffel bekannt ist, "*" = jede Staffel',
                 'add' => true, 'delete' => true, 'columns' => [
                    ['caption' => 'Serie', 'name' => 'serie', 'width' => '300px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                    ['caption' => 'von', 'name' => 'von', 'width' => '120px', 'add' => '0',
                     'edit' => ['type' => 'ValidationTextBox', 'validate' => '^(\\*|\\d{1,4})$']],
                    ['caption' => 'nach', 'name' => 'nach', 'width' => 'auto', 'add' => 1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 1, 'maximum' => 9999]],
                 ]],
            ],
            'actions' => [
                ['type' => 'List', 'name' => 'DuplikateAnsicht', 'caption' => 'Mehrfach vorhandene Aufnahmen',
                 'rowCount' => 12, 'add' => false, 'delete' => false,
                 'columns' => [
                    ['caption' => 'Serie', 'name' => 'serie', 'width' => '210px'],
                    ['caption' => 'Folge', 'name' => 'nummer', 'width' => '80px'],
                    ['caption' => 'Bleibt', 'name' => 'bleibt', 'width' => 'auto'],
                    ['caption' => 'Groesse', 'name' => 'bgroesse', 'width' => '80px'],
                    ['caption' => 'Ueberfluessig', 'name' => 'weg', 'width' => 'auto'],
                    ['caption' => 'Groesse', 'name' => 'wgroesse', 'width' => '80px'],
                    ['caption' => '', 'name' => 'aktion', 'width' => '110px'],
                 ],
                 'values' => $this->duplikatZeilen()],
                ['type' => 'Label', 'caption' => 'Der Knopf loescht genau die Datei der Zeile. Vorher wird geprueft, ob sie in einer frisch gerechneten Liste noch als ueberfluessig steht.'],
                ['type' => 'Button', 'caption' => 'Jetzt lesen (ohne Wirkung)', 'onClick' => 'SR_Analyse($id);'],
                ['type' => 'Button', 'caption' => 'Programmvorschau jetzt holen', 'onClick' => 'echo SR_HoleProgramm($id);'],
                ['type' => 'Button', 'caption' => 'Wunschliste jetzt holen', 'onClick' => 'echo SR_HoleWunschliste($id);'],
                ['type' => 'Button', 'caption' => 'Serienliste anzeigen', 'onClick' => 'echo SR_Serienliste($id);'],
                ['type' => 'Button', 'caption' => 'Bestand jetzt scannen', 'onClick' => 'echo SR_ScanneBestand($id);'],
                ['type' => 'Button', 'caption' => 'Duplikate pruefen (loescht nur wenn scharf)', 'onClick' => 'echo SR_PruefeDuplikate($id);'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ProbeTitel', 'caption' => 'Titel pruefen'],
                    ['type' => 'Button', 'caption' => 'Zuordnen', 'onClick' => 'echo SR_TitelProbe($id, $ProbeTitel);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ProbeSender', 'caption' => 'Sender pruefen'],
                    ['type' => 'Button', 'caption' => 'Zuordnen', 'onClick' => 'echo SR_KanalProbe($id, $ProbeSender);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'KatSerie', 'caption' => 'Serie'],
                    ['type' => 'ValidationTextBox', 'name' => 'KatTitel', 'caption' => 'Episodentitel'],
                    ['type' => 'Button', 'caption' => 'Im Katalog nachschlagen', 'onClick' => 'echo SR_KatalogProbe($id, $KatSerie, $KatTitel);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ProbeSerie', 'caption' => 'Serie'],
                    ['type' => 'NumberSpinner', 'name' => 'ProbeStaffel', 'caption' => 'Staffel'],
                    ['type' => 'NumberSpinner', 'name' => 'ProbeFolge', 'caption' => 'Folge'],
                    ['type' => 'Button', 'caption' => 'Schranke pruefen', 'onClick' => 'echo SR_RegelProbe($id, $ProbeSerie, $ProbeStaffel, $ProbeFolge);'],
                ]],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Bereit'],
                ['code' => 200, 'icon' => 'error', 'caption' => 'Quelldatei fehlt'],
            ],
        ], JSON_UNESCAPED_UNICODE);
    }

    // ==================================================================
    // Intern
    // ==================================================================

    private function baueAnalyse(): Analyse
    {
        $tt = $this->titeltabelle();
        return new Analyse($this->favoriten(), $tt['aliase'], $tt['ablage'], $this->empfangbar(),
            $this->kanaltabelle(), new Bestand($this->bestandsdatei()), $this->bedingungen(),
            $this->episodenquelle(), $this->receiver(), $this->staffelregeln(), $this->planer());
    }

    /**
     * Der TV-Planer von wunschliste.de als zweite Quelle fuer die Folgennummer.
     *
     * Geholt wird hoechstens stuendlich und nur, wenn Zugangsdaten hinterlegt sind;
     * das Zwischenlager liegt neben den uebrigen Arbeitsdateien. Fehlt der Zugang
     * oder scheitert der Abruf, arbeitet alles weiter wie vorher - die Quelle
     * ergaenzt, sie ist keine Voraussetzung.
     */
    private function planer(): ?WunschlistePlaner
    {
        if ($this->planer !== null) {
            return $this->planer;
        }
        $datei = rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/tvplaner.json';
        $benutzer = trim($this->ReadPropertyString('WunschBenutzer'));
        $passwort = $this->ReadPropertyString('WunschPasswort');
        $holer = null;
        if ($benutzer !== '' && $passwort !== '') {
            $holer = function () use ($benutzer, $passwort): string {
                return $this->holeTvPlaner($benutzer, $passwort);
            };
        }
        // Sechs Stunden Standzeit: der Planer reicht 14 Tage voraus und aendert sich
        // selten. Stuendlich zu holen hiesse 24 Anmeldungen am Tag bei einem Anbieter,
        // der uns die Daten freundlicherweise ueberlaesst.
        $this->planer = new WunschlistePlaner($datei, $holer, 21600);
        return $this->planer;
    }

    /**
     * Das HTML des TV-Planers holen - ueber dieselbe angemeldete Sitzung, die auch
     * die Favoritenliste zieht. Eine zweite Anmeldung waere nicht nur unnoetig,
     * sondern unhoeflich gegenueber dem Anbieter.
     */
    private function holeTvPlaner(string $benutzer, string $passwort): string
    {
        if (!class_exists(\SerienRecorderWunschliste::class)) {
            return '';
        }
        $ordner = rtrim($this->ReadPropertyString('Datenpfad'), '/');
        $h = new \SerienRecorderWunschliste([
            'debug' => false, 'debug_level' => 0, 'cache_dir' => $ordner,
            'base_url' => 'https://www.wunschliste.de',
            'username' => $benutzer, 'password' => $passwort, 'timeout' => 30,
        ], new class {
            public function log($n, $e = 1): void {}
            public function shouldRefreshFile($d, $s = 24): bool { return true; }
            public function zeilen(): array { return []; }
        });
        if (!$h->login()) {
            return '';
        }
        // prepareCurl() traegt die Sitzung; sie ist privat, weil sie niemanden
        // ausserhalb der Klasse etwas angeht - hier aber genau das Richtige.
        $r = new \ReflectionClass($h);
        $m = $r->getMethod('prepareCurl');
        $m->setAccessible(true);
        $ch = $m->invoke($h);
        curl_setopt($ch, CURLOPT_URL, WunschlistePlaner::URL);
        $html = (string) curl_exec($ch);
        curl_close($ch);
        return $html;
    }

    /**
     * Der Ablageort einer Aufnahme AUF DEM RECEIVER.
     *
     * Aufgebaut wie im Altsystem: "<Basis>/<Serie>/Season <n>/". Die Serie ist
     * der ABLAGENAME, nicht der Favoritenname - "CSI: Miami" heisst auf der
     * Platte "CSI Miami", und ein Doppelpunkt im Pfad waere dort ohnehin nicht
     * zu gebrauchen. Die Staffel kommt aus der Nummer; ist keine bekannt,
     * schreibt das Altsystem "Season 0", und die vorhandenen Aufnahmen liegen
     * so. Wer hier abweicht, legt eine zweite Ablage neben der bestehenden an.
     *
     * @param array<string,mixed> $s
     */
    private function aufnahmeverzeichnis(array $s): string
    {
        $basis = rtrim(trim($this->ReadPropertyString('ReceiverAufnahmepfad')), '/');
        if ($basis === '') {
            return '';                      // leer = Vorgabe der Box, wie im Receiver-Modul
        }
        $serie = trim((string) ($s['serie'] ?? ''));
        if ($serie === '') {
            return $basis . '/';
        }
        return $basis . '/' . $serie . '/Season ' . $this->staffelnummer($s) . '/';
    }

    /** @param array<string,mixed> $s */
    private function staffelnummer(array $s): int
    {
        return preg_match('/^S(\d+)/i', (string) ($s['staffelFolge'] ?? ''), $m) ? (int) $m[1] : 0;
    }

    /**
     * Denselben Ordner auf der Symcon-Seite anlegen.
     *
     * Enigma legt ein unbekanntes Aufnahmeverzeichnis nicht an - der Timer
     * entsteht, die Aufnahme scheitert. Das Altskript hat den Ordner deshalb vor
     * jedem timeradd erzeugt, und zwar ueber den Einhaengepunkt hier.
     *
     * Angelegt wird NUR unterhalb des eingestellten Pfades und nur, wenn dieser
     * wirklich existiert. Ein vertippter Pfad soll keine Ordner im Nirgendwo
     * erzeugen - und schon gar keine ausserhalb der Ablage.
     *
     * @param array<string,mixed> $s
     */
    private function legeOrdnerAn(array $s): void
    {
        $lokal = rtrim(trim($this->ReadPropertyString('AufnahmepfadLokal')), '/');
        $serie = trim((string) ($s['serie'] ?? ''));
        if ($lokal === '' || $serie === '' || !is_dir($lokal)) {
            return;
        }
        $ziel = $lokal . '/' . $serie . '/Season ' . $this->staffelnummer($s);
        if (is_dir($ziel)) {
            return;
        }
        if (@mkdir($ziel, 0777, true)) {
            $this->LogMessage('Aufnahmeordner angelegt: ' . $ziel, KL_MESSAGE);
        }
    }

    /**
     * Kanalname der Box -> Dateiname des Senderlogos.
     *
     * Die Logos liegen bei Symcon (rund 2.900 Stueck) und werden unter /tile/
     * ausgeliefert. BEWUSST von dort und nicht von der Box: eine Tabelle mit 200
     * Zeilen waeren sonst 200 Abrufe an ein Geraet, das nebenher fernsieht.
     *
     * Der Dateiname ist die Serviceref, Doppelpunkte zu Unterstrichen, nur die
     * ersten zehn Felder. Was dahinter steht (Streaming-Adressen), ergibt keinen
     * Dateinamen.
     *
     * @return array<string,string> Kleinschreibung des Namens => Dateiname
     */
    private function piconKarte(): array
    {
        $er = $this->ReadPropertyInteger('ErInstanz');
        if ($er <= 0 || !function_exists('ER_Sender')) {
            return [];
        }
        $j = json_decode(ER_Sender($er), true);
        if (empty($j['ok'])) {
            return [];
        }
        $ordner = self::PICONORDNER;
        $out = [];
        foreach ((array) ($j['sender'] ?? []) as $x) {
            $name = trim((string) ($x['name'] ?? ''));
            $felder = explode(':', trim((string) ($x['ref'] ?? '')));
            if ($name === '' || count($felder) < 10) {
                continue;
            }
            $datei = strtoupper(implode('_', array_slice($felder, 0, 10)));
            if (preg_match('/^[0-9A-Z_]+$/', $datei) !== 1) {
                continue;
            }
            // Nur was wirklich daliegt. Ein Verweis ins Leere waere ein kaputtes
            // Bild in jeder Zeile - schlechter als der blosse Name.
            if (!is_file($ordner . $datei . '.png')) {
                continue;
            }
            // Unter der Vergleichsform ablegen, nicht unter dem Namen: die
            // Kanaltabelle sagt "ORF 1HD", die Box sagt "ORF1 HD" - ein Zeichen
            // Unterschied, und sechs Sender blieben ohne Logo. Zusaetzlich ohne
            // die Kennung am Ende, damit "ATV2" auch "ATV2 HD" findet; der
            // erste Treffer gewinnt, also die Fassung, die zuerst in der Liste
            // steht.
            $k = self::kanalForm($name);
            $out[$k] = $datei . '.png';
            $kurz = preg_replace('/(uhd|hd|sd)$/', '', $k) ?? $k;
            if ($kurz !== '' && $kurz !== $k && !isset($out[$kurz])) {
                $out[$kurz] = $datei . '.png';
            }
        }
        return $out;
    }

    /**
     * Vergleichsform eines Kanalnamens: alles weg, was nur Schreibweise ist.
     *
     * "ORF 1HD", "ORF1 HD" und "orf1hd" sind derselbe Sender - nur schreibt ihn
     * jede Quelle anders. Punkte gehoeren dazu ("SAT.1"), Bindestriche auch.
     */
    /**
     * Wo die Senderlogos LIEGEN.
     *
     * Nicht unter webfront/user/img/picons - dort sieht es zwar genauso aus, nur
     * bedient die Adresse /tile/ diesen Ordner nicht. Sie zeigt auf das
     * Programmverzeichnis, und ein Bild, das nur im falschen Ordner liegt, wird
     * zur kaputten Grafik in jeder Zeile. Genau so ist es dem Logo von Comedy
     * Central ergangen.
     *
     * ACHTUNG: das ist ein Programmverzeichnis. Eine Symcon-Aktualisierung kann
     * selbst nachgelegte Bilder wieder entfernen.
     */
    private const PICONORDNER = '/usr/share/symcon/tile/picons/';

    private static function kanalForm(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($name), 'UTF-8')) ?? '';
    }

    /**
     * Die Senderspalte einer Tabelle.
     *
     * Gesucht wird ueber den Kanalnamen der BOX, angezeigt wird der Name aus dem
     * Programm - beides steckt im Bild: der eine als Titel, der andere als
     * Alternativtext. Das ist nicht nur Hoeflichkeit gegenueber Vorlesegeraeten,
     * sondern noetig fuer die Suche der Tabelle: sie sucht im Zellentext, und der
     * ist hier das Bild.
     *
     * @param array<string,string> $karte
     */
    private function senderZelle(array $karte, string $kanal, string $anzeige): string
    {
        $k = self::kanalForm($kanal);
        $kurz = preg_replace('/(uhd|hd|sd)$/', '', $k) ?? $k;
        $datei = $karte[$k] ?? ($karte[$kurz] ?? '');
        if ($datei === '') {
            return $anzeige;
        }
        return '<img src="/tile/picons/' . $datei . '"'
            . ' alt="' . htmlspecialchars($anzeige, ENT_QUOTES) . '"'
            . ' title="' . htmlspecialchars($kanal, ENT_QUOTES) . '"'
            . ' style="height:1.6em;vertical-align:middle">';
    }

    private function pfad(string $property): string
    {
        return rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/' . ltrim($this->ReadPropertyString($property), '/');
    }

    /**
     * Was schon auf der Platte liegt, als Nachschlagewerk fuer den Programmfuehrer.
     *
     * Schluessel ist die XMLTV-Kennung des Senders und die Startzeit - dieselbe
     * Kennung, unter der das Raster seine Sender fuehrt. Ueber den Namen zu
     * gehen hiesse, drei Schreibweisen gegeneinander zu normalisieren.
     *
     * "mehrfach" zaehlt dazu: auch das liegt auf der Platte, nur oefter als
     * noetig. Wer die Sendung sucht, will genau das wissen.
     *
     * Zwei Quellen, in dieser Rangfolge: das Urteil des Entscheiders, wo es
     * eines gibt, sonst der direkte Griff in den Bestand aus dem Lauf. Das
     * Urteil kennt Katalog, Staffelregeln und Duplikate und ist deshalb die
     * bessere Auskunft; es gibt es aber nur fuer Wunschserien im
     * Entscheidungsfenster. Der Nachschlag fuellt die Luecken - vergangene
     * Stunden des laufenden Tages und Serien, die nicht (mehr) auf der Liste
     * stehen.
     *
     * @param list<array<string,mixed>> $sendungen
     * @param array<string,int> $nachschlag "kanalId|start" => 1
     */
    private function bestandsmarken(array $sendungen, array $nachschlag = []): string
    {
        $marken = $nachschlag;
        $nummern = [];

        // Was die ERSTE Ausstrahlung einer Folge ueber die Platte gesagt hat.
        //
        // 'mehrfach' ist KEINE Aussage ueber die Platte: der Entscheider vergibt es fuer
        // "laeuft in diesem Zeitraum erneut", und zwar BEVOR er im Bestand nachsieht
        // (Entscheidung.php, Schritt 3 vor Schritt 5). Hier wurde daraus bisher die
        // Marke 2 = "liegt mehrfach auf der Platte". Bei "The Voice of Germany" S16E01
        // hiess das: die Folge ist nirgends aufgenommen - der Bestand endet bei S15 -,
        // lief aber viermal im Zeitraum, und drei davon standen als "liegt mehrfach
        // vor" im Raster (gemeldet 11.09.2026).
        //
        // Ob eine Folge da ist, sagt allein die erste Ausstrahlung. Die Wiederholungen
        // erben dieses Urteil - und die 2 bekommt endlich ihre eigentliche Bedeutung:
        // der Bestand fuehrt MEHR ALS EINE Datei dazu.
        $folgenschluessel = static function (array $s): string {
            return mb_strtolower(trim((string) ($s['serie'] ?? '')) . '|'
                . (string) ($s['staffelFolge'] ?? '') . '|' . trim((string) ($s['titel'] ?? '')));
        };
        $plattenurteil = [];
        foreach ($sendungen as $s) {
            $u = (string) ($s['urteil'] ?? '');
            if ($u !== 'vorhanden' && $u !== 'aufnehmen') {
                continue;
            }
            $sch = $folgenschluessel($s);
            if (!isset($plattenurteil[$sch])) {
                $plattenurteil[$sch] = ['urteil' => $u, 'dateien' => (int) ($s['dateien'] ?? 0)];
            }
        }

        foreach ($sendungen as $s) {
            $k = trim((string) ($s['kanalId'] ?? ''));
            if ($k === '') {
                continue;
            }
            $u = (string) ($s['urteil'] ?? '');
            if ($u === 'mehrfach') {
                // Wiederholung im Zeitraum: das Urteil der ersten Ausstrahlung gilt.
                $pu = $plattenurteil[$folgenschluessel($s)] ?? null;
                if ($pu !== null && $pu['urteil'] === 'vorhanden') {
                    $marken[$k . '|' . (int) $s['start']] = $pu['dateien'] > 1 ? 2 : 1;
                } else {
                    unset($marken[$k . '|' . (int) $s['start']]);
                }
                continue;
            }
            if ($u !== 'vorhanden') {
                // Nur EIN Urteil ist eine Aussage ueber die Platte: "aufnehmen"
                // heisst, der Entscheider hat im Bestand nachgesehen und nichts
                // gefunden. Das zaehlt auch gegen den Nachschlag, der nur Name
                // und Nummer vergleicht.
                //
                // "ausgeschlossen", "unklar" und "programmiert" sagen dagegen gar
                // nichts darueber. Der Tatort von 2019 faellt aus der Schranke
                // "season >= 2024" - er liegt trotzdem auf der Platte, und genau
                // das will man im Raster sehen.
                if ($u === 'aufnehmen') {
                    unset($marken[$k . '|' . (int) $s['start']]);
                }
                continue;
            }
            // 1 = liegt da, 2 = liegt MEHRFACH da (mehr als eine Datei im Bestand).
            // Die 3 der Filmablagen kommt aus dem Nachschlag und wird hier nie
            // gesetzt: was ein Urteil hat, ist eine Serienfolge.
            $marken[$k . '|' . (int) $s['start']] = ((int) ($s['dateien'] ?? 0)) > 1 ? 2 : 1;
        }
        // Nummer, Serienname und Episodentitel gleich mitgeben - alles drei so,
        // wie die Aufnahme auf der Platte heisst.
        //
        // Das EPG kennt diese Namen nicht. Es schreibt "Tatort: Trotzdem" und
        // "Der Wien-Krimi: Blind ermittelt" in EINEN Titel, waehrend die Ablage
        // "Tatort" mit der Folge "Voss - 10 - Trotzdem" und die Serie "Blind
        // ermittelt" fuehrt; "CSI: Miami" liegt unter "CSI Miami". Ermittelt
        // wird das alles ohnehin fuer den Bestandsabgleich - nur wusste bisher
        // niemand ausserhalb davon.
        //
        // Ein Eintrag je Ausstrahlung: [Nummer, Serie, Episodentitel] - und, wo das
        // EPG keine Inhaltsangabe hat, als vierter Platz die von TheTVDB.
        foreach ($sendungen as $s) {
            $k = trim((string) ($s['kanalId'] ?? ''));
            if ($k === '') {
                continue;
            }
            $nr = (string) ($s['staffelFolge'] ?? '');
            $serie = (string) ($s['serie'] ?? '');
            $ep = (string) ($s['titel'] ?? '');
            $inhalt = (string) ($s['inhalt'] ?? '');
            if ($nr === '' && $serie === '' && $ep === '' && $inhalt === '') {
                continue;
            }
            // Der vierte Platz bleibt leer, wo das EPG selbst etwas zu sagen hat -
            // sonst traegt die Variable ein halbes Megabyte Text, das niemand liest.
            $nummern[$k . '|' . (int) $s['start']] = $inhalt === ''
                ? [$nr, $serie, $ep]
                : [$nr, $serie, $ep, $inhalt];
        }
        return (string) json_encode(['stand' => time(), 'anzahl' => count($marken),
                                     'marken' => $marken, 'sendungen' => $nummern],
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Die Handeintraege in die Ablagen schreiben.
     *
     * Bei jedem Speichern UND bei jedem Lauf: der uebernommene Handler haelt
     * eine Ablage nur begrenzte Zeit fuer gueltig (Vorgabe 168 Stunden) und
     * wuerde danach wieder suchen - eine Handeintragung darf aber nicht nach
     * einer Woche verfallen.
     *
     * @return array{tvdb:int,tmdb:int,fehler:list<string>}
     */
    private function schreibeKatalogzuordnung(): array
    {
        return Katalogzuordnung::schreibe(
            rtrim($this->ReadPropertyString('Datenpfad'), '/'),
            Katalogzuordnung::ausJson($this->ReadPropertyString('Katalogtabelle')));
    }

    /** @return list<string> */
    private function fehlendeDateien(): array
    {
        $fehlt = [];
        foreach (['XmltvDatei', 'FavoritenDatei', 'KanaeleDatei', 'BestandDatei'] as $p) {
            if (!is_readable($this->pfad($p))) {
                $fehlt[] = $this->ReadPropertyString($p);
            }
        }
        return $fehlt;
    }

    /** Liest JSON und faengt die BOM ab, die in favorites.xml tatsaechlich drinsteht. */
    private function json(string $datei): array
    {
        $roh = @file_get_contents($datei);
        if ($roh === false) {
            return [];
        }
        $d = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $roh) ?? $roh, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Die Namen, gegen die das Programm zugeordnet wird.
     *
     * Erste Wahl ist die eigene Serienliste. Nur solange sie leer ist - also
     * vor dem ersten Abgleich - kommen die Namen direkt aus der Datei; sonst
     * stuende das Modul nach der Umstellung ohne Serien da.
     *
     * @return list<string>
     */
    private function favoriten(): array
    {
        $namen = Serienliste::namen($this->aufnahmeliste());
        return $namen !== [] ? $namen : $this->favoritenAusDatei();
    }

    /** Die Namen aus der Wunschlisten-Datei: erst die selbst geholte, sonst die des Altsystems.
     *
     * @return list<string>
     */
    private function favoritenAusDatei(): array
    {
        $eigen = $this->pfad('WunschZiel');
        $d = $this->json(is_readable($eigen) ? $eigen : $this->pfad('FavoritenDatei'));
        return array_values(array_filter(array_map(
            static fn(array $f): string => trim((string) ($f['name'] ?? '')),
            $d['favorites'] ?? []
        )));
    }

    /** @return list<array{serie:string,quelle:string,aktiv:bool}> */
    private function aufnahmeliste(): array
    {
        return Serienliste::ausJson($this->ReadPropertyString('Serienliste'));
    }

    /**
     * Schreibt die Tabelle zurueck in die Eigenschaft.
     *
     * Nur wenn sich wirklich etwas geaendert hat: IPS_ApplyChanges laesst die
     * Instanz durch ihren Aufbau laufen, und das nach jedem Wunschlisten-Bezug
     * zu tun, waere Arbeit ohne Anlass.
     *
     * @param list<array{serie:string,quelle:string,aktiv:bool}> $tabelle
     */
    private function serienlisteSchreiben(array $tabelle): void
    {
        $neu = json_encode(array_values($tabelle), JSON_UNESCAPED_UNICODE);
        if ($neu === false || $neu === $this->ReadPropertyString('Serienliste')) {
            return;
        }
        IPS_SetProperty($this->InstanceID, 'Serienliste', $neu);
        IPS_ApplyChanges($this->InstanceID);
    }

    /** @return list<string> */
    private function empfangbar(): array
    {
        $d = $this->json($this->pfad('KanaeleDatei'));
        return array_values(array_filter(array_map(
            static fn(array $c): string => trim((string) ($c['name'] ?? '')),
            $d['channels'] ?? []
        )));
    }

    /** @return array<string,string> */
    private function kanaltabelle(): array
    {
        $out = [];
        foreach (json_decode($this->ReadPropertyString('Kanaltabelle'), true) ?: [] as $z) {
            $von = trim((string) ($z['xmltv'] ?? ''));
            $nach = trim((string) ($z['kanal'] ?? ''));
            if ($von !== '' && $nach !== '') {
                $out[$von] = $nach;
            }
        }
        return $out;
    }

    /**
     * Die Kette: erst der Dateikatalog, dann - wenn freigegeben - TheTVDB.
     * Ohne Freigabe wird die Netzquelle GAR NICHT GEBAUT. Das ist Absicht: die
     * uebernommene Klasse geht auch ueber ihren "Cache"-Einstieg ins Netz, sobald
     * eine Serie dort unbekannt ist. Die Zusicherung darf nicht an einem
     * Methodennamen haengen.
     */
    private function episodenquelle(): ?\Hoep\SeriesRecorder\EpisodenQuelle
    {
        $quellen = [];
        if ($this->ReadPropertyBoolean('Katalog')) {
            $quellen[] = new Episodenkatalog($this->ReadPropertyString('Datenpfad'));
        }
        // TMDB vor TheTVDB: dessen Seriensuche ist abgeschaltet (v3 antwortet auf
        // /search/series mit 404), es findet also nur noch, was im Cache steht.
        if ($this->ReadPropertyBoolean('TmdbNetz') && $this->ReadPropertyString('TmdbApiKey') !== '') {
            $quellen[] = new TmdbQuelle(
                rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/tmdb',
                $this->ReadPropertyString('TmdbApiKey'),
                max(1, $this->ReadPropertyInteger('TmdbDeckel')),
                max(1, $this->ReadPropertyInteger('TmdbCacheStunden'))
            );
        }
        if ($this->ReadPropertyBoolean('TvdbNetz') && $this->ReadPropertyString('TvdbApiKey') !== '') {
            $quellen[] = new TvdbQuelle(
                rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/tvdb',
                $this->ReadPropertyString('TvdbApiKey'),
                max(1, $this->ReadPropertyInteger('TvdbDeckel')),
                max(1, $this->ReadPropertyInteger('TvdbCacheStunden'))
            );
        }
        if ($quellen === []) {
            return null;
        }
        return count($quellen) === 1 ? $quellen[0] : new Quellenkette(...$quellen);
    }

    /**
     * Zeilen fuer die Duplikat-Ansicht im Formular, jede mit eigenem Loeschknopf.
     *
     * @return list<array<string,mixed>>
     */
    private function duplikatZeilen(): array
    {
        $roh = json_decode((string) @$this->GetValue('DuplikateListe'), true);
        if (!is_array($roh) || count($roh) < 2) {
            return [];
        }
        $aus = [];
        foreach (array_slice($roh, 1) as $z) {
            if (!is_array($z) || count($z) < 7) {
                continue;
            }
            // Der Knopf traegt den vollen Pfad; die Tabelle zeigt nur den Dateinamen,
            // sonst ist die Zeile nicht mehr lesbar.
            $pfad = $this->pfadZuDateiname((string) $z[5]);
            $aus[] = [
                'serie' => $z[0], 'nummer' => $z[1],
                'bleibt' => $z[3], 'bgroesse' => $z[4],
                'weg' => $z[5], 'wgroesse' => $z[6],
                'aktion' => [
                    'type' => 'Button', 'caption' => 'Loeschen',
                    'onClick' => 'echo SR_LoescheDatei($id, "' . addslashes($pfad) . '");',
                ],
            ];
        }
        return $aus;
    }

    /**
     * Sucht zu einem Dateinamen den vollen Pfad aus der Bestandsliste.
     * Die Anzeigetabelle fuehrt nur den Namen - der Knopf braucht den Pfad.
     */
    private function pfadZuDateiname(string $name): string
    {
        $fh = @fopen($this->bestandsdatei(), 'r');
        if ($fh === false) {
            return '';
        }
        $treffer = '';
        while (($z = fgets($fh)) !== false) {
            if (!mb_check_encoding($z, 'UTF-8')) {
                $z = mb_convert_encoding($z, 'UTF-8', 'ISO-8859-1');
            }
            $p = trim((string) substr($z, (int) strrpos($z, '|') + 1));
            if ($p !== '' && basename($p) === $name) {
                $treffer = $p;
                break;
            }
        }
        fclose($fh);
        return $treffer;
    }

    /**
     * Dateiname ohne den Teil, der links in der Zeile ohnehin steht.
     *
     * "Bones - S11E17 - Die Suende im Secret Service_001.ts" wird zu
     * "Die Suende im Secret Service_001.ts". Serie und Folge fuehrt die Tabelle
     * in eigenen Spalten; sie im Dateinamen zu wiederholen kostet die halbe
     * Zeilenbreite und verdraengt den Knopf aus dem Bild.
     */
    private static function kurzerName(string $pfad, string $serie): string
    {
        $n = basename($pfad);
        if (preg_match('/^(.*?) - S\d{1,4}E\d{1,4} - (.+)$/', $n, $m)) {
            return $m[2];
        }
        if ($serie !== '' && str_starts_with($n, $serie . ' - ')) {
            return substr($n, strlen($serie) + 3);
        }
        return $n;
    }

    /** Nur wenn eine Adresse eingetragen ist - sonst bleibt der Receiver aussen vor. */
    private function receiver(): ?Receiver
    {
        $ip = trim($this->ReadPropertyString('ReceiverIp'));
        return $ip === '' ? null : new Receiver($ip,
            trim($this->ReadPropertyString('ReceiverBouquet')),
            max(1, $this->ReadPropertyInteger('ReceiverTuner')));
    }

    /** Die selbst aufgenommene Liste hat Vorrang, sonst die des Altsystems. */
    private function bestandsdatei(): string
    {
        $eigen = $this->pfad('ScanZiel');
        return is_readable($eigen) ? $eigen : $this->pfad('BestandDatei');
    }

    private ?WunschlistePlaner $planer = null;

    private function bedingungen(): Bedingungen
    {
        $liste = json_decode($this->ReadPropertyString('Bedingungen'), true) ?: [];
        return new Bedingungen(is_array($liste) ? $liste : []);
    }

    /**
     * Zeilen fuer die Serienuebersicht.
     *
     * @param list<array<string,mixed>>|null $sendungen Ausstrahlungen des letzten
     *        Laufs; ohne sie bleiben die Zaehlspalten leer.
     */
    private function serientabelle(?array $sendungen): string
    {
        $verweise = new Katalogverweise(rtrim($this->ReadPropertyString('Datenpfad'), '/'),
            Katalogzuordnung::karte(Katalogzuordnung::ausJson($this->ReadPropertyString('Katalogtabelle'))));
        $regeln = json_decode($this->ReadPropertyString('Staffeltabelle'), true) ?: [];
        $regelText = [];
        foreach ($regeln as $z) {
            $serie = trim((string) ($z['serie'] ?? ''));
            if ($serie === '') {
                continue;
            }
            $von = trim((string) ($z['von'] ?? ''));
            $regelText[Bestand::form($serie)][] =
                (($von === '' || $von === '*') ? 'jede Staffel' : ('S' . str_pad($von, 2, '0', STR_PAD_LEFT)))
                . ' → S' . str_pad((string) (int) ($z['nach'] ?? 0), 2, '0', STR_PAD_LEFT);
        }

        // Was laeuft, und wie es gezaehlt wird - je Serie zusammengefasst.
        $anzahl = [];
        $ohne = [];
        foreach ((array) $sendungen as $x) {
            $serie = (string) ($x['serie'] ?? '');
            if ($serie === '') {
                continue;
            }
            $anzahl[$serie] = ($anzahl[$serie] ?? 0) + 1;
            $nr = strtoupper(trim((string) ($x['staffelFolge'] ?? '')));
            if ($nr === '' || str_starts_with($nr, 'S00')) {
                $ohne[$serie] = ($ohne[$serie] ?? 0) + 1;
            }
        }

        $link = static function (string $url, string $text): string {
            return $url === '' ? '' : '<a href="' . $url . '" target="_blank" rel="noopener">' . $text . '</a>';
        };

        // Der Zustand ist eine KATEGORIE, keine Zahl - nur so lassen sich daraus
        // Filterpillen bauen ("Staffel offen 3"). Die Zahl steht daneben in ihrer
        // eigenen Spalte; stuende sie im Zustand, waere jede Zeile ein eigener
        // Wert und die Pillenzeile so lang wie die Tabelle.
        $zeilen = [['Serie', 'Ausstrahlungen', 'ohne Staffel', 'Zustand', 'Staffelregel', 'TheTVDB', 'TMDB']];
        $namen = $this->favoriten();
        sort($namen, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($namen as $serie) {
            $v = $verweise->fuer($serie);
            $s = Katalogverweise::suche($serie);
            $k = Bestand::form($serie);
            $lauf = (int) ($anzahl[$serie] ?? 0);
            $luecke = (int) ($ohne[$serie] ?? 0);
            $regel = implode(', ', $regelText[$k] ?? []);
            if ($lauf === 0) {
                $zustand = 'ruht';                 // nichts im Programm - nichts zu tun
            } elseif ($luecke > 0 && $regel === '') {
                $zustand = 'Staffel offen';        // hier fehlt eine Regel
            } elseif ($regel !== '') {
                $zustand = 'Regel aktiv';
            } else {
                $zustand = 'vollständig';
            }
            $zeilen[] = [
                $serie,
                $lauf > 0 ? (string) $lauf : '',
                $luecke > 0 ? (string) $luecke : '',
                $zustand,
                $regel,
                $v['tvdb'] !== '' ? $link($v['tvdb'], $v['tvdbName'] !== '' ? $v['tvdbName'] : 'öffnen') : $link($s['tvdb'], 'nachschlagen'),
                $v['tmdb'] !== '' ? $link($v['tmdb'], $v['tmdbName'] !== '' ? $v['tmdbName'] : 'öffnen') : $link($s['tmdb'], 'nachschlagen'),
            ];
        }
        return (string) json_encode($zeilen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Die Zaehlungen des letzten Laufs aus der Ausstrahlungstabelle zurueckholen.
     *
     * Nur Serie und Nummer - mehr braucht die Uebersicht nicht, und mehr steht
     * dort auch nicht mehr in verwertbarer Form (die Senderspalte traegt
     * inzwischen ein Bild).
     *
     * @return list<array<string,mixed>>
     */
    private function sendungenAusTabelle(): array
    {
        $j = json_decode((string) @$this->GetValue('Ausstrahlungen'), true);
        if (!is_array($j) || count($j) < 2) {
            return [];
        }
        $kopf = array_map('strval', (array) $j[0]);
        $iS = array_search('Serie', $kopf, true);
        $iN = array_search('Folge', $kopf, true);
        if ($iS === false) {
            return [];
        }
        $out = [];
        foreach (array_slice($j, 1) as $r) {
            $out[] = ['serie' => (string) ($r[$iS] ?? ''),
                      'staffelFolge' => $iN === false ? '' : (string) ($r[$iN] ?? '')];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> Sendungen des letzten Laufs in diesem Prozess */
    private function letzteSendungen(): array
    {
        return (array) ($this->letzterLauf['sendungen'] ?? []);
    }

    /**
     * Timername wie im Altsystem: "Serie - S01E02 - Episodentitel".
     *
     * Zeichengenau uebernommen, und das mit Absicht: der Bestandsscan liest die
     * Dateinamen auf der Platte, und die Duplikaterkennung vergleicht sie. Ein
     * anderer Name hiesse, dass jede bereits aufgenommene Folge als fehlend gilt
     * und in der naechsten Runde noch einmal programmiert wird.
     *
     * @param array<string,mixed> $s
     */
    private function timername(array $s): string
    {
        // Auch ohne bekannte Nummer steht SxxExx im Namen - das Altsystem schreibt
        // dann S00E00, und die Aufnahmen auf der Platte tragen es so. Wer die
        // Stelle hier weglaesst, erzeugt zwei Namensformen in derselben Ablage.
        $nummer = strtoupper(trim((string) ($s['staffelFolge'] ?? '')));
        if ($nummer === '') {
            $nummer = 'S00E00';
        }
        $name = (string) $s['serie'] . ' - ' . $nummer;
        $ep = trim((string) ($s['titel'] ?? ''));
        if ($ep !== '' && $ep !== $name) {
            $name .= ' - ' . $ep;
        }
        return $name;
    }

    /**
     * Kennzahlen des letzten Laufs als Tabelle.
     *
     * @param array<string,mixed> $e
     */
    private function kennzahlentabelle(array $e): string
    {
        $k = (array) ($e['kennzahlen'] ?? []);
        $zeilen = [['Kennzahl', 'Wert']];
        $paare = [
            'geprueft'                 => 'Sendungen im Fenster',
            'zugeordnet'               => 'einem Favoriten zugeordnet',
            'Serien mit Ausstrahlung'  => 'Serien mit Ausstrahlung',
            'vorhanden'                => 'liegt bereits vor',
            'mehrfach'                 => 'laeuft mehrfach',
            'programmiert'             => 'am Receiver eingeplant',
            'aufnehmen'                => 'fehlt im Bestand',
            'ausgeschlossen'           => 'durch Schranke verworfen',
            'unklar'                   => 'nicht wiedererkennbar',
            'Sender nicht empfangbar'  => 'Sender fehlt',
            'ohne Favorit'             => 'kein Favorit',
        ];
        foreach ($paare as $feld => $text) {
            if (isset($k[$feld])) {
                $zeilen[] = [$text, (string) $k[$feld]];
            }
        }
        $zeilen[] = ['Rechenzeit', ((int) ($e['dauerMs'] ?? 0)) . ' ms'];
        return (string) json_encode($zeilen, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Betriebsprotokoll: was hat wann zuletzt gearbeitet.
     *
     * Ersetzt das Protokoll des Altsystems, das die Meldungen EINES Laufs
     * auflistete. Hier steht stattdessen der Stand jedes Vorgangs mit seinem
     * Zeitpunkt - das ist die Frage, die man an ein laufendes System hat:
     * laeuft noch alles, und wann war es zuletzt dran.
     */
    private function protokolltabelle(): string
    {
        $zeilen = [['Vorgang', 'zuletzt', 'Ergebnis']];
        $reihe = [
            'Status'         => 'Analyse',
            'Programmierung' => 'Programmierung',
            'Bezug'          => 'Programmvorschau geholt',
            'Wunschliste'    => 'Wunschliste geholt',
            'Bestand'        => 'Bestand gescannt',
            'Duplikate'      => 'Duplikate geprueft',
            'Quellen'        => 'Episodenquellen',
            'OffeneSender'   => 'Sender ohne Empfangskanal',
        ];
        foreach ($reihe as $ident => $text) {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if (!$vid) {
                continue;
            }
            $wert = trim((string) GetValue($vid));
            $v = IPS_GetVariable($vid);
            $zeilen[] = [$text,
                         $v['VariableUpdated'] > 0 ? date('d.m. H:i', $v['VariableUpdated']) : '',
                         $wert === '' ? '-' : mb_substr(str_replace("\n", ' · ', $wert), 0, 160)];
        }
        // Der Receiver gehoert dazu: ohne ihn programmiert niemand.
        $er = $this->ReadPropertyInteger('ErInstanz');
        if ($er > 0 && @IPS_InstanceExists($er)) {
            foreach (['Meldung' => 'Receiver: letzte Handlung', 'TimerAnzahl' => 'Receiver: Timer',
                      'Erreichbar' => 'Receiver: erreichbar'] as $ident => $text) {
                $vid = @IPS_GetObjectIDByIdent($ident, $er);
                if (!$vid) {
                    continue;
                }
                $v = IPS_GetVariable($vid);
                $zeilen[] = [$text, date('d.m. H:i', $v['VariableUpdated']),
                             mb_substr((string) GetValueFormatted($vid), 0, 160)];
            }
        }
        return (string) json_encode($zeilen, JSON_UNESCAPED_UNICODE);
    }

    private function staffelregeln(): Staffelregeln
    {
        $liste = json_decode($this->ReadPropertyString('Staffeltabelle'), true) ?: [];
        return new Staffelregeln(is_array($liste) ? $liste : []);
    }

    /** @return array{aliase:array<string,string>,ablage:array<string,string>} */
    private function titeltabelle(): array
    {
        $aliase = [];
        $ablage = [];
        foreach (json_decode($this->ReadPropertyString('Titeltabelle'), true) ?: [] as $z) {
            $titel = trim((string) ($z['titel'] ?? ''));
            $fav = trim((string) ($z['favorit'] ?? ''));
            $abl = trim((string) ($z['ablage'] ?? ''));
            if ($titel !== '' && $fav !== '') {
                $aliase[$titel] = $fav;
            }
            if ($fav !== '' && $abl !== '') {
                $ablage[$fav] = $abl;
            }
        }
        return ['aliase' => $aliase, 'ablage' => $ablage];
    }
}
