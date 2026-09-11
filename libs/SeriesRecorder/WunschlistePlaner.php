<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

/**
 * Der persoenliche TV-Planer von wunschliste.de als ZWEITE Quelle fuer die
 * Folgennummer.
 *
 * Warum es ihn braucht: die Nummer einer Ausstrahlung stammt sonst allein aus der
 * XMLTV-Datei, und die kann schlicht falsch sein. Am 11.09.2026 fuehrte sie VIER
 * Ausstrahlungen von "The Voice of Germany" als S16E01 "Blind Audition (1)" - mit
 * zeichengleicher Beschreibung. In Wahrheit lief am 11.09. auf SAT.1 Folge 1 und am
 * 12.09. auf ProSieben Folge 2. In den Programmdaten gab es KEIN Merkmal, an dem sich
 * das haette erkennen lassen; der Entscheider hielt drei Erstausstrahlungen fuer
 * Wiederholungen, und Folge 1 waere nie aufgenommen worden.
 *
 * Der TV-Planer weiss es besser. Er liefert je Ausstrahlung Staffel, Folge,
 * Episodentitel UND die Kennzeichnung, ob es eine Erstausstrahlung ist oder eine
 * Wiederholung - und zwar nur fuer die eigenen Wunschserien, also genau fuer die
 * Menge, um die es geht.
 *
 * Die Quelle ERSETZT nichts: wo sie schweigt, bleibt alles beim Alten. Wo sie etwas
 * sagt, hat sie Vorrang vor der XMLTV-Nummer.
 */
final class WunschlistePlaner
{
    public const URL = 'https://www.wunschliste.de/ajax/tvplaner.pl?ajax=1&start=1&tag=';

    /** @var list<array{start:int,sender:string,serie:string,staffel:int,folge:int,titel:string,wdh:bool}> */
    private array $eintraege = [];
    private bool $geladen = false;
    private string $meldung = '';
    private int $stand = 0;

    /** @var list<string> */
    private array $abweichungen = [];

    /** @var list<string> */
    private array $luecken = [];

    /** @var array<string,list<array<string,mixed>>> Ablagename (Bestand::form) => Eintraege */
    private array $nachAblage = [];

    /**
     * @param string $datei    Zwischenlager (JSON)
     * @param ?callable $holer liefert das HTML des TV-Planers; null = nur lesen, nie holen
     * @param int $maxAlter    Sekunden, ab denen neu geholt wird
     */
    public function __construct(
        private string $datei,
        private $holer = null,
        private int $maxAlter = 3600,
    ) {
    }

    public function meldung(): string
    {
        return $this->meldung;
    }

    public function stand(): int
    {
        return $this->stand;
    }

    /** @return list<string> Wo XMLTV und Planer verschiedener Meinung waren. */
    public function abweichungen(): array
    {
        return $this->abweichungen;
    }

    /**
     * Ausstrahlungen, zu denen der Planer NICHTS sagt - dort gilt weiter das XMLTV
     * allein. Die Liste gehoert sichtbar gemacht: sie zeigt genau die Stellen, an
     * denen die Berichtigung nicht greifen kann.
     *
     * @return list<string>
     */
    public function luecken(): array
    {
        return $this->luecken;
    }

    public function merkeLuecke(string $text): void
    {
        if (count($this->luecken) < 200) {
            $this->luecken[] = $text;
        }
    }

    public function anzahl(): int
    {
        $this->laden();
        return count($this->eintraege);
    }

    /**
     * Die Eintraege unter dem ABLAGENAMEN einordnen.
     *
     * Dieselbe Serie heisst an drei Stellen verschieden: der Planer schreibt
     * "Criminal Intent - Verbrechen im Visier", die Ablage "Criminal Intent", das EPG
     * mal so, mal so. Es ist EINE Serie, und das Haus hat dafuer laengst eine
     * Titeltabelle - die wird hier benutzt, statt zwei Schreibweisen zu raten.
     *
     * @param callable(string):string $ablageFuer loest einen Seriennamen zum Ablagenamen auf
     */
    public function ordneZu(callable $ablageFuer): void
    {
        $this->laden();
        $this->nachAblage = [];
        foreach ($this->eintraege as $e) {
            $namen = [(string) $e['serie']];
            $auf = trim((string) $ablageFuer((string) $e['serie']));
            if ($auf !== '') {
                $namen[] = $auf;
            }
            foreach (array_unique($namen) as $n) {
                $k = Bestand::form($n);
                if ($k !== '') {
                    $this->nachAblage[$k][] = $e;
                }
            }
        }
    }

