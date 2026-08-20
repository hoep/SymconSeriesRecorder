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

use Hoep\SeriesRecorder\Analyse;
use Hoep\SeriesRecorder\Bedingungen;
use Hoep\SeriesRecorder\Bestand;
use Hoep\SeriesRecorder\Episodenkatalog;
use Hoep\SeriesRecorder\Quellenkette;
use Hoep\SeriesRecorder\TmdbQuelle;
use Hoep\SeriesRecorder\TvdbQuelle;
use Hoep\SeriesRecorder\KanalMapper;
use Hoep\SeriesRecorder\TitelResolver;
use Hoep\SeriesRecorder\WunschlisteBezug;
use Hoep\SeriesRecorder\XmltvBezug;
use Hoep\SeriesRecorder\XmltvLeser;

/**
 * Serienrecorder (Phase 0/1).
 *
 * Das Modul liest in diesem Stand ausschliesslich: es ordnet die Ausstrahlungen
 * des XMLTV der Wunschliste zu und legt das Ergebnis in Variablen ab. Es spricht
 * KEINEN Receiver an, programmiert keine Timer und loescht keine Aufnahme - das
 * bleibt der Skript-Fassung, bis beide Seiten ueber mehrere Tage dasselbe sagen.
 *
 * Die Eigenschaft "Scharf" existiert schon, wirkt aber noch auf nichts. Sie ist
 * das Gate fuer Phase 3; wer sie einschaltet, aendert heute kein Verhalten.
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

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyBoolean('Armed', false);
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

        $this->RegisterTimer(self::TIMER_LAUF, 0, 'SR_Analyse($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_BEZUG, 0, 'SR_HoleProgramm($_IPS[\'TARGET\']);');
        $this->RegisterTimer(self::TIMER_WUNSCH, 0, 'SR_HoleWunschliste($_IPS[\'TARGET\']);');
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
        $this->RegisterVariableString('Ausstrahlungen', 'Ausstrahlungen (JSON)', '', 60);
        $this->RegisterVariableString('OffeneSender', 'Sender ohne Empfangskanal', '', 70);
        $this->RegisterVariableString('Quellen', 'Episodenquellen', '', 80);
        $this->RegisterVariableString('Bezug', 'Programmvorschau geholt', '', 90);
        $this->RegisterVariableString('Wunschliste', 'Wunschliste geholt', '', 100);

        $an = $this->ReadPropertyBoolean('Aktiv');
        $this->SetTimerInterval(self::TIMER_LAUF,
            ($an ? max(0, $this->ReadPropertyInteger('Intervall')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_BEZUG,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallBezug')) : 0) * 60 * 1000);
        $this->SetTimerInterval(self::TIMER_WUNSCH,
            ($an ? max(0, $this->ReadPropertyInteger('IntervallWunsch')) : 0) * 60 * 1000);

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
        $a = $this->baueAnalyse();
        // Die selbst geholte Datei hat Vorrang; fehlt sie, wird die des
        // Altsystems gelesen. So laeuft das Modul in jeder Ausbaustufe.
        $eigen = $this->pfad('XmltvZiel');
        $quelle = is_readable($eigen) ? $eigen : $this->pfad('XmltvDatei');
        $e = $a->lauf(new XmltvLeser($quelle), time() - 3600, time() + $vorschau * 86400);

        $this->SetValue('Zugeordnet', (int) ($e['kennzahlen']['zugeordnet'] ?? 0));
        $this->SetValue('OhneEmpfang', (int) ($e['kennzahlen']['Sender nicht empfangbar'] ?? 0));
        $this->SetValue('Aufnehmen', (int) ($e['kennzahlen']['aufnehmen'] ?? 0));
        $this->SetValue('Ausgeschlossen', (int) ($e['kennzahlen']['ausgeschlossen'] ?? 0));
        $this->SetValue('Dauer', $e['dauerMs']);
        $this->SetValue('LetzterLauf', time());
        $this->SetValue('Ausstrahlungen', json_encode(Analyse::alsTabelle($e['sendungen']), JSON_UNESCAPED_UNICODE));
        $this->SetValue('OffeneSender', implode("\n", $e['offeneSender']));
        $this->SetValue('Quellen', (string) ($e['quellen'] ?? ''));
        $this->SetValue('Status', sprintf('%d Ausstrahlungen, davon %d fehlend; %d Serien, %d ms%s',
            $e['kennzahlen']['zugeordnet'] ?? 0,
            $e['kennzahlen']['aufnehmen'] ?? 0,
            $e['kennzahlen']['Serien mit Ausstrahlung'] ?? 0,
            $e['dauerMs'],
            $this->ReadPropertyBoolean('Armed') ? '' : ' (nur lesend)'));
        $this->SendDebug('SR.quelle', 'gelesen aus ' . basename($quelle), 0);

        return json_encode(['ok' => true] + $e['kennzahlen'] + ['dauerMs' => $e['dauerMs']], JSON_UNESCAPED_UNICODE);
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
        $this->SetValue('Wunschliste', sprintf('%s · %s%s · %.1f s',
            date('d.m. H:i'), $e['meldung'],
            $e['anzahl'] > 0 ? (' (' . $e['anzahl'] . ' Serien)') : '',
            $e['dauerMs'] / 1000));
        return json_encode($e, JSON_UNESCAPED_UNICODE);
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

        return json_encode([
            'elements' => [
                ['type' => 'CheckBox', 'name' => 'Aktiv', 'caption' => 'Aktiv'],
                ['type' => 'CheckBox', 'name' => 'Armed', 'caption' => 'Scharf (schaltet Timer am Receiver - in diesem Stand ohne Wirkung)'],
                ['type' => 'Label', 'caption' => '— Zeitsteuerung: 0 schaltet die jeweilige Aufgabe ab —'],
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Zuordnen und bewerten (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'NumberSpinner', 'name' => 'IntervallBezug', 'caption' => 'Programmvorschau holen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvUrl', 'caption' => 'Quelle der Programmvorschau (URL)'],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvZiel', 'caption' => 'Zieldatei (eigene, nicht die des Altsystems)'],
                ['type' => 'NumberSpinner', 'name' => 'IntervallWunsch', 'caption' => 'Wunschliste holen (Minuten)', 'minimum' => 0, 'maximum' => 10080],
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
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt lesen (ohne Wirkung)', 'onClick' => 'SR_Analyse($id);'],
                ['type' => 'Button', 'caption' => 'Programmvorschau jetzt holen', 'onClick' => 'echo SR_HoleProgramm($id);'],
                ['type' => 'Button', 'caption' => 'Wunschliste jetzt holen', 'onClick' => 'echo SR_HoleWunschliste($id);'],
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
            $this->kanaltabelle(), new Bestand($this->pfad('BestandDatei')), $this->bedingungen(),
            $this->episodenquelle());
    }

    private function pfad(string $property): string
    {
        return rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/' . ltrim($this->ReadPropertyString($property), '/');
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

    /** @return list<string> */
    private function favoriten(): array
    {
        // Die selbst geholte Liste hat Vorrang, sonst die des Altsystems.
        $eigen = $this->pfad('WunschZiel');
        $d = $this->json(is_readable($eigen) ? $eigen : $this->pfad('FavoritenDatei'));
        return array_values(array_filter(array_map(
            static fn(array $f): string => trim((string) ($f['name'] ?? '')),
            $d['favorites'] ?? []
        )));
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

    private function bedingungen(): Bedingungen
    {
        $liste = json_decode($this->ReadPropertyString('Bedingungen'), true) ?: [];
        return new Bedingungen(is_array($liste) ? $liste : []);
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
