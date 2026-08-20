<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Holt die XMLTV-Datei vom Anbieter.
 *
 * Bewusst schlank statt den 1330-Zeilen-Handler des Altsystems mitzunehmen:
 * dessen Hauptaufgabe ist das Vorfiltern auf die Favoriten, und genau das
 * braucht dieses Modul nicht - sein Leser geht die volle Datei im Streaming
 * durch. Was aus dem Original uebernommen wurde, sind die drei Erfahrungen,
 * die dort in Kommentaren stehen: manche Anbieter liefern einen zerschossenen
 * XML-Kopf, nicht jede Datei ist UTF-8, und bei 24 MB lohnt der Umweg ueber
 * eine temporaere Datei.
 *
 * Geschrieben wird IMMER erst daneben und dann umbenannt. Ein Lauf, der
 * waehrend des Downloads liest, wuerde sonst auf eine halbe Datei treffen -
 * und ein Umzug innerhalb desselben Verzeichnisses ist unteilbar.
 *
 * Die Zieldatei ist absichtlich eine ANDERE als die des Altsystems. Solange
 * beide laufen, wuerden sie sich sonst gegenseitig die Datei unter den Fuessen
 * wegschreiben.
 */
final class XmltvBezug
{
    public function __construct(
        private string $url,
        private string $ziel,
        private int $timeout = 120,
    ) {
    }

    /**
     * @return array{ok:bool,groesse:int,dauerMs:int,meldung:string}
     */
    public function hole(): array
    {
        $t0 = microtime(true);
        $fertig = fn(bool $ok, string $meldung, int $groesse = 0) => [
            'ok' => $ok, 'groesse' => $groesse,
            'dauerMs' => (int) round((microtime(true) - $t0) * 1000), 'meldung' => $meldung,
        ];

        if ($this->url === '' || $this->ziel === '') {
            return $fertig(false, 'URL oder Zieldatei fehlt');
        }
        if (!function_exists('curl_init')) {
            return $fertig(false, 'curl steht nicht zur Verfuegung');
        }
        $ordner = dirname($this->ziel);
        if (!is_dir($ordner) || !is_writable($ordner)) {
            return $fertig(false, 'Verzeichnis nicht beschreibbar: ' . $ordner);
        }

        $temp = $this->ziel . '.teil';
        $fp = @fopen($temp, 'w');
        if ($fp === false) {
            return $fertig(false, 'Kann nicht schreiben: ' . $temp);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->url,
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 20,
            // Der Anbieter liefert gzip, wenn man es anbietet - bei 24 MB ist das
            // der Unterschied zwischen Sekunden und einer Minute.
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'SymconSeriesRecorder/1.0',
        ]);
        $ok = curl_exec($ch);
        $fehler = curl_errno($ch) ? curl_error($ch) : '';
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $fehler !== '') {
            @unlink($temp);
            return $fertig(false, 'Uebertragung fehlgeschlagen: ' . $fehler);
        }
        if ($code !== 200) {
            @unlink($temp);
            return $fertig(false, 'Anbieter antwortete mit HTTP ' . $code);
        }

        $groesse = (int) @filesize($temp);
        // Eine Fehlerseite ist auch eine Antwort. Unter 100 KB kann keine
        // Programmvorschau stecken - lieber die alte Datei behalten.
        if ($groesse < 100 * 1024) {
            @unlink($temp);
            return $fertig(false, 'Antwort zu klein (' . $groesse . ' Byte) - alte Datei bleibt');
        }
        if (!self::sieht_nach_xmltv_aus($temp)) {
            @unlink($temp);
            return $fertig(false, 'Antwort ist kein XMLTV - alte Datei bleibt');
        }

        if (!@rename($temp, $this->ziel)) {
            @unlink($temp);
            return $fertig(false, 'Konnte nicht an die Stelle der alten Datei ruecken');
        }
        return $fertig(true, 'geladen', $groesse);
    }

    /**
     * Prueft den Anfang der Datei. Ein zerschossener XML-Kopf ("?xml" ohne
     * spitze Klammer) kommt bei einem der Anbieter regelmaessig vor und ist
     * kein Grund, die Datei zu verwerfen - dafuer gibt es die Reparatur.
     */
    private static function sieht_nach_xmltv_aus(string $datei): bool
    {
        $fp = @fopen($datei, 'r');
        if ($fp === false) {
            return false;
        }
        $kopf = (string) fread($fp, 4096);
        fclose($fp);
        if (str_starts_with(ltrim($kopf), '?xml')) {
            self::repariereKopf($datei);
            $kopf = '<' . ltrim($kopf);
        }
        return str_contains($kopf, '<tv') || str_contains($kopf, '<?xml');
    }

    private static function repariereKopf(string $datei): void
    {
        $inhalt = @file_get_contents($datei);
        if ($inhalt === false) {
            return;
        }
        @file_put_contents($datei, preg_replace('/^\s*\?xml/', '<?xml', $inhalt, 1));
    }
}