    /**
     * Die Ausstrahlung im Planer suchen.
     *
     * Verglichen wird ueber SERIE und ZEIT, nicht ueber den Sendernamen: wunschliste
     * schreibt "Sat.1", die Box "SAT.1 HD", das XMLTV "Sat1.de". Drei Schreibweisen
     * gegeneinander zu normalisieren waere eine Fehlerquelle fuer sich - und zwei
     * Ausstrahlungen DERSELBEN Serie zur selben Minute auf zwei Sendern gibt es nicht.
     * Der Sender wird nur mitgegeben, damit die Abweichungsliste ihn nennen kann.
     *
     * @return ?array{start:int,sender:string,serie:string,staffel:int,folge:int,titel:string,wdh:bool}
     */
    public function finde(string $serie, int $start, int $toleranzS = 1200): ?array
    {
        $this->laden();
        $s = Bestand::form($serie);
        if ($s === '' || $start <= 0) {
            return null;
        }
        // Ueber den Ablagenamen, sofern eingeordnet - sonst ueber den rohen Namen.
        $menge = $this->nachAblage[$s] ?? null;
        if ($menge === null) {
            $menge = [];
            foreach ($this->eintraege as $e) {
                if (Bestand::form($e['serie']) === $s) {
                    $menge[] = $e;
                }
            }
        }
        $besterAbstand = $toleranzS + 1;
        $bester = null;
        foreach ($menge as $e) {
            $abstand = abs($e['start'] - $start);
            if ($abstand <= $toleranzS && $abstand < $besterAbstand) {
                $besterAbstand = $abstand;
                $bester = $e;
            }
        }
        return $bester;
    }

    /** Eine Abweichung festhalten - fuer den Bericht, nicht fuer die Entscheidung. */
    public function merkeAbweichung(string $text): void
    {
        if (count($this->abweichungen) < 200) {
            $this->abweichungen[] = $text;
        }
    }

    // ------------------------------------------------------------------ laden

    private function laden(): void
    {
        if ($this->geladen) {
            return;
        }
        $this->geladen = true;

        $alt = $this->ausDatei();
        $frisch = ($alt !== null && (time() - (int) ($alt['stand'] ?? 0)) < $this->maxAlter);
        if ($frisch) {
            $this->eintraege = $alt['eintraege'];
            $this->stand = (int) $alt['stand'];
            $this->meldung = 'aus dem Zwischenlager (' . count($this->eintraege) . ' Ausstrahlungen)';
            return;
        }
        if ($this->holer === null) {
            // Kein Abrufweg: lieber die alte Liste als gar keine. Eine Nummer von
            // gestern ist immer noch besser als eine falsche aus dem XMLTV.
            if ($alt !== null) {
                $this->eintraege = $alt['eintraege'];
                $this->stand = (int) $alt['stand'];
                $this->meldung = 'veraltet, kein Abrufweg (' . count($this->eintraege) . ')';
            } else {
                $this->meldung = 'kein Zwischenlager und kein Abrufweg';
            }
            return;
        }
        try {
            $html = (string) ($this->holer)();
        } catch (\Throwable $e) {
            $html = '';
            $this->meldung = 'Abruf fehlgeschlagen: ' . $e->getMessage();
        }
        $neu = $html === '' ? [] : self::zerlege($html);
        if ($neu === []) {
            // NIE die gute Liste durch eine leere ersetzen - genau daran ist schon
            // die Favoritenliste einmal gescheitert.
            if ($alt !== null) {
                $this->eintraege = $alt['eintraege'];
                $this->stand = (int) $alt['stand'];
                $this->meldung = ($this->meldung !== '' ? $this->meldung : 'Antwort ohne Ausstrahlungen')
                    . ' - alte Liste bleibt (' . count($this->eintraege) . ')';
            } elseif ($this->meldung === '') {
                $this->meldung = 'Antwort ohne Ausstrahlungen';
            }
            return;
        }
        $this->eintraege = $neu;
        $this->stand = time();
        $this->meldung = 'geholt (' . count($neu) . ' Ausstrahlungen)';
        $this->inDatei();
    }

    /** @return ?array{stand:int,eintraege:list<array<string,mixed>>} */
    private function ausDatei(): ?array
    {
        $j = json_decode((string) @file_get_contents($this->datei), true);
        if (!is_array($j) || !is_array($j['eintraege'] ?? null) || $j['eintraege'] === []) {
            return null;
        }
        return ['stand' => (int) ($j['stand'] ?? 0), 'eintraege' => $j['eintraege']];
    }

