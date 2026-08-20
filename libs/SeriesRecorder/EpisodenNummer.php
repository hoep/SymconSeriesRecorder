<?php

declare(strict_types=1);

namespace Hoep\SeriesRecorder;

/**
 * Staffel und Folge aus dem, was das EPG hergibt.
 *
 * XMLTV liefert die Nummer in mehreren Dialekten, teils im Titel, teils im Feld
 * episode-num, teils gar nicht:
 *
 *     "0.4."          xmltv_ns:  nullbasiert, Staffel 1 Folge 5
 *     ".1244."        xmltv_ns:  nur eine laufende Nummer, keine Staffel
 *     "S03/E05"       onscreen
 *     "3x05"          onscreen
 *     "(S04/E02)"     im Titel, hinter dem Serienamen
 *
 * Was hier NICHT passiert: raten. Wenn nichts Verlaessliches dasteht, kommt
 * (0,0) zurueck und die Ausstrahlung gilt als unklar. Eine erfundene Nummer
 * waere schlimmer als keine - sie wuerde eine fremde Folge als vorhanden
 * markieren und die richtige nie aufnehmen.
 */
final class EpisodenNummer
{
    /**
     * @return array{staffel:int,folge:int,quelle:string}
     *         quelle: 'xmltv_ns', 'onscreen', 'titel', 'lauf' oder '' (nichts gefunden)
     */
    public static function bestimme(string $episodeNum, string $titel = '', string $untertitel = ''): array
    {
        $e = trim($episodeNum);

        // xmltv_ns: "Staffel . Folge . Teil", nullbasiert, Teile duerfen fehlen.
        if ($e !== '' && preg_match('/^\s*(\d*)\s*\.\s*(\d*)(?:\s*\/\s*\d+)?\s*\.\s*(\d*)/', $e, $m)) {
            $st = $m[1] !== '' ? ((int) $m[1]) + 1 : 0;
            $fo = $m[2] !== '' ? ((int) $m[2]) + 1 : 0;
            if ($st > 0 && $fo > 0) {
                return ['staffel' => $st, 'folge' => $fo, 'quelle' => 'xmltv_ns'];
            }
            // Nur eine laufende Nummer, ohne Staffel (Tatort zaehlt so: ".1244.").
            // Hier NICHT hochzaehlen: die Nullbasierung gilt fuer Staffel/Folge-Paare,
            // eine allein stehende Nummer ist die Nummer, die der Sender meint.
            // Zum Wiedererkennen taugt sie ohnehin nur bedingt - im Bestand fuehrt
            // Tatort die Staffel als JAHR ("S2021E14"), ein Nummernvergleich geht
            // dort ins Leere, und es bleibt der Episodentitel.
            if ($m[1] === '' && $m[2] !== '') {
                return ['staffel' => 0, 'folge' => (int) $m[2], 'quelle' => 'lauf'];
            }
        }

        // onscreen: "S03/E05", "S3E5", "3x05"
        foreach ([$e, $titel, $untertitel] as $i => $quelle) {
            if ($quelle === '') {
                continue;
            }
            if (preg_match('/\bS\s*(\d{1,3})\s*[\/ ]?\s*E\s*(\d{1,3})\b/i', $quelle, $m)
                || preg_match('/\b(\d{1,2})\s*x\s*(\d{1,3})\b/i', $quelle, $m)) {
                return [
                    'staffel' => (int) $m[1],
                    'folge'   => (int) $m[2],
                    'quelle'  => $i === 0 ? 'onscreen' : 'titel',
                ];
            }
        }

        return ['staffel' => 0, 'folge' => 0, 'quelle' => ''];
    }
}
