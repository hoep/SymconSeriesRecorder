<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Entscheidung.php';
use Hoep\SeriesRecorder\{Bedingungen, Bestand, Entscheidung};

// Die Regel des Altsystems, jetzt als Datenzeilen
$regeln = [
    ['serie'=>'Tatort','feld'=>'season','op'=>'>=','wert'=>2024],
    ['serie'=>'Tatort','feld'=>'episode','op'=>'>','wert'=>0],
];
$b = new Bedingungen($regeln);

$faelle = [
    ['Tatort', 0, 1244, false, 'EPG liefert keine Staffel - genau der Fall, der alle Tatorte verwirft'],
    ['Tatort', 2025, 3,  true,  'neue Staffel, Folge gesetzt'],
    ['Tatort', 2023, 5,  false, 'Staffel zu alt'],
    ['Tatort', 2025, 0,  false, 'Folge fehlt'],
    ['CSI Miami', 1, 9,  true,  'Serie ohne Schranke bleibt unberuehrt'],
    ['tatort',  2025, 3,  true,  'Schreibweise egal'],
];
$ok=0;
foreach ($faelle as [$serie,$st,$fo,$soll,$warum]) {
    $r=$b->pruefe($serie,$st,$fo);
    $gut=($r['erlaubt']===$soll); $ok+=$gut?1:0;
    printf("  %-5s %-12s S%04dE%04d -> %-11s %s\n", $gut?'ok':'FEHL', $serie, $st, $fo,
        $r['erlaubt']?'erlaubt':'verworfen', $gut?$warum:('ERWARTET: '.($soll?'erlaubt':'verworfen')));
}
printf("  %d/%d\n\n", $ok, count($faelle));

// Wirkung auf den echten Lauf
$d='/var/lib/symcon/serienrecorder/';
$best=new Bestand($d.'recordings.txt');
$bw=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites_with_broadcasts.json')),true);
$alle=[]; foreach($bw as $e) foreach(($e['broadcasts']??[]) as $x) $alle[]=$x;
usort($alle, fn($a,$b)=>$a['start_timestamp']<=>$b['start_timestamp']);

foreach ([false,true] as $mitRegeln) {
    $en=new Entscheidung($best, $mitRegeln ? $b : null); $en->beginneLauf();
    $stat=[]; $nimmt=[];
    foreach($alle as $x){
        $u=$en->fuer(['serie'=>(string)$x['favorite_name'],'titel'=>(string)$x['title'],
                      'untertitel'=>(string)($x['episodeName']??''),'folgeNum'=>(string)($x['episode-num']??'')]);
        $stat[$u['urteil']]=($stat[$u['urteil']]??0)+1;
        if ($u['urteil']===Entscheidung::AUFNEHMEN)
            $nimmt[]=sprintf('%s S%02dE%02d %s [alt=%s]',$x['favorite_name'],$u['staffel'],$u['folge'],
                mb_strimwidth((string)($x['episodeName']??''),0,24,'…'),$x['recordingDecision']);
    }
    printf("%s Regeln: %s\n", $mitRegeln?'MIT ':'OHNE', json_encode($stat,JSON_UNESCAPED_UNICODE));
    printf("   wuerde aufnehmen (%d):\n",count($nimmt));
    foreach($nimmt as $z) echo "      $z\n";
}
