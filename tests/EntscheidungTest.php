<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Entscheidung.php';
use Hoep\SeriesRecorder\{Bestand, Entscheidung, EpisodenNummer};

$d='/var/lib/symcon/serienrecorder/';
$b=new Bestand($d.'recordings.txt');
printf("Bestand: %d Aufnahmen, %d Serien\n\n", $b->anzahl(), $b->serien());

$n=[['0.4.','','',1,5],['.1244.','','',0,1244],['S03/E05','','',3,5],
    ['','Walking on Sunshine (S04/E02)','',4,2],['','','3x07',3,7],['','','',0,0]];
$ok=0; foreach($n as [$e,$t,$u,$ss,$ff]){ $r=EpisodenNummer::bestimme($e,$t,$u);
  $g=($r['staffel']===$ss&&$r['folge']===$ff); $ok+=$g?1:0;
  printf("  %-5s %-32s -> S%02dE%02d [%s]\n",$g?'ok':'FEHL','"'.($e?:$t?:$u).'"',$r['staffel'],$r['folge'],$r['quelle']); }
printf("  Nummern: %d/%d\n\n",$ok,count($n));

// Gegen die Entscheidungen des Altsystems
$bw=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites_with_broadcasts.json')),true);
$alle=[]; foreach($bw as $e) foreach(($e['broadcasts']??[]) as $x) $alle[]=$x;
usort($alle, fn($a,$b)=>$a['start_timestamp']<=>$b['start_timestamp']);

$karte=['V'=>Entscheidung::VORHANDEN,'D'=>Entscheidung::MEHRFACH,'J'=>Entscheidung::AUFNEHMEN,'?'=>Entscheidung::UNKLAR];
$en=new Entscheidung($b); $en->beginneLauf();
$treffer=0; $vergleichbar=0; $abw=[]; $stat=[];
foreach($alle as $x){
    $u=$en->fuer(['serie'=>(string)$x['favorite_name'],'titel'=>(string)$x['title'],
                  'untertitel'=>(string)($x['episodeName']??''),'folgeNum'=>(string)($x['episode-num']??'')]);
    $stat[$u['urteil']]=($stat[$u['urteil']]??0)+1;
    $alt=(string)$x['recordingDecision'];
    if ($alt==='P') continue;                    // Timer am Receiver - kann das Modul (noch) nicht wissen
    $vergleichbar++;
    if (($karte[$alt]??'') === $u['urteil']) $treffer++;
    else $abw[]=sprintf('%-24s S%02dE%02d %-26s alt=%-9s neu=%-9s %s',
        mb_strimwidth((string)$x['favorite_name'],0,24,'…'),$u['staffel'],$u['folge'],
        mb_strimwidth((string)($x['episodeName']??''),0,26,'…'),$karte[$alt]??$alt,$u['urteil'],$u['grund']);
}
printf("Uebereinstimmung: %d von %d (%.1f%%)\n", $treffer,$vergleichbar,$treffer/$vergleichbar*100);
printf("Verteilung neu: %s\n", json_encode($stat,JSON_UNESCAPED_UNICODE));
printf("\nAbweichungen im Etikett: %d (Muster siehe unten)\n", count($abw));

// Was zaehlt, ist die HANDLUNG: aufnehmen oder nicht. Vorhanden und Mehrfach
// fuehren beide zu "nicht aufnehmen" - eine vertauschte Reihenfolge zwischen
// diesen beiden aendert am Ergebnis nichts.
$en2=new Entscheidung($b); $en2->beginneLauf();
$nurAlt=[]; $nurNeu=[];
foreach($alle as $x){
    $u=$en2->fuer(["serie"=>(string)$x["favorite_name"],"titel"=>(string)$x["title"],
                   "untertitel"=>(string)($x["episodeName"]??""),"folgeNum"=>(string)($x["episode-num"]??"")]);
    $altNimmt = ((string)$x["recordingDecision"]==="J");
    $neuNimmt = ($u["urteil"]===Entscheidung::AUFNEHMEN);
    $z=sprintf("%-24s S%02dE%02d %-30s [alt=%s]", mb_strimwidth((string)$x["favorite_name"],0,24,"…"),
        $u["staffel"],$u["folge"],mb_strimwidth((string)($x["episodeName"]??""),0,30,"…"),$x["recordingDecision"]);
    if($altNimmt && !$neuNimmt) $nurAlt[]=$z;
    if(!$altNimmt && $neuNimmt) $nurNeu[]=$z;
}
printf("\n=== Handlung: nur ALT wuerde aufnehmen: %d\n", count($nurAlt));
foreach($nurAlt as $z) echo "   $z\n";
printf("\n=== Handlung: nur NEU wuerde aufnehmen: %d\n", count($nurNeu));
foreach($nurNeu as $z) echo "   $z\n";
