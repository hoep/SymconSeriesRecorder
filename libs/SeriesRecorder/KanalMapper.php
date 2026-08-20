<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Bildet einen XMLTV-Kanal auf einen empfangbaren Kanal des Receivers ab.
 *
 * Die Skript-Fassung fuehrte eine Tabelle mit 27 handgeschriebenen Paaren
 * ("One" => "ONE HD"). Sie verglich gegen den XMLTV-Anzeigenamen, und der lautet
 * bei genau diesem Sender "One DE" - also griff der Eintrag nie, und alles, was
 * dort lief, war fuer den Recorder unsichtbar. Nachgewiesen an 26 Sendungen
 * "Walking on Sunshine" im August 2026.
 *
 * Deshalb hier drei Stufen statt einer Tabelle: ausdrueckliche Zuordnung,
 * Vergleichsform, und Vergleichsform ohne die ueblichen Zusaetze (HD, DE, SD).
 * Die Tabelle bleibt trotzdem - sie faengt die Faelle, in denen sich die Namen
 * wirklich unterscheiden ("Kabel Eins" gegen "kabel eins HD").
 */
final class KanalMapper
{
    /** Zusaetze, die nur die Ausstrahlungsform oder das Land bezeichnen. */
    private const ZUSAETZE = '/\b(hd|uhd|sd|fhd|de|at|ch|austria|deutschland|oesterreich)\b/u';

    /** @var array<string,string> Vergleichsform des Empfangskanals => Originalname */
    private array $empfang = [];

    /** @var array<string,string> Vergleichsform ohne Zusaetze => Originalname */
    private array $empfangKurz = [];

    /** @var array<string,string> kanonische Form (Zahlwoerter als Ziffern) => Originalname */
    private array $empfangKanon = [];

    /** @var array<string,string> dasselbe fuer die Tabelle */
    private array $tabelleKanon = [];

    /** @var array<string,list<string>> verdichtete Form (ohne Leerzeichen) => alle passenden Kanaele */
    private array $empfangEng = [];

    /** @var array<string,string> Vergleichsform des XMLTV-Namens => Empfangskanal (Originalname) */
    private array $tabelle = [];

    /** @var array<string,string> dasselbe ohne Ausstrahlungs-/Landeszusatz */
    private array $tabelleKurz = [];

    /** @var list<string> XMLTV-Namen, die zu keinem Empfangskanal fuehrten */
    private array $offen = [];

    /**
     * @param string[]             $empfangbar Kanalnamen des Receivers (channels.json)
     * @param array<string,string> $tabelle    XMLTV-Name => Empfangskanal, ausdrueckliche Zuordnung
     */
    public function __construct(array $empfangbar, array $tabelle = [])
    {
        foreach ($empfangbar as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $v = self::vergleichsform($name);
            // Erster gewinnt: "ORF1 HD" soll nicht von einem spaeteren "ORF1 SD"
            // ueberschrieben werden, die Liste ist nach Wertigkeit sortiert.
            $this->empfang[$v] ??= $name;
            $this->empfangKurz[self::ohneZusaetze($v)] ??= $name;
            $this->empfangKanon[self::kanonisch(self::ohneZusaetze($v))] ??= $name;
            $eng = self::verdichtet(self::kanonisch(self::ohneZusaetze($v)));
            if ($eng !== '') {
                $this->empfangEng[$eng][] = $name;
            }
        }
        foreach ($tabelle as $von => $nach) {
            $v = self::vergleichsform((string) $von);
            $this->tabelle[$v] = (string) $nach;
            $kurz = self::ohneZusaetze($v);
            if ($kurz !== '') {
                $this->tabelleKurz[$kurz] ??= (string) $nach;
                $this->tabelleKanon[self::kanonisch($kurz)] ??= (string) $nach;
            }
        }
    }

