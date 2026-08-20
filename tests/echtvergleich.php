<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/TitelResolver.php';
use Hoep\SeriesRecorder\TitelResolver;

$favJson = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites.xml')),true);
$favoriten = array_map(fn($f)=>$f['name'], $favJson['favorites']);

// Alias-Tabelle aus den Override-Regeln des Altsystems (From => To)
$src = file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all("/\['From'\s*=>\s*[\"'](.+?)[\"']\s*,\s*'To'\s*=>\s*[\"'](.+?)[\"']\]/u", $src, $m, PREG_SET_ORDER);
$aliase = [];
foreach ($m as $x) { $aliase[$x[1]] = $x[2]; }

$r = new TitelResolver($favoriten, $aliase);

// ALLE Titel aus dem vollen XMLTV
$xml = file_get_contents('/var/lib/symcon/serienrecorder/xmltv.xml');
preg_match_all('/<title[^>]*>(.*?)<\/title>/s', $xml, $tm);
$anzahl = [];
foreach ($tm[1] as $t) { $t = html_entity_decode(trim($t), ENT_QUOTES|ENT_XML1, 'UTF-8'); if ($t!=='') { $anzahl[$t] = ($anzahl[$t] ?? 0) + 1; } }

// Alt: was der Recorder tatsaechlich zugeordnet hat
$bw = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/favorites_with_broadcasts.json')),true);
$altTitelZuFav = [];
foreach ($bw as $e) {
    foreach (($e['broadcasts'] ?? []) as $b) {
        $altTitelZuFav[trim((string)($b['title'] ?? ''))] = $e['favorite']['name'];
    }
}

$neuTreffer = 0; $neuTitel = 0; $regeln = [];
$gewonnen = []; $verloren = []; $anders = [];
foreach ($anzahl as $titel => $n) { $titel = (string) $titel;
    $t = $r->bestimme($titel);
    $neu = $t['favorit'] ?? null;
    $alt = $altTitelZuFav[$titel] ?? null;
    if ($neu !== null) { $neuTreffer += $n; $neuTitel++; $regeln[$t['regel']] = ($regeln[$t['regel']] ?? 0) + 1; }
    if ($neu !== null && $alt === null)      { $gewonnen[] = [$titel,$neu,$n,$t['regel'],$t['punkte']]; }
    elseif ($neu === null && $alt !== null)  { $verloren[] = [$titel,$alt,$n]; }
    elseif ($neu !== null && $alt !== null && $neu !== $alt) { $anders[] = [$titel,$alt,$neu,$n,$t['regel']]; }
}
printf("XMLTV: %d verschiedene Titel, %d Sendungen gesamt\n", count($anzahl), array_sum($anzahl));
printf("Neu zugeordnet: %d Titel / %d Sendungen   Regeln: %s\n\n", $neuTitel, $neuTreffer, json_encode($regeln));

usort($gewonnen, fn($a,$b)=>$b[2]<=>$a[2]);
printf("=== ZUSAETZLICH gefunden (%d Titel, %d Sendungen)\n", count($gewonnen), array_sum(array_column($gewonnen,2)));
foreach ($gewonnen as [$t,$f,$n,$reg,$p]) printf("  %3dx  %-46s -> %-32s [%s %d]\n", $n, mb_strimwidth($t,0,46,'…'), $f, $reg, $p);

printf("\n=== NICHT mehr gefunden (%d Titel, %d Sendungen)\n", count($verloren), array_sum(array_column($verloren,2)));
foreach ($verloren as [$t,$f,$n]) printf("  %3dx  %-46s war: %s\n", $n, mb_strimwidth($t,0,46,'…'), $f);

printf("\n=== ANDERS zugeordnet (%d)\n", count($anders));
foreach ($anders as [$t,$a,$nn,$n,$reg]) printf("  %3dx  %-42s  %s  ->  %s  [%s]\n", $n, mb_strimwidth($t,0,42,'…'), $a, $nn, $reg);
