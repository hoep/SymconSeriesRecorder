<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/SeriesRecorder/Analyse.php';

use Hoep\SeriesRecorder\Analyse;
use Hoep\SeriesRecorder\KanalMapper;
use Hoep\SeriesRecorder\TitelResolver;
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
    private const TIMER_LAUF = 'Lauf';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Aktiv', true);
        $this->RegisterPropertyBoolean('Armed', false);
        $this->RegisterPropertyInteger('Intervall', 60);          // Minuten, 0 = kein Timer
        $this->RegisterPropertyString('Datenpfad', '/var/lib/symcon/serienrecorder/');
        $this->RegisterPropertyString('XmltvDatei', 'xmltv.xml');
        $this->RegisterPropertyString('FavoritenDatei', 'favorites.xml');
        $this->RegisterPropertyString('KanaeleDatei', 'channels.json');
        $this->RegisterPropertyInteger('Vorschau', 14);           // Tage nach vorn

        // Regeln als Daten, nicht als Code. In der Skript-Fassung standen sie als
        // PHP-Literale mitten im Ablauf - deshalb hat auch nie jemand bemerkt, dass
        // die Tatort-Bedingung faktisch jede Tatort-Ausstrahlung verwarf.
        $this->RegisterPropertyString('Kanaltabelle', '[]');      // XMLTV-Name => Empfangskanal
        $this->RegisterPropertyString('Titeltabelle', '[]');      // XMLTV-Titel => Favorit + Ablagename

        $this->RegisterTimer(self::TIMER_LAUF, 0, 'SR_Analyse($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterVariableString('Status', 'Status', '', 10);
        $this->RegisterVariableInteger('LetzterLauf', 'Letzter Lauf', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('Dauer', 'Dauer (ms)', '', 30);
        $this->RegisterVariableInteger('Zugeordnet', 'Zugeordnete Ausstrahlungen', '', 40);
        $this->RegisterVariableInteger('OhneEmpfang', 'Verworfen (Sender fehlt)', '', 50);
        $this->RegisterVariableString('Ausstrahlungen', 'Ausstrahlungen (JSON)', '', 60);
        $this->RegisterVariableString('OffeneSender', 'Sender ohne Empfangskanal', '', 70);

        $min = $this->ReadPropertyBoolean('Aktiv') ? max(0, $this->ReadPropertyInteger('Intervall')) : 0;
        $this->SetTimerInterval(self::TIMER_LAUF, $min * 60 * 1000);

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
        $e = $a->lauf(new XmltvLeser($this->pfad('XmltvDatei')), time() - 3600, time() + $vorschau * 86400);

        $this->SetValue('Zugeordnet', (int) ($e['kennzahlen']['zugeordnet'] ?? 0));
        $this->SetValue('OhneEmpfang', (int) ($e['kennzahlen']['Sender nicht empfangbar'] ?? 0));
        $this->SetValue('Dauer', $e['dauerMs']);
        $this->SetValue('LetzterLauf', time());
        $this->SetValue('Ausstrahlungen', json_encode(Analyse::alsTabelle($e['sendungen']), JSON_UNESCAPED_UNICODE));
        $this->SetValue('OffeneSender', implode("\n", $e['offeneSender']));
        $this->SetValue('Status', sprintf('%d Ausstrahlungen, %d Serien, %d ms%s',
            $e['kennzahlen']['zugeordnet'] ?? 0,
            $e['kennzahlen']['Serien mit Ausstrahlung'] ?? 0,
            $e['dauerMs'],
            $this->ReadPropertyBoolean('Armed') ? '' : ' (nur lesend)'));

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
                ['type' => 'NumberSpinner', 'name' => 'Intervall', 'caption' => 'Intervall (Minuten, 0 = kein Timer)', 'minimum' => 0, 'maximum' => 1440],
                ['type' => 'NumberSpinner', 'name' => 'Vorschau', 'caption' => 'Vorschau (Tage)', 'minimum' => 1, 'maximum' => 28],
                ['type' => 'Label', 'caption' => '— Quelldateien —'],
                ['type' => 'ValidationTextBox', 'name' => 'Datenpfad', 'caption' => 'Verzeichnis'],
                ['type' => 'ValidationTextBox', 'name' => 'XmltvDatei', 'caption' => 'XMLTV'],
                ['type' => 'ValidationTextBox', 'name' => 'FavoritenDatei', 'caption' => 'Wunschliste'],
                ['type' => 'ValidationTextBox', 'name' => 'KanaeleDatei', 'caption' => 'Empfangbare Kanaele'],
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
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt lesen (ohne Wirkung)', 'onClick' => 'SR_Analyse($id);'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ProbeTitel', 'caption' => 'Titel pruefen'],
                    ['type' => 'Button', 'caption' => 'Zuordnen', 'onClick' => 'echo SR_TitelProbe($id, $ProbeTitel);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ProbeSender', 'caption' => 'Sender pruefen'],
                    ['type' => 'Button', 'caption' => 'Zuordnen', 'onClick' => 'echo SR_KanalProbe($id, $ProbeSender);'],
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
        return new Analyse($this->favoriten(), $tt['aliase'], $tt['ablage'], $this->empfangbar(), $this->kanaltabelle());
    }

    private function pfad(string $property): string
    {
        return rtrim($this->ReadPropertyString('Datenpfad'), '/') . '/' . ltrim($this->ReadPropertyString($property), '/');
    }

    /** @return list<string> */
    private function fehlendeDateien(): array
    {
        $fehlt = [];
        foreach (['XmltvDatei', 'FavoritenDatei', 'KanaeleDatei'] as $p) {
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
        $d = $this->json($this->pfad('FavoritenDatei'));
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
