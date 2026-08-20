<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Analyse.php';
use Hoep\SeriesRecorder\{Analyse, XmltvLeser};

$d='/var/lib/symcon/serienrecorder/';
$fav = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites.xml')),true);
$favoriten = array_map(fn($f)=>$f['name'], $fav['favorites']);
$ch = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'channels.json')),true);
$empfang = array_map(fn($c)=>$c['name'], $ch['channels']);
$src = file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all("/\['From'\s*=>\s*[\"'](.+?)[\"']\s*,\s*'To'\s*=>\s*[\"'](.+?)[\"']\]/u", $src, $m, PREG_SET_ORDER);
$aliase=[]; $ablage=[]; foreach ($m as $x) { $aliase[$x[1]]=$x[2]; $ablage[$x[1]]=$x[2]; }
preg_match_all('/^\s*"([^"]+)"\s*=>\s*"([^"]+)",?\s*$/mu', $src, $km, PREG_SET_ORDER);
$kanaltab=[]; foreach ($km as $x) { $kanaltab[$x[1]]=$x[2]; }

$leser = new XmltvLeser($d.'xmltv.xml');
printf("XMLTV vorhanden: %s, Alter %.1f h\n", $leser->vorhanden()?'ja':'nein', ($leser->alter()??0)/3600);
$a = new Analyse($favoriten,$aliase,$ablage,$empfang,$kanaltab);
$speicherVor = memory_get_peak_usage(true);
$e = $a->lauf($leser);
printf("Dauer %d ms, Spitzenspeicher %.1f MB\n", $e['dauerMs'], memory_get_peak_usage(true)/1048576);
print_r($e['kennzahlen']);
printf("\nSender ohne Empfang (Top 8):\n");
foreach (array_slice($e['offeneSender'],0,8) as $s) echo "   $s\n";
printf("\nErste 6 Treffer:\n");
foreach (array_slice($e['sendungen'],0,6) as $s)
    printf("  %s %s  %-28s %-30s %s [%s]\n", date('d.m.',$s['start']), date('H:i',$s['start']),
        $s['serie'], mb_strimwidth($s['titel'],0,30,'…'), $s['sender'], $s['regel']);
$tab = Analyse::alsTabelle($e['sendungen']);
printf("\nTabelle: %d Zeilen, JSON %d KB\n", count($tab)-1, (int)(strlen(json_encode($tab,JSON_UNESCAPED_UNICODE))/1024));
