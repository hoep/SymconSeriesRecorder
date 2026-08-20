<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Analyse.php';
use Hoep\SeriesRecorder\{Analyse, XmltvLeser};
$d='/var/lib/symcon/serienrecorder/';
$fav=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites.xml')),true);
$favoriten=array_map(fn($f)=>$f['name'],$fav['favorites']);
$ch=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'channels.json')),true);
$empfang=array_map(fn($c)=>$c['name'],$ch['channels']);
$src=file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all("/\['From'\s*=>\s*[\"'](.+?)[\"']\s*,\s*'To'\s*=>\s*[\"'](.+?)[\"']\]/u",$src,$m,PREG_SET_ORDER);
$aliase=[];$ablage=[]; foreach($m as $x){$aliase[$x[1]]=$x[2];$ablage[$x[1]]=$x[2];}
preg_match_all('/^\s*"([^"]+)"\s*=>\s*"([^"]+)",?\s*$/mu',$src,$km,PREG_SET_ORDER);
$kanaltab=[]; foreach($km as $x) $kanaltab[$x[1]]=$x[2];

// Alt: Sendung (Serie|Startzeit) -> so wie der Recorder sie kennt
$bw=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites_with_broadcasts.json')),true);
$alt=[]; $von=PHP_INT_MAX; $bis=0;
foreach($bw as $e) foreach(($e['broadcasts']??[]) as $b){
    $ts=(int)($b['start_timestamp']??0); if(!$ts) continue;
    $alt[$e['favorite']['name'].'|'.$ts]=true; $von=min($von,$ts); $bis=max($bis,$ts);
}
$a=new Analyse($favoriten,$aliase,$ablage,$empfang,$kanaltab);
$e=$a->lauf(new XmltvLeser($d.'xmltv.xml'), $von, $bis);
$neu=[]; foreach($e['sendungen'] as $s) $neu[$s['serie'].'|'.$s['start']]=$s;

printf("Zeitfenster %s bis %s\n", date('d.m. H:i',$von), date('d.m. H:i',$bis));
printf("alt: %d Sendungen   neu: %d Sendungen\n\n", count($alt), count($neu));
$plus=[];$minus=[];
foreach($neu as $k=>$s) if(!isset($alt[$k])) $plus[]=sprintf('%-30s %s %s (%s)',$s['serie'],date('d.m. H:i',$s['start']),$s['sender'],$s['regel']);
foreach($alt as $k=>$x) if(!isset($neu[$k])) { [$serie,$ts]=explode('|',$k); $minus[]=sprintf('%-30s %s',$serie,date('d.m. H:i',(int)$ts)); }
printf("=== NUR NEU: %d\n",count($plus)); foreach(array_slice($plus,0,40) as $x) echo "   $x\n";
printf("\n=== NUR ALT (Verlust): %d\n",count($minus)); foreach(array_slice($minus,0,40) as $x) echo "   $x\n";
