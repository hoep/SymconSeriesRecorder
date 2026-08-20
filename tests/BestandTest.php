<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Bestand.php';
require __DIR__.'/../libs/SeriesRecorder/EpisodenNummer.php';
use Hoep\SeriesRecorder\{Bestand, EpisodenNummer};

$d='/var/lib/symcon/serienrecorder/';
$b=new Bestand($d.'recordings.txt');
printf("Bestand: %d Aufnahmen, %d Serien\n\n", $b->anzahl(), $b->serien());

// --- EpisodenNummer: Faelle aus den echten Daten
$faelle=[
    ['0.4.',        '', '', 1, 5, 'xmltv_ns nullbasiert'],
    ['.1244.',      '', '', 0, 1244, 'Tatort zaehlt durch, ohne Staffel'],
    ['S03/E05',     '', '', 3, 5, 'onscreen'],
    ['',  'Walking on Sunshine (S04/E02)', '', 4, 2, 'Nummer steckt im Titel'],
    ['',  '', '3x07', 3, 7, 'im Untertitel'],
    ['',            '', '', 0, 0, 'nichts da - darf nicht geraten werden'],
];
$ok=0;
foreach ($faelle as [$en,$t,$u,$ss,$ff,$warum]) {
    $r=EpisodenNummer::bestimme($en,$t,$u);
    $gut=($r['staffel']===$ss && $r['folge']===$ff);
    $ok+=$gut?1:0;
    printf("  %-5s %-30s -> S%02dE%02d %-9s %s\n", $gut?'ok':'FEHL',
        '"'.($en?:$t?:$u).'"', $r['staffel'], $r['folge'], '['.$r['quelle'].']', $warum);
}
printf("  %d/%d Nummern-Faelle\n\n", $ok, count($faelle));

// --- Entscheidung gegen das Altsystem
$bw=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites_with_broadcasts.json')),true);
$alle=[]; foreach ($bw as $e) foreach (($e['broadcasts']??[]) as $x) $alle[]=$x;

// "Mehrfach": dieselbe Folge laeuft im Zeitraum mehrmals - nur die erste ist die Aufnahme
$gesehen=[];
$stat=['V'=>0,'D'=>0,'J'=>0,'?'=>0];
$treffer=0; $abw=[];
usort($alle, fn($a,$b)=>$a['start_timestamp']<=>$b['start_timestamp']);
foreach ($alle as $x) {
    $serie=(string)$x['favorite_name'];
    $n=EpisodenNummer::bestimme((string)($x['episode-num']??''), (string)$x['title'], (string)($x['episodeName']??''));
    $st=$n['staffel']?:(int)($x['season']??0);
    $fo=$n['folge']?:(int)($x['episode']??0);
    $eptitel=(string)($x['episodeName']??'');

    $meine='J';
    if ($st===0 && $fo===0 && $eptitel==='') { $meine='?'; }
    else {
        $s=$b->suche($serie,$st,$fo,$eptitel);
        if ($s['da']) { $meine='V'; }
        else {
            $k=$serie.'|'.$st.'|'.$fo.'|'.mb_strtolower($eptitel);
            if (isset($gesehen[$k])) { $meine='D'; } else { $gesehen[$k]=true; $meine='J'; }
        }
    }
    $stat[$meine]++;
    $alt=(string)$x['recordingDecision'];
    if ($alt==='P') continue;                       // Timer existiert schon - das weiss nur der Receiver
    if ($alt===$meine) $treffer++;
    else $abw[]=sprintf('%-26s S%02dE%02d %-28s alt=%s neu=%s', mb_strimwidth($serie,0,26,'…'), $st,$fo, mb_strimwidth($eptitel,0,28,'…'), $alt,$meine);
}
$vergleichbar=count($alle)-4;
printf("Entscheidung: %d von %d gleich (%.1f%%)\n", $treffer,$vergleichbar,$treffer/$vergleichbar*100);
printf("meine Verteilung: %s\n", json_encode($stat));
printf("\nAbweichungen: %d\n", count($abw));
foreach (array_slice($abw,0,25) as $a) echo "   $a\n";