    private function inDatei(): void
    {
        $ordner = dirname($this->datei);
        if (!is_dir($ordner)) {
            @mkdir($ordner, 0775, true);
        }
        @file_put_contents($this->datei, (string) json_encode(
            ['stand' => $this->stand, 'anzahl' => count($this->eintraege), 'eintraege' => $this->eintraege],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    // ------------------------------------------------------------- zerlegen

    /**
     * Das HTML des TV-Planers in Ausstrahlungen zerlegen.
     *
     * Aufbau (Stand 11.09.2026):
     *   <h2 class="upfront clear absatz">Freitag, 11.09.2026    <- gilt fuer alles danach
     *   <li id="60" class="db mix ...">
     *     <span class="w70-60">20.15<label> Uhr</label>
     *     <img ... alt="Sat.1" ...>
     *     <label class="sendung ...">The Voice of Germany</label>
     *     <span class="epg_st" title="Staffel">16</span>
     *     <span class="epg_ep" title="Episode">01</span> Blind Audition (1)<span class="hinweis">NEU</span>
     *
     * Die Wiederholung steht NICHT in der Klasse des <li>, sondern als "(Wdh.)" hinter
     * dem Episodentitel - die Klasse "neu" markiert etwas anderes (Free-TV-Premiere)
     * und traegt nur 20 von 1000 Eintraegen.
     *
     * @return list<array{start:int,sender:string,serie:string,staffel:int,folge:int,titel:string,wdh:bool}>
     */
    public static function zerlege(string $html): array
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }
        // Tagesueberschriften und Eintraege in EINEM Durchlauf, damit die Reihenfolge
        // erhalten bleibt: der Tag gilt fuer alle Eintraege bis zur naechsten Ueberschrift.
        $muster = '/(?P<tag><h2[^>]*>\s*(?:Montag|Dienstag|Mittwoch|Donnerstag|Freitag|Samstag|Sonntag),\s*'
                . '(?P<datum>\d{2}\.\d{2}\.\d{4}))|(?P<eintrag><li\s+id="\d+"\s+class="[^"]*".*?<\/li>)/su';
        if (!preg_match_all($muster, $html, $treffer, PREG_SET_ORDER)) {
            return [];
        }
        $raus = [];
        $datum = '';
        foreach ($treffer as $t) {
            if (($t['tag'] ?? '') !== '') {
                $datum = (string) $t['datum'];
                continue;
            }
            $e = ($t['eintrag'] ?? '');
            if ($e === '' || $datum === '') {
                continue;
            }
            $x = self::eintrag($e, $datum);
            if ($x !== null) {
                $raus[] = $x;
            }
        }
        return $raus;
    }

    /** @return ?array{start:int,sender:string,serie:string,staffel:int,folge:int,titel:string,wdh:bool} */
    private static function eintrag(string $e, string $datum): ?array
    {
        if (!preg_match('/<label\s+class="sendung[^"]*"[^>]*>(.*?)<\/label>/su', $e, $m)) {
            return null;
        }
        $serie = self::text($m[1]);
        if ($serie === '') {
            return null;
        }
        if (!preg_match('/<span\s+class="w70-60"[^>]*>\s*(\d{1,2})[.:](\d{2})/su', $e, $mz)) {
            return null;
        }
        $start = (int) strtotime(
            substr($datum, 6, 4) . '-' . substr($datum, 3, 2) . '-' . substr($datum, 0, 2)
            . ' ' . $mz[1] . ':' . $mz[2] . ':00'
        );
        if ($start <= 0) {
            return null;
        }
        $sender = preg_match('/<img[^>]*\salt="([^"]+)"/su', $e, $ms) ? self::text($ms[1]) : '';

        // Staffel und Folge stehen in eigenen Kaesten; der Episodentitel folgt als
        // blanker Text dahinter, bis zum naechsten Element.
        $staffel = preg_match('/<span\s+class="epg_st"[^>]*>\s*(\d{1,4})\s*<\/span>/su', $e, $mst)
            ? (int) $mst[1] : 0;
        $folge = 0;
        $titel = '';
        if (preg_match('/<span\s+class="epg_ep"[^>]*>\s*(\d{1,4})\s*<\/span>([^<]*)/su', $e, $mep)) {
            $folge = (int) $mep[1];
            $titel = self::text($mep[2]);
        } elseif (preg_match('/<div\s+class="ep"[^>]*>(.*?)<\/div>/su', $e, $md)) {
            $titel = self::text($md[1]);
        }
        // "(Wdh.)" haengt hinten am Titel und gehoert nicht dazu.
        $wdh = false;
        if (preg_match('/\s*\((?:Wdh\.?|Wiederholung)\)\s*$/ui', $titel)) {
            $wdh = true;
            $titel = trim((string) preg_replace('/\s*\((?:Wdh\.?|Wiederholung)\)\s*$/ui', '', $titel));
        }
        return ['start' => $start, 'sender' => $sender, 'serie' => $serie,
                'staffel' => $staffel, 'folge' => $folge, 'titel' => $titel, 'wdh' => $wdh];
    }

    private static function text(string $roh): string
    {
        $t = html_entity_decode(strip_tags($roh), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace("\xC2\xA0", ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }
}
