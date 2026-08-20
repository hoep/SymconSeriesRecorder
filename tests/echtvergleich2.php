<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/TitelResolver.php';
use Hoep\SeriesRecorder\TitelResolver;

$favJson = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites.xml')),true);
$favoriten = array_map(fn($f)=>$f['name'], $favJson['favorites']);
$src = file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all("/\['From'\s*=>\s*[\"'](.+?)[\"']\s*,\s*'To'\s*=>\s*[\"'](.+?)[\"']\]/u", $src, $m, PREG_SET_ORDER);
$aliase = []; foreach ($m as $x) { $aliase[$x[1]] = $x[2]; }
$r = new TitelResolver($favoriten, $aliase);

// ALT: Sendung (Kanal|Start) -> Favorit, so wie der Recorder sie zugeordnet hat
$bw = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites_with_broadcasts.json')),true);
$alt = []; $kanaele = []; $vonZeit = PHP_INT_MAX; $bisZeit = 0;
foreach ($bw as $e) {
    foreach (($e['broadcasts'] ?? []) as $b) {
        $k = trim((string)$b['channel']).'|'.trim((string)$b['start']);
        $alt[$k] = $e['favorite']['name'];
        $kanaele[trim((string)$b['channel'])] = true;
        $ts = (int)($b['start_timestamp'] ?? 0);
        if ($ts > 0) { $vonZeit = min($vonZeit,$ts); $bisZeit = max($bisZeit,$ts); }
    }
}
printf("Vergleichsraum: %d Kanaele, %s bis %s\n", count($kanaele), date('d.m. H:i',$vonZeit), date('d.m. H:i',$bisZeit));

// NEU: dieselben Kanaele und dasselbe Zeitfenster aus dem vollen XMLTV
$xml = file_get_contents('/var/lib/symcon/serienrecorder/xmltv.xml');
preg_match_all('/<programme\s+channel="([^"]+)"\s+start="([^"]+)"[^>]*>(.*?)<\/programme>/s', $xml, $pm, PREG_SET_ORDER);
$neu = []; $betrachtet = 0;
foreach ($pm as $p) {
    [$ganz,$kanal,$start,$inhalt] = $p;
    if (!isset($kanaele[$kanal])) { continue; }
    $ts = strtotime(substr($start,0,14).' '.substr($start,15));
    if ($ts === false || $ts < $vonZeit || $ts > $bisZeit) { continue; }
    if (!preg_match('/<title[^>]*>(.*?)<\/title>/s',$inhalt,$tm)) { continue; }
    $titel = html_entity_decode(trim($tm[1]), ENT_QUOTES|ENT_XML1, 'UTF-8');
    $betrachtet++;
    $t = $r->bestimme($titel);
    if ($t !== null) { $neu[$kanal.'|'.$start] = [$t['favorit'],$titel,$t['regel'],$t['punkte']]; }
}
printf("Im Vergleichsraum: %d Sendungen  |  alt zugeordnet: %d  |  neu zugeordnet: %d\n\n", $betrachtet, count($alt), count($neu));

$plus = []; $minus = []; $anders = [];
foreach ($neu as $k => [$f,$titel,$reg,$p]) {
    if (!isset($alt[$k])) { $plus[] = [$titel,$f,$reg,$p]; }
    elseif ($alt[$k] !== $f) { $anders[] = [$titel,$alt[$k],$f,$reg]; }
}
foreach ($alt as $k => $f) { if (!isset($neu[$k])) { $minus[] = [$k,$f]; } }

$z = function(array $l) { $c=[]; foreach ($l as $x) { $key=$x[0].' -> '.$x[1]; $c[$key]=($c[$key]??0)+1; } arsort($c); return $c; };
printf("=== ZUSAETZLICH programmiert: %d Sendungen\n", count($plus));
foreach ($z($plus) as $k=>$n) printf("  %3dx  %s\n", $n, $k);
printf("\n=== NICHT MEHR zugeordnet: %d Sendungen\n", count($minus));
$c=[]; foreach ($minus as [$k,$f]) { $c[$f]=($c[$f]??0)+1; } arsort($c);
foreach ($c as $f=>$n) printf("  %3dx  %s\n", $n, $f);
printf("\n=== ANDERS zugeordnet: %d Sendungen\n", count($anders));
foreach ($z(array_map(fn($a)=>[$a[0],$a[1].' => '.$a[2]],$anders)) as $k=>$n) printf("  %3dx  %s\n", $n, $k);
