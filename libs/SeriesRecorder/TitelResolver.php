<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Ordnet einen Sendungstitel aus dem XMLTV genau einem Favoriten zu.
 *
 * Der Vorgaenger beantwortete die Frage "passen diese beiden Namen zueinander?"
 * fuer ein Paar isoliert. Das ist nicht entscheidbar: hinter "Walking on Sunshine
 * (S03/E05)" steht ein Episodenzusatz, hinter "Navy CIS: Hawaii" ein eigener
 * Ableger - beide Male folgt dem Basisnamen ein Zusatz. Wer nur das Paar sieht,
 * muss raten und entscheidet sich mit harten Ausschluessen ("andere Wortanzahl ->
 * nein"), die reale Sendungen verwerfen.
 *
 * Hier wird der ganze Favoritenbestand gegen den Titel bewertet und der beste
 * Kandidat gewinnt. Damit loest sich der Ablegerfall von selbst: fuer
 * "Navy CIS: Hawaii" bietet der Favorit "Navy CIS Hawaii" die exakte
 * Uebereinstimmung (100) und schlaegt das kuerzere "Navy CIS", das nur ueber die
 * Zusatz-Regel (80) hereinkaeme.
 */
final class TitelResolver
{
    /** Punkte je Regel. Hoeher schlaegt niedriger; bei Gleichstand gewinnt der laengere Favorit. */
    private const P_EXAKT  = 100;   // Titel und Favorit sind (normalisiert) derselbe Name
    private const P_ALIAS  = 95;    // per Alias-Tabelle ausdruecklich zugeordnet
    private const P_ZUSATZ = 80;    // Favorit + Trenner + Zusatz, abzueglich Laenge des Zusatzes
    private const P_TIPPO  = 55;    // Schreibvariante / Tippfehler

    /** Ab hier gilt ein Kandidat als Treffer. */
    private const SCHWELLE = 55;

    /**
     * Trenner, an denen ein Zusatz beginnen darf - jeweils MIT der Zeichenumgebung,
     * die ihn als Trenner ausweist. Ein Bindestrich zaehlt nur mit Leerzeichen davor
     * und danach: sonst zerlegte er "Der Kroatien-Krimi" und "Hawaii Five-0".
     * Ohne diese Strenge passte "Sherlock" auf "Sherlock Yack - Der Zoodetektiv" -
     * eine andere Serie, die zufaellig so anfaengt.
     */
    private const TRENNER = '/\s*(?::\s|\s[–—-]\s|\s*\(|,\s*(?:Teil|Folge)\b)/u';

    /** @var list<array{name:string,norm:string,laenge:int}> */
    private array $kandidaten = [];

    /** @var array<string,string> normalisierte XMLTV-Schreibweise => Favoritenname */
    private array $aliase = [];

    /**
     * @param string[]             $favoriten Namen aus der Wunschliste
     * @param array<string,string> $aliase    XMLTV-Schreibweise => Favoritenname (Override-Regeln)
     */
    public function __construct(array $favoriten, array $aliase = [])
    {
        foreach ($favoriten as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $norm = self::normalisiere($name);
            if ($norm === '') {
                continue;
            }
            $this->kandidaten[] = ['name' => $name, 'norm' => $norm, 'laenge' => mb_strlen($norm)];
        }
        foreach ($aliase as $von => $nach) {
            $this->aliase[self::normalisiere((string) $von)] = (string) $nach;
        }
    }

    /**
     * Ablagename je Favorit: unter DIESEM Namen liegen die Aufnahmen im Dateisystem.
     * Er ist NICHT dasselbe wie der Favoritenname - "CSI: Miami" steht in der
     * Wunschliste, der Ordner heisst "CSI Miami". Im Altsystem erledigte die
     * Override-Tabelle beides in einem Schritt (Matching-Hilfe UND Umbenennung);
     * hier bleibt es getrennt, sonst wandern beim Umstieg die Aufnahmepfade.
     *
     * @var array<string,string> Favoritenname => Ablagename
     */
    private array $ablage = [];

    /** @param array<string,string> $zuordnung Favoritenname => Ablagename */
    public function setAblagenamen(array $zuordnung): void
    {
        foreach ($zuordnung as $favorit => $ablage) {
            $this->ablage[(string) $favorit] = (string) $ablage;
        }
    }

    /**
     * Favoriten, die sich nur in der Schreibweise unterscheiden. Sie sind nicht
     * falsch, kosten aber einen Wunschlisten-Platz und tauchen in der Statistik
     * mit null Treffern auf - deshalb meldet der Resolver sie, statt sie stumm
     * zusammenzufassen.
     *
     * @return array<string,list<string>> Vergleichsform => Favoritennamen
     */
    public function doppelte(): array
    {
        $nachNorm = [];
        foreach ($this->kandidaten as $k) {
            $nachNorm[$k['norm']][] = $k['name'];
        }
        return array_filter($nachNorm, static fn(array $l): bool => count($l) > 1);
    }

    /**
     * Bestimmt den passenden Favoriten zu einem Sendungstitel.
     *
     * @return array{favorit:string,ablage:string,punkte:int,regel:string,zusatz:string}|null
     *         null, wenn kein Kandidat die Schwelle erreicht
     */
    public function bestimme(string $titel): ?array
    {
        $titel = trim($titel);
        if ($titel === '') {
            return null;
        }
        $voll = self::normalisiere($titel);
        if ($voll === '') {
            return null;
        }
        // Titel ohne Zusatz. Wird am ERSTEN Trenner abgeschnitten, denn der Serienname
        // steht immer vorn: "Der Kroatien-Krimi: Jagd auf einen Toten".
        [$basis, $zusatz] = self::zerlege($titel);
        $basisNorm = ($basis === $titel) ? $voll : self::normalisiere($basis);

        $beste = null;
        foreach ($this->kandidaten as $k) {
            $t = $this->bewerte($k, $voll, $basisNorm, $zusatz);
            if ($t === null) {
                continue;
            }
            // Gleichstand geht an den laengeren Favoriten: "Navy CIS Hawaii" ist die
            // genauere Aussage als "Navy CIS", auch wenn beide dieselbe Regel treffen.
            if ($beste === null
                || $t['punkte'] > $beste['punkte']
                || ($t['punkte'] === $beste['punkte'] && $k['laenge'] > $beste['_laenge'])) {
                $t['_laenge'] = $k['laenge'];
                $beste = $t;
            }
        }
        if ($beste === null || $beste['punkte'] < self::SCHWELLE) {
            // Knapp daneben ist die interessanteste Auskunft, die dieser Resolver
            // geben kann: "Der Wien-Krimi: Blind ermittelt" mit 48 Punkten auf
            // "Blind ermittelt" heisst, dass eine Zeile in der Titeltabelle fehlt.
            // Ohne diesen Merker sieht man nur, dass nichts zugeordnet wurde.
            $this->letzterFastTreffer = ($beste === null) ? null
                : ['favorit' => (string) $beste['favorit'], 'punkte' => (int) $beste['punkte'],
                   'regel' => (string) $beste['regel']];
            return null;
        }
        $this->letzterFastTreffer = null;
        unset($beste['_laenge']);
        $beste['ablage'] = $this->ablage[$beste['favorit']] ?? $beste['favorit'];
        return $beste;
    }

    /** @var array{favorit:string,punkte:int,regel:string}|null */
    private ?array $letzterFastTreffer = null;

    /**
     * Der beste Kandidat des letzten `bestimme()`, der die Schwelle NICHT
     * erreicht hat. Nur unmittelbar danach gueltig.
     *
     * @return array{favorit:string,punkte:int,regel:string}|null
     */
    public function fastTreffer(): ?array
    {
        return $this->letzterFastTreffer;
    }

    public static function schwelle(): int
    {
        return self::SCHWELLE;
    }

    /** @param array{name:string,norm:string,laenge:int} $k */
    private function bewerte(array $k, string $voll, string $basisNorm, string $zusatz): ?array
    {
        $mk = fn(int $p, string $regel, string $z = '') =>
            ['favorit' => $k['name'], 'punkte' => $p, 'regel' => $regel, 'zusatz' => $z];

        if ($voll === $k['norm']) {
            return $mk(self::P_EXAKT, 'exakt');
        }
        if (isset($this->aliase[$voll]) && $this->aliase[$voll] === $k['name']) {
            return $mk(self::P_ALIAS, 'alias');
        }
        if ($zusatz !== '' && $basisNorm === $k['norm']) {
            // Je laenger der Zusatz, desto unsicherer die Zuordnung - aber gedeckelt,
            // sonst faellt "Der Kroatien-Krimi: Jagd auf einen Toten" unter die Schwelle.
            $abzug = min(20, (int) floor(mb_strlen($zusatz) / 6));
            return $mk(self::P_ZUSATZ - $abzug, 'zusatz', $zusatz);
        }
        // Schreibvarianten. Bewusst eng gefasst: bei kurzen Namen darf sich hoechstens
        // ein Zeichen unterscheiden, sonst treffen sich "Bull" und "Bulls".
        $d = levenshtein($voll, $k['norm']);
        if ($d > 0 && $d <= max(1, (int) floor($k['laenge'] * 0.08))) {
            return $mk(self::P_TIPPO, 'variante');
        }
        return null;
    }

    /**
     * Zerlegt einen Titel in Serienname und Zusatz.
     *
     * @return array{0:string,1:string} [Basis, Zusatz]; Zusatz ist leer, wenn keiner da ist
     */
    public static function zerlege(string $titel): array
    {
        if (preg_match(self::TRENNER, $titel, $m, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $m[0][1];
            if ($pos > 0) {
                return [rtrim(mb_substr($titel, 0, mb_strlen(substr($titel, 0, $pos)))),
                        trim(mb_substr($titel, mb_strlen(substr($titel, 0, $pos))))];
            }
        }
        return [$titel, ''];
    }

    /**
     * Vergleichsform eines Namens. Bewusst verlustbehaftet: Gross-/Kleinschreibung,
     * Satzzeichen und Umlaut-Schreibweisen sollen keinen Unterschied machen, die
     * WORTFOLGE dagegen schon. Damit sind "CSI: Miami", "CSI - Miami" und
     * "CSI Miami" derselbe Name. Fuehrende Artikel bleiben stehen: "Der Alte" und
     * "Alte" sind nicht dasselbe, und Sender schreiben Serientitel konsistent.
     */
    public static function normalisiere(string $s): string
    {
        $s = trim($s);
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'auto');
        }
        $s = preg_replace('/[\x00-\x1F\x7F\xA0]/u', '', $s) ?? '';
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ñ' => 'n', 'ç' => 'c', 'å' => 'a', 'ø' => 'o',
            '&' => ' und ', '’' => '', '`' => '', "'" => '',
        ]);
        // Satzzeichen zu Leerzeichen; Ziffern bleiben (Staffel-/Teilangaben).
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        // Buchstabierte Abkuerzungen wieder zusammenziehen. Der Schritt davor macht aus
        // "Navy CIS: L.A." ein "navy cis l a", waehrend der Favorit "Navy CIS LA" als
        // "navy cis la" ankommt - dieselbe Serie, zwei Schreibweisen. Betroffen sind nur
        // Folgen von mindestens zwei EINZELNEN Buchstaben, echte Woerter bleiben unberuehrt.
        return preg_replace_callback(
            '/(?<![\p{L}\p{N}])(\p{L}(?:\s\p{L})+)(?![\p{L}\p{N}])/u',
            static fn(array $m): string => str_replace(' ', '', $m[1]),
            $s
        ) ?? $s;
    }
}
