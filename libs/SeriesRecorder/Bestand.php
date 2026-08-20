<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Der Bestand auf der Platte: liegt diese Folge schon da?
 *
 * Quelle ist die Liste, die der Scanner schreibt - eine Zeile je Aufnahme:
 *
 *     0|100 Code|S01E01|Wintermädchen|/mnt/Aufnahmen/100 Code/Season 1/…ts
 *
 * Die Datei ist ISO-8859-1 kodiert (Dateinamen vom Receiver), waehrend alles
 * andere im System UTF-8 ist. Wer das uebersieht, findet jede Folge mit Umlaut
 * nicht wieder und nimmt sie ein zweites Mal auf.
 *
 * Gesucht wird ueber ZWEI Schluessel, und das ist kein Luxus: fuer 14 der 417
 * Ausstrahlungen liefert das EPG keine Staffel/Folge (S00E00). Ueber die
 * Nummer allein waeren sie unentscheidbar, ueber den Episodentitel sind sie es
 * nicht.
 */
final class Bestand
{
    /** @var array<string,list<string>> "serie|s01e01" => Dateipfade */
    private array $nachNummer = [];

    /** @var array<string,list<string>> "serie|episodentitel" => Dateipfade */
    private array $nachTitel = [];

    private int $zeilen = 0;

    public function __construct(private string $datei)
    {
        $this->lies();
    }

    public function anzahl(): int
    {
        return $this->zeilen;
    }

    /**
     * @return array{da:bool,weg:string,dateien:list<string>}
     *         weg: 'nummer', 'titel' oder '' (nicht gefunden)
     */
    public function suche(string $serie, int $staffel, int $folge, string $episodentitel = ''): array
    {
        $s = self::form($serie);
        if ($s !== '' && ($staffel > 0 || $folge > 0)) {
            $k = $s . '|' . self::nummer($staffel, $folge);
            if (isset($this->nachNummer[$k])) {
                return ['da' => true, 'weg' => 'nummer', 'dateien' => $this->nachNummer[$k]];
            }
        }
        // Der Episodentitel traegt, wo die Nummer fehlt - und er faengt auch den
        // Fall, in dem dieselbe Folge unter abweichender Zaehlung gelaufen ist
        // (EPG und TVDB sind sich bei Mehrteilern oft nicht einig).
        $t = self::form($episodentitel);
        if ($s !== '' && $t !== '' && isset($this->nachTitel[$s . '|' . $t])) {
            return ['da' => true, 'weg' => 'titel', 'dateien' => $this->nachTitel[$s . '|' . $t]];
        }
        return ['da' => false, 'weg' => '', 'dateien' => []];
    }

    /** Serien im Bestand (Anzeigeform der ersten Fundstelle). */
    public function serien(): int
    {
        $s = [];
        foreach (array_keys($this->nachNummer) as $k) {
            $s[explode('|', $k)[0]] = true;
        }
        return count($s);
    }

    private function lies(): void
    {
        $fh = @fopen($this->datei, 'r');
        if ($fh === false) {
            return;
        }
        while (($zeile = fgets($fh)) !== false) {
            // Erst nach UTF-8 wandeln, dann zerlegen: die Trennzeichen sind zwar
            // ASCII, die Serien- und Episodennamen daneben aber nicht.
            if (!mb_check_encoding($zeile, 'UTF-8')) {
                $zeile = mb_convert_encoding($zeile, 'UTF-8', 'ISO-8859-1');
            }
            $f = explode('|', rtrim($zeile, "\r\n"));
            if (count($f) < 4) {
                continue;
            }
            // Die Spalten stehen NICHT immer gleich: mal "id|Serie|S01E01|Titel|Pfad",
            // mal "id|Serie|Zweitname|S02E03|Pfad". Deshalb nicht nach Position lesen,
            // sondern die Felder erkennen - der Pfad ist das letzte, die Nummer das
            // Feld, das wie eine Nummer aussieht, die Serie steht immer an Position 2.
            $pfad  = (string) array_pop($f);
            $serie = (string) ($f[1] ?? '');
            $s = self::form($serie);
            if ($s === '') {
                continue;
            }
            $this->zeilen++;

            $nummer = '';
            $rest = [];
            foreach (array_slice($f, 2) as $feld) {
                if ($nummer === '' && preg_match('/^\s*S\d{1,4}E\d{1,4}\s*$/i', (string) $feld)) {
                    $nummer = mb_strtolower(trim((string) $feld), 'UTF-8');
                } else {
                    $rest[] = (string) $feld;
                }
            }
            if ($nummer !== '' && $nummer !== 's00e00') {
                $this->nachNummer[$s . '|' . $nummer][] = $pfad;
            }

            // Episodentitel: im Dateinamen steht er hinter der Nummer und ist dort am
            // verlaesslichsten - die Spalte davor fuehrt bei manchen Serien einen
            // Zweitnamen statt des Titels ("Dr. Nice" / "Dr Nice").
            $titel = '';
            if (preg_match('/ - S\d{1,4}E\d{1,4} - (.+?)\.[a-z0-9]{2,4}$/i', $pfad, $m)) {
                $titel = $m[1];
            } elseif ($rest !== []) {
                $titel = (string) end($rest);
            }
            foreach (self::titelFormen($titel) as $t) {
                $this->nachTitel[$s . '|' . $t][] = $pfad;
            }
        }
        fclose($fh);
    }

    /**
     * Ein Dateiname kann den Episodentitel mit Vorspann tragen:
     *
     *     Tatort - S2023E26 - Berlinger & Rascher - 05 - Aus dem Dunkel.ts
     *
     * Vorn stehen Ermittlerteam und Teamzaehlung, der eigentliche Folgentitel
     * steht hinten. Das EPG kennt nur "Aus dem Dunkel". Deshalb wird ausser dem
     * vollen Titel auch das letzte Segment indiziert - ohne das galt eine
     * laengst aufgenommene Folge als fehlend und waere ein zweites Mal
     * aufgezeichnet worden.
     *
     * @return list<string> Vergleichsformen, unter denen die Aufnahme auffindbar ist
     */
    private static function titelFormen(string $titel): array
    {
        $out = [];
        $voll = self::form($titel);
        if ($voll !== '') {
            $out[$voll] = true;
        }
        $teile = preg_split('/\s+-\s+/u', trim($titel)) ?: [];
        if (count($teile) > 1) {
            $letzt = self::form((string) end($teile));
            // Mindestlaenge gegen Zufallstreffer: "05" oder "II" waeren keine Titel.
            if ($letzt !== '' && mb_strlen($letzt) >= 6) {
                $out[$letzt] = true;
            }
        }
        return array_keys($out);
    }

    public static function nummer(int $staffel, int $folge): string
    {
        return sprintf('s%02de%02d', max(0, $staffel), max(0, $folge));
    }

    /**
     * Vergleichsform. Absichtlich dieselbe Bauart wie beim Titel-Resolver:
     * Gross-/Kleinschreibung, Satzzeichen und Umlaut-Schreibweisen sollen egal
     * sein, denn Dateiname, EPG-Titel und Wunschliste schreiben denselben Namen
     * jeweils anders. Oeffentlich, weil die Entscheidung ihre Schluessel aus
     * denselben Bausteinen baut - zwei Vergleichsformen waeren zwei Wahrheiten.
     */
    public static function form(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'à' => 'a']);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }
}
