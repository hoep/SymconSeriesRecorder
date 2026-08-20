<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';
require_once __DIR__ . '/../fremd/serienrecorder.class.recorder.php';

/**
 * Log-Anschluss fuer die uebernommene Recorder-Klasse.
 *
 * Sie braucht drei Methoden vom Umfeld. `seriesNamesMatch` wird hier auf
 * dieselbe Vergleichsform gelegt, die auch Bestand und Resolver benutzen -
 * damit gilt im ganzen Modul EIN Massstab dafuer, wann zwei Namen dieselbe
 * Serie meinen.
 */
final class ReceiverProtokoll
{
    /** @var list<string> */
    private array $zeilen = [];

    public function log($nachricht, $ebene = 1): void
    {
        $this->zeilen[] = (string) $nachricht;
        if (count($this->zeilen) > 200) {
            array_shift($this->zeilen);
        }
    }

    public function seriesNamesMatch($a, $b): bool
    {
        return Bestand::form((string) $a) === Bestand::form((string) $b);
    }

    public function shouldRefreshFile($datei, $stunden = 24): bool
    {
        $t = @filemtime((string) $datei);
        return $t === false || (time() - $t) > max(1, (int) $stunden) * 3600;
    }

    /** @return list<string> */
    public function zeilen(): array
    {
        return $this->zeilen;
    }
}

/**
 * Liest die programmierten Aufnahmen vom Receiver.
 *
 * In diesem Stand ausschliesslich lesend: `GET /web/timerlist`. Das Setzen von
 * Timern kann dieselbe Klasse (`/web/timeradd`), wird hier aber bewusst nicht
 * angeboten - solange das Altsystem programmiert, waeren zwei Absender auf
 * demselben Receiver ein Rezept fuer doppelte Aufnahmen.
 *
 * Der Grund fuer diesen Schritt: im Vergleich mit dem Altsystem blieben drei
 * Ausstrahlungen uebrig, bei denen es "bereits programmiert" sagte und das
 * Modul "fehlt". Beide hatten recht - nur wusste das Modul nichts von den
 * Timern. Mit dieser Klasse ist der Vergleich vollstaendig.
 */
final class Receiver
{
    private ?\SerienRecorderRecorder $handler = null;
    private ReceiverProtokoll $protokoll;

    /** @var list<array{name:string,kanal:string,start:int,ende:int,datei:string}> */
    private array $timer = [];

    private bool $gelesen = false;
    private string $fehler = '';

    public function __construct(
        private string $ip,
        private string $bouquet = '',
        private int $tuner = 25,
    ) {
        $this->protokoll = new ReceiverProtokoll();
    }

    public function einsatzbereit(): bool
    {
        return $this->ip !== '';
    }

    /**
     * Programmierte Aufnahmen. Wird je Lauf einmal geholt.
     *
     * @return list<array{name:string,kanal:string,start:int,ende:int,datei:string}>
     */
    public function timer(): array
    {
        if ($this->gelesen) {
            return $this->timer;
        }
        $this->gelesen = true;
        if (!$this->einsatzbereit() || !class_exists(\SerienRecorderRecorder::class)) {
            $this->fehler = 'kein Receiver eingetragen';
            return [];
        }
        try {
            $h = $this->handler();
            $roh = $h === null ? [] : $h->getTimerList(true);
        } catch (\Throwable $e) {
            $this->fehler = $e->getMessage();
            return [];
        }
        foreach ($roh as $t) {
            if (!is_array($t)) {
                continue;
            }
            $this->timer[] = [
                'name'  => (string) ($t['e2name'] ?? ''),
                'kanal' => (string) ($t['e2servicename'] ?? ''),
                'start' => (int) ($t['e2timebegin'] ?? 0),
                'ende'  => (int) ($t['e2timeend'] ?? 0),
                'datei' => (string) ($t['e2filename'] ?? ''),
            ];
        }
        return $this->timer;
    }

    /**
     * Ist diese Ausstrahlung schon programmiert?
     *
     * Verglichen wird ueber die Zeit, nicht ueber den Namen: der Receiver
     * schreibt in den Timernamen sein eigenes Muster, und Vor- und Nachlauf
     * verschieben die Zeiten um Minuten. Ein Timer gilt als Treffer, wenn er
     * die Sendung zeitlich umschliesst und derselbe Sender ist.
     */
    public function istProgrammiert(string $kanal, int $start, int $ende): bool
    {
        $k = Bestand::form($kanal);
        foreach ($this->timer() as $t) {
            if ($t['start'] <= 0) {
                continue;
            }
            if ($k !== '' && Bestand::form($t['kanal']) !== $k) {
                continue;
            }
            // Grosszuegig: Vorlauf und Nachlauf duerfen die Grenzen verschieben.
            if ($t['start'] <= $start + 300 && ($t['ende'] <= 0 || $t['ende'] >= $ende - 300)) {
                return true;
            }
        }
        return false;
    }

    public function bericht(): string
    {
        if (!$this->einsatzbereit()) {
            return 'Receiver: nicht eingetragen';
        }
        if ($this->fehler !== '') {
            return 'Receiver ' . $this->ip . ': ' . $this->fehler;
        }
        return sprintf('Receiver %s: %d programmierte Aufnahmen', $this->ip, count($this->timer()));
    }

    /** @return list<string> */
    public function protokollzeilen(): array
    {
        return $this->protokoll->zeilen();
    }

    private function handler(): ?\SerienRecorderRecorder
    {
        if ($this->handler !== null) {
            return $this->handler;
        }
        $config = [
            'debug'                  => false,
            'debug_level'            => 0,
            'ip'                     => $this->ip,
            'tunerCount'             => $this->tuner,
            'bouquet'                => $this->bouquet,
            'timer_refresh_interval' => 60,
        ];
        $this->handler = new \SerienRecorderRecorder($config, $this->protokoll);
        return $this->handler;
    }
}