    /**
     * @param string $xmltvName Anzeigename aus dem XMLTV (display-name)
     * @return array{kanal:string,regel:string}|null null = nicht empfangbar
     */
    public function finde(string $xmltvName): ?array
    {
        $v = self::vergleichsform($xmltvName);
        if ($v === '') {
            return null;
        }
        if (isset($this->tabelle[$v])) {
            return ['kanal' => $this->tabelle[$v], 'regel' => 'tabelle'];
        }
        if (isset($this->empfang[$v])) {
            return ['kanal' => $this->empfang[$v], 'regel' => 'exakt'];
        }
        // Ohne Ausstrahlungs- und Landeszusatz. Das XMLTV haengt sie an ("Kabel 1 DE",
        // "ORF 1 AT"), die Tabelle kennt nur den nackten Namen - ohne diesen Schritt
        // greift sie bei fast jedem deutschen Privatsender daneben.
        $kurz = self::ohneZusaetze($v);
        if ($kurz !== '') {
            if (isset($this->tabelleKurz[$kurz])) {
                return ['kanal' => $this->tabelleKurz[$kurz], 'regel' => 'tabelle ohne Zusatz'];
            }
            if (isset($this->empfangKurz[$kurz])) {
                return ['kanal' => $this->empfangKurz[$kurz], 'regel' => 'ohne Zusatz'];
            }
            // Letzte Stufe: Zahlwoerter als Ziffern und Ziffern ans Wort gezogen.
            // Derselbe Sender heisst im XMLTV "Pro 7 Maxx DE" und beim Receiver
            // "Pro7 MAXX HD"; "Kabel 1" und "kabel eins" sind ebenfalls einer.
            // Bewusst zuletzt: die Regel fasst mehr zusammen als die davor und
            // soll nur greifen, wenn nichts Genaueres gepasst hat.
            $kan = self::kanonisch($kurz);
            if ($kan !== '') {
                if (isset($this->tabelleKanon[$kan])) {
                    return ['kanal' => $this->tabelleKanon[$kan], 'regel' => 'tabelle kanonisch'];
                }
                if (isset($this->empfangKanon[$kan])) {
                    return ['kanal' => $this->empfangKanon[$kan], 'regel' => 'kanonisch'];
                }
                // Ganz zuletzt ohne Leerzeichen: das XMLTV schreibt "N-TV DE", "VOX Up DE"
                // und "ZDF Info DE", die Empfangsliste "ntv HD", "VOXup HD", "ZDFinfo HD".
                // NUR bei Eindeutigkeit - faenden mehrere Kanaele auf dieselbe verdichtete
                // Form, waere die Wahl geraten, und ein falscher Sender nimmt die falsche
                // Sendung auf. Dann lieber kein Treffer und ein Eintrag im Protokoll.
                $eng = self::verdichtet($kan);
                if ($eng !== '' && isset($this->empfangEng[$eng]) && count($this->empfangEng[$eng]) === 1) {
                    return ['kanal' => $this->empfangEng[$eng][0], 'regel' => 'verdichtet'];
                }
                // Zuletzt der Namensanfang: der Empfangskanal heisst "AnixeHD Serie",
                // das XMLTV nur "Anixe DE" - das HD klebt hier am Namen und faellt
                // deshalb durch alle Zusatz-Regeln. Wieder nur bei Eindeutigkeit:
                // "One" ist Anfang von "ONE HD" UND "One Terra HD", da waere jede
                // Wahl geraten.
                if (mb_strlen($eng) >= 4) {
                    $anfang = [];
                    foreach ($this->empfangEng as $form => $namen) {
                        if (str_starts_with($form, $eng)) {
                            foreach ($namen as $n) {
                                $anfang[$n] = true;
                            }
                        }
                    }
                    if (count($anfang) === 1) {
                        return ['kanal' => array_key_first($anfang), 'regel' => 'namensanfang'];
                    }
                }
            }
        }
        $this->offen[$xmltvName] = $xmltvName;
        return null;
    }

    /**
     * XMLTV-Namen ohne Empfangskanal. Gehoert ins Protokoll: ein Sender, der hier
     * auftaucht, ist fuer den Recorder unsichtbar - so ein Fall soll nie wieder
     * unbemerkt bleiben.
     *
     * @return list<string>
     */
    public function offen(): array
    {
        return array_values($this->offen);
    }

    private static function vergleichsform(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /**
     * Kanonische Form: Zahlwoerter werden zu Ziffern, und Ziffern ruecken an das
     * Wort davor. Damit sind "prosieben maxx", "pro 7 maxx" und "pro7 maxx"
     * derselbe Sender - Senderlisten und EPG-Anbieter schreiben das beliebig.
     */
    private static function kanonisch(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = strtr($s, [
            'eins' => '1', 'zwei' => '2', 'drei' => '3', 'vier' => '4', 'fuenf' => '5',
            'sechs' => '6', 'sieben' => '7', 'acht' => '8', 'neun' => '9', 'zehn' => '10',
            'one' => '1', 'two' => '2', 'three' => '3',
        ]);
        // Ziffer an das vorangehende Wort ziehen: "sat 1" -> "sat1".
        $s = preg_replace('/(\p{L})\s+(\d)/u', '$1$2', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** Form ohne jedes Leerzeichen. Nur fuer die letzte, eindeutigkeitsgepruefte Stufe. */
    private static function verdichtet(string $s): string
    {
        $s = str_replace(' ', '', $s);
        return mb_strlen($s) >= 3 ? $s : '';
    }

    private static function ohneZusaetze(string $vergleichsform): string
    {
        $s = preg_replace(self::ZUSAETZE, ' ', $vergleichsform) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        // Leerbleibende Namen (ein Sender, der nur "HD" heisst) waeren mehrdeutig.
        return mb_strlen($s) >= 2 ? $s : '';
    }
}
