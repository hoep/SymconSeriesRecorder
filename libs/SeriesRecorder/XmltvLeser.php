<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Liest eine XMLTV-Datei sendungsweise.
 *
 * Die Datei ist rund 24 MB gross; sie am Stueck zu parsen kostet ein Vielfaches
 * davon an Speicher. Deshalb XMLReader im Streaming - die Skript-Fassung macht
 * das an dieser Stelle bereits richtig, das Verfahren ist uebernommen.
 *
 * Rueckgabe sind einfache Arrays statt Objekte: sie gehen unveraendert in die
 * JSON-Variablen, die die Oberflaeche anzeigt.
 */
final class XmltvLeser
{
    public function __construct(private string $datei)
    {
    }

    public function vorhanden(): bool
    {
        return is_file($this->datei) && is_readable($this->datei);
    }

    public function alter(): ?int
    {
        $t = @filemtime($this->datei);
        return $t === false ? null : (time() - $t);
    }

    /**
     * Sender der Datei: XMLTV-Kanal-ID => Anzeigename.
     *
     * @return array<string,string>
     */
    public function sender(): array
    {
        $out = [];
        $r = new \XMLReader();
        if (!@$r->open($this->datei)) {
            return $out;
        }
        while (@$r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($r->name === 'channel') {
                $id = (string) $r->getAttribute('id');
                $xml = $r->readInnerXml();
                if ($id !== '' && preg_match('/<display-name[^>]*>(.*?)<\/display-name>/s', $xml, $m)) {
                    $out[$id] = self::text($m[1]);
                }
            } elseif ($r->name === 'programme') {
                break;  // Sender stehen vor den Sendungen - ab hier ist nichts mehr zu holen
            }
        }
        $r->close();
        return $out;
    }

    /**
     * Sendungen im Zeitfenster.
     *
     * @param int $von Unix-Zeit, ab wann (0 = ohne Untergrenze)
     * @param int $bis Unix-Zeit, bis wann (0 = ohne Obergrenze)
     * @return \Generator<int,array{kanal:string,titel:string,untertitel:string,start:int,ende:int,folge:string,beschreibung:string}>
     */
    public function sendungen(int $von = 0, int $bis = 0): \Generator
    {
        $r = new \XMLReader();
        if (!@$r->open($this->datei)) {
            return;
        }
        while (@$r->read()) {
            if ($r->nodeType !== \XMLReader::ELEMENT || $r->name !== 'programme') {
                continue;
            }
            $start = self::zeit((string) $r->getAttribute('start'));
            $ende  = self::zeit((string) $r->getAttribute('stop'));
            if ($start === null) {
                continue;
            }
            if (($von > 0 && $start < $von) || ($bis > 0 && $start > $bis)) {
                // Trotzdem weiterlesen: XMLTV-Dateien sind nach Sender gruppiert,
                // nicht durchgehend nach Zeit - ein Abbruch verloere die spaeteren Sender.
                continue;
            }
            $xml = $r->readInnerXml();
            $titel = preg_match('/<title[^>]*>(.*?)<\/title>/s', $xml, $m) ? self::text($m[1]) : '';
            if ($titel === '') {
                continue;
            }
            yield [
                'kanal'         => (string) $r->getAttribute('channel'),
                'titel'         => $titel,
                'untertitel'    => preg_match('/<sub-title[^>]*>(.*?)<\/sub-title>/s', $xml, $m) ? self::text($m[1]) : '',
                'start'         => $start,
                'ende'          => $ende ?? 0,
                'folge'         => preg_match('/<episode-num[^>]*>(.*?)<\/episode-num>/s', $xml, $m) ? self::text($m[1]) : '',
                'beschreibung'  => preg_match('/<desc[^>]*>(.*?)<\/desc>/s', $xml, $m) ? self::text($m[1]) : '',
            ];
        }
        $r->close();
    }

    /** XMLTV-Zeitstempel "20260821222000 +0200". */
    private static function zeit(string $s): ?int
    {
        if (!preg_match('/^(\d{14})(?:\s*([+-]\d{4}))?/', trim($s), $m)) {
            return null;
        }
        $t = strtotime($m[1] . (isset($m[2]) ? ' ' . $m[2] : ''));
        return $t === false ? null : $t;
    }

    private static function text(string $s): string
    {
        return trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
