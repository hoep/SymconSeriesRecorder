<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Ein Sendungstitel ist kein Dateiname.
 *
 * Titel kommen aus dem EPG, aus der Wunschliste und aus TheTVDB - drei Quellen,
 * die sich um Dateisysteme nicht kuemmern. "Navy CIS: Sydney" landete so als
 * Ordner auf der Aufnahmefreigabe, und ein Doppelpunkt ist auf SMB/NTFS nicht
 * erlaubt: der Linux-Client bildet ihn per `mapposix` auf U+F022 ab. Lokal sieht
 * der Name dann richtig aus, auf der NAS-Oberflaeche und in Plex steht ein
 * Kaestchen. Genau das waren die "komischen Zeichen".
 *
 * Verboten sind auf SMB/NTFS:  \ / : * ? " < > |  sowie alle Steuerzeichen.
 * Dazu kommen zwei stille Fallen:
 *
 *   - Punkt oder Leerzeichen am ENDE schneidet Windows wortlos ab. "Magnum P.I."
 *     wurde dadurch zu "Magnum P.I", waehrend derselbe Titel an anderer Stelle
 *     "Magnum PI" ergab. Beide Ordner liegen heute nebeneinander auf der Platte.
 *   - CON, PRN, AUX, NUL, COM1..9 und LPT1..9 sind reservierte Geraetenamen und
 *     als Datei- oder Ordnername nicht zu gebrauchen.
 *
 * ERSETZT WIRD DURCH EIN LEERZEICHEN, nicht ersatzlos gestrichen: aus
 * "Tod/Leben" wuerde sonst "TodLeben". Mehrfache Leerzeichen fallen danach
 * zusammen, deshalb wird aus "CSI: Miami" das gewohnte "CSI Miami".
 *
 * WICHTIG fuer den Bestand: der Vergleich laeuft ueber Bestand::form(), und die
 * wirft Satzzeichen ohnehin weg. Eine gesaeuberte Ablage findet ihre alten
 * Aufnahmen also weiterhin - nur der ORDNER heisst kuenftig anders, und der alte
 * muss von Hand mitgenommen werden, sonst stehen zwei nebeneinander.
 */
final class Dateiname
{
    /** Auf SMB/NTFS nicht erlaubt. */
    private const VERBOTEN = ['\\', '/', ':', '*', '?', '"', '<', '>', '|'];

    /** Reservierte Geraetenamen; als Namensbestandteil unbrauchbar. */
    private const GERAETE = [
        'CON', 'PRN', 'AUX', 'NUL',
        'COM1', 'COM2', 'COM3', 'COM4', 'COM5', 'COM6', 'COM7', 'COM8', 'COM9',
        'LPT1', 'LPT2', 'LPT3', 'LPT4', 'LPT5', 'LPT6', 'LPT7', 'LPT8', 'LPT9',
    ];

    /**
     * Obergrenze fuer einen Namensbestandteil.
     *
     * ext4 und NTFS lassen 255 Zeichen zu, der laengste Dateiname auf der
     * Freigabe misst 130. Die Grenze ist also ein Fangnetz gegen einen entgleisten
     * EPG-Eintrag und kein Formatierungsmittel - sie greift bei echten Titeln nie.
     */
    public const GRENZE = 200;

    /**
     * Einen Titel so herrichten, dass er als Datei- oder Ordnername taugt.
     *
     * Gibt einen leeren String zurueck, wenn vom Titel nichts uebrig bleibt.
     * Der Aufrufer muss diesen Fall behandeln - stillschweigend "unbenannt" zu
     * erfinden hiesse, Aufnahmen in einem Sammelordner zu verstecken.
     */
    public static function sicher(string $roh, int $grenze = self::GRENZE): string
    {
        $s = trim($roh);
        if ($s === '') {
            return '';
        }
        // Nicht jede Quelle liefert UTF-8. Ein als Latin-1 durchgereichtes "ä"
        // steht sonst als rohes 0xE4 im Dateinamen - so entstand im Datenordner
        // die Datei "Tim M<E4>lzer kocht!.json".
        if (!mb_check_encoding($s, 'UTF-8')) {
            $s = mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
        }
        // Steuerzeichen und unsichtbares Leerzeug (geschuetztes Leerzeichen,
        // schmales Leerzeichen, Nullbreiten, BOM) auf ein normales Leerzeichen.
        $s = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s);
        $s = (string) preg_replace('/[\x{00A0}\x{2000}-\x{200F}\x{202F}\x{205F}\x{2060}\x{FEFF}]+/u', ' ', $s);
        $s = str_replace(self::VERBOTEN, ' ', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        // Punkt und Leerzeichen am Ende - siehe Klassenkopf.
        $s = rtrim($s, " \t.");
        if ($s === '') {
            return '';
        }
        if ($grenze > 0 && mb_strlen($s, 'UTF-8') > $grenze) {
            $s = rtrim(mb_substr($s, 0, $grenze, 'UTF-8'), " \t.");
        }
        // Geraetenamen: der Vergleich gilt auch mit Endung ("NUL.ts"), deshalb
        // wird der Teil vor dem ersten Punkt geprueft.
        $vorn = strtoupper((string) strstr($s . '.', '.', true));
        if (in_array($vorn, self::GERAETE, true)) {
            $s .= '_';
        }
        return $s;
    }

    /**
     * Hat das Saeubern etwas veraendert?
     *
     * Dafuer da, dass der Lauf es MELDEN kann. Ein still umbenannter Ordner ist
     * genau die Art Aenderung, die man drei Monate spaeter als "wo sind die
     * Aufnahmen hin" wiedersieht.
     */
    public static function veraendert(string $roh): bool
    {
        return self::sicher($roh) !== trim($roh);
    }
}
