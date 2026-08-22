<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

require_once __DIR__ . '/Bestand.php';

/**
 * Von Hand vergebene Kennungen bei TheTVDB und TMDB.
 *
 * Wozu: Die Seriensuche von TheTVDB v3 ist tot (gemessen: /search/series
 * antwortet mit 404). Fuer eine Serie, die noch in keiner Ablage steht, gibt es
 * damit KEINEN Weg, an Staffel und Folge zu kommen - der uebernommene Handler
 * beginnt jede Anreicherung mit der Suche und scheitert dort sofort. Bei TMDB
 * ist die Suche zwar am Leben, findet aber nicht jede deutsche Schreibweise.
 *
 * Beides hat dieselbe Loesung: die Kennung einmal von Hand nachsehen und
 * eintragen. Damit sie auch WIRKT, wird sie nicht nebenher verwaltet, sondern
 * genau dort abgelegt, wo die Handler ihre Suchergebnisse erwarten:
 *
 *     tvdb/series_search_<md5(Serie)>.json   {"id":…, "seriesName":…}
 *     tmdb/search_<md5(Serie)>.json          {"id":…, "name":…}
 *
 * So findet der Handler die Serie ueber seinen eigenen Weg und ueberspringt die
 * Suche - ohne dass eine fremde Klasse angefasst werden muss. Dieselben Dateien
 * liest auch die Verweisliste, die Verlinkung stimmt also automatisch mit.
 *
 * Die Dateien werden bei JEDEM Lauf neu geschrieben. Der Handler haelt sie nur
 * eine begrenzte Zeit fuer gueltig (Vorgabe 168 Stunden) und wuerde danach doch
 * wieder suchen - eine Handeintragung darf aber nicht nach einer Woche verfallen.
 */
final class Katalogzuordnung
{
    /**
     * @return list<array{serie:string,tvdb:int,tmdb:int}>
     */
    public static function ausJson(string $json): array
    {
        $out = [];
        foreach ((array) (json_decode($json, true) ?: []) as $z) {
            if (!is_array($z)) {
                continue;
            }
            $serie = trim((string) ($z['serie'] ?? ''));
            if ($serie === '') {
                continue;
            }
            $out[] = ['serie' => $serie, 'tvdb' => (int) ($z['tvdb'] ?? 0), 'tmdb' => (int) ($z['tmdb'] ?? 0)];
        }
        return $out;
    }

    /**
     * Nachschlagewerk fuer die Verweisliste.
     *
     * @param list<array{serie:string,tvdb:int,tmdb:int}> $tabelle
     * @return array<string,array{tvdb:int,tmdb:int}> Vergleichsform => Kennungen
     */
    public static function karte(array $tabelle): array
    {
        $out = [];
        foreach ($tabelle as $z) {
            $k = Bestand::form($z['serie']);
            if ($k !== '') {
                $out[$k] = ['tvdb' => $z['tvdb'], 'tmdb' => $z['tmdb']];
            }
        }
        return $out;
    }

    /**
     * Die Eintragungen in die Ablagen schreiben, aus denen die Handler lesen.
     *
     * @param list<array{serie:string,tvdb:int,tmdb:int}> $tabelle
     * @return array{tvdb:int,tmdb:int,fehler:list<string>}
     */
    public static function schreibe(string $datenpfad, array $tabelle): array
    {
        $basis = rtrim($datenpfad, '/');
        $n = ['tvdb' => 0, 'tmdb' => 0];
        $fehler = [];

        foreach ($tabelle as $z) {
            if ($z['tvdb'] > 0) {
                $ok = self::datei($basis . '/tvdb', 'series_search_' . md5($z['serie']) . '.json', [
                    'id'         => $z['tvdb'],
                    'seriesName' => $z['serie'],
                    'slug'       => '',
                    'aliases'    => [],
                    // Kennzeichen, damit man einer Datei ansieht, dass sie nicht
                    // von einer Suche stammt.
                    'quelle'     => 'Serienrecorder-Handeintrag',
                ]);
                $ok ? $n['tvdb']++ : $fehler[] = 'TheTVDB: ' . $z['serie'];
            }
            if ($z['tmdb'] > 0) {
                $ok = self::datei($basis . '/tmdb', 'search_' . md5($z['serie']) . '.json', [
                    'id'            => $z['tmdb'],
                    'name'          => $z['serie'],
                    'original_name' => $z['serie'],
                    'match_type'    => 'manuell',
                    'match_score'   => 100,
                    'quelle'        => 'Serienrecorder-Handeintrag',
                ]);
                $ok ? $n['tmdb']++ : $fehler[] = 'TMDB: ' . $z['serie'];
            }
        }
        return ['tvdb' => $n['tvdb'], 'tmdb' => $n['tmdb'], 'fehler' => $fehler];
    }

    /** @param array<string,mixed> $inhalt */
    private static function datei(string $ordner, string $name, array $inhalt): bool
    {
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true)) {
            return false;
        }
        $roh = json_encode($inhalt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($roh === false) {
            return false;
        }
        // Nur schreiben, wenn sich etwas aendert - sonst aber die Uhrzeit
        // auffrischen, damit der Handler die Eintragung weiter fuer gueltig haelt.
        $ziel = $ordner . '/' . $name;
        if (is_file($ziel) && (string) @file_get_contents($ziel) === $roh) {
            return @touch($ziel);
        }
        return @file_put_contents($ziel, $roh) !== false;
    }
}
