<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/KanalMapper.php';
use Hoep\SeriesRecorder\KanalMapper;
$d='/var/lib/symcon/serienrecorder/';
$ch=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'channels.json')),true);
$empfang=array_map(fn($c)=>$c['name'],$ch['channels']);
$src=file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all('/^\s*"([^"]+)"\s*=>\s*"([^"]+)",?\s*$/mu',$src,$km,PREG_SET_ORDER);
$tab=[]; foreach($km as $x) $tab[$x[1]]=$x[2];
$k=new KanalMapper($empfang,$tab);
$xml=file_get_contents($d.'xmltv.xml');
preg_match_all('/<channel id="([^"]+)">\s*<display-name[^>]*>([^<]*)</s',$xml,$cm,PREG_SET_ORDER);
$g=[];
foreach($cm as $c){ $n=trim($c[2]); $t=$k->finde($n); $g[$t['regel'] ?? '(kein Treffer)'][]=[$n,$t['kanal'] ?? '-']; }
foreach($g as $regel=>$l){ printf("--- %s (%d)\n",$regel,count($l)); foreach($l as [$a,$b]) printf("    %-22s -> %s\n",$a,$b); }
