<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/TitelResolver.php';
use Hoep\SeriesRecorder\TitelResolver;

$favJson = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites.xml')),true);
$favoriten = array_map(fn($f)=>$f['name'], $favJson['favorites']);
// Override-Tabelle des Altsystems: dient dort zugleich als Matching-Hilfe UND als Umbenennung
$src = file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all("/\['From'\s*=>\s*[\"'](.+?)[\"']\s*,\s*'To'\s*=>\s*[\"'](.+?)[\"']\]/u", $src, $m, PREG_SET_ORDER);
$aliase = []; $ablage = [];
foreach ($m as $x) { $aliase[$x[1]] = $x[2]; $ablage[$x[1]] = $x[2]; }
$r = new TitelResolver($favoriten, $aliase);
$r->setAblagenamen($ablage);

$dop = $r->doppelte();
printf("Doppelte Wunschlisten-Eintraege: %d\n", count($dop));
foreach ($dop as $norm => $namen) printf("   %s\n", implode('  ==  ', $namen));

// Kanalmenge: die tatsaechlich konfigurierten Empfangskanaele (nicht die, wo alt zufaellig Treffer lagen)
$ch = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/channels.json')),true);
$empfang = array_map(fn($c)=>$c['name'], $ch['channels']);
printf("\nEmpfangbare Kanaele laut channels.json: %d\n", count($empfang));

$bw = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites_with_broadcasts.json')),true);
$alt = []; $altKanal = []; $von = PHP_INT_MAX; $bis = 0;
foreach ($bw as $e) foreach (($e['broadcasts'] ?? []) as $b) {
    $alt[trim((string)$b['channel']).'|'.trim((string)$b['start'])] = $e['favorite']['name'];
    $altKanal[trim((string)$b['channel'])] = trim((string)($b['channel_name'] ?? ''));
    $ts = (int)($b['start_timestamp'] ?? 0); if ($ts>0) { $von=min($von,$ts); $bis=max($bis,$ts); }
}
// XMLTV-Kanal-IDs auf die Anzeigenamen abbilden, um "empfangbar" zu pruefen
$xml = file_get_contents('/var/lib/symcon/serienrecorder/xmltv.xml');
preg_match_all('/<channel id="([^"]+)">\s*<display-name[^>]*>([^<]*)</s', $xml, $cm, PREG_SET_ORDER);
$idName = []; foreach ($cm as $c) { $idName[$c[1]] = trim($c[2]); }

preg_match_all('/<programme\s+channel="([^"]+)"\s+start="([^"]+)"[^>]*>(.*?)<\/programme>/s', $xml, $pm, PREG_SET_ORDER);
$neu = []; $ausserhalb = [];
foreach ($pm as [$g,$kanal,$start,$inhalt]) {
    $ts = strtotime(substr($start,0,14).' '.substr($start,15));
    if ($ts === false || $ts < $von || $ts > $bis) continue;
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/s',$inhalt,$tm)) continue;
    $titel = html_entity_decode(trim($tm[1]), ENT_QUOTES|ENT_XML1, 'UTF-8');
    $t = $r->bestimme($titel);
    if ($t === null) continue;
    $k = $kanal.'|'.$start;
    if (isset($altKanal[$kanal])) { $neu[$k] = [$t['ablage'],$titel,$t['regel']]; }
    else { $ausserhalb[] = [$titel,$t['ablage'],$idName[$kanal] ?? $kanal]; }
}
printf("\nZeitfenster %s bis %s\n", date('d.m. H:i',$von), date('d.m. H:i',$bis));
printf("alt zugeordnet: %d   neu zugeordnet (gleiche Kanaele): %d\n\n", count($alt), count($neu));

$plus=[]; $minus=[]; $anders=[];
foreach ($neu as $k=>[$f,$titel,$reg]) {
    if (!isset($alt[$k])) $plus[] = "$titel -> $f  [$reg]";
    elseif ($alt[$k] !== $f) $anders[] = "$titel : {$alt[$k]} => $f";
}
foreach ($alt as $k=>$f) if (!isset($neu[$k])) $minus[] = $f;
$zaehl = function(array $l) { $c=[]; foreach ($l as $x) $c[$x]=($c[$x]??0)+1; arsort($c); return $c; };
printf("=== ZUSAETZLICH: %d Sendungen\n", count($plus));
foreach ($zaehl($plus) as $k=>$n) printf("  %3dx  %s\n",$n,$k);
printf("\n=== NICHT MEHR: %d Sendungen\n", count($minus));
foreach ($zaehl($minus) as $k=>$n) printf("  %3dx  %s\n",$n,$k);
printf("\n=== ANDERS: %d Sendungen\n", count($anders));
foreach ($zaehl($anders) as $k=>$n) printf("  %3dx  %s\n",$n,$k);
printf("\n=== Treffer auf Kanaelen, die das Altsystem NICHT betrachtet: %d\n", count($ausserhalb));
foreach ($zaehl(array_map(fn($a)=>sprintf('%-34s -> %-24s (%s)',mb_strimwidth($a[0],0,34,'…'),$a[1],$a[2]),$ausserhalb)) as $k=>$n) printf("  %3dx  %s\n",$n,$k);
