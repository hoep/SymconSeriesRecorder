<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/KanalMapper.php';
use Hoep\SeriesRecorder\KanalMapper;

// Echte Empfangsliste des Receivers
$ch = json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents('/var/lib/symcon/serienrecorder/channels.json')),true);
$empfang = array_map(fn($c)=>$c['name'], $ch['channels']);
// Tabelle des Altsystems
$src = file_get_contents('/var/lib/symcon/scripts/44702.ips.php');
preg_match_all('/^\s*"([^"]+)"\s*=>\s*"([^"]+)",?\s*$/mu', $src, $m, PREG_SET_ORDER);
$tab = []; foreach ($m as $x) { $tab[$x[1]] = $x[2]; }

$k = new KanalMapper($empfang, $tab);

$faelle = [
    ['One DE',      'ONE HD',        'der Fall, an dem 26 Sendungen haengen: Tabelle sagt "One", XMLTV sagt "One DE"'],
    ['Das Erste',   'Das Erste HD',  'Tabelleneintrag'],
    ['ZDF',         'ZDF HD',        'Tabelleneintrag'],
    ['VOX',         'VOX HD',        'Tabelleneintrag'],
    ['Kabel Eins',  'kabel eins HD', 'Gross-/Kleinschreibung weicht ab'],
    ['ORF 1',       'ORF 1HD',       'Leerzeichen weicht ab'],
    ['3sat',        '3sat HD',       'Zusatz HD'],
    ['Radio Bremen TV', null,        'nicht empfangbar'],
];
$ok=0; $fehl=[];
foreach ($faelle as [$name,$soll,$warum]) {
    $t = $k->finde($name);
    $ist = $t['kanal'] ?? null;
    if ($ist === $soll) { $ok++; printf("  ok    %-20s -> %-16s [%s]\n", $name, $ist ?? '(keiner)', $t['regel'] ?? '-'); }
    else { $fehl[] = "$name: erwartet ".($soll ?? '(keiner)').", bekommen ".($ist ?? '(keiner)')."  ($warum)";
           printf("  FEHL  %-20s -> %-16s erwartet %s\n", $name, $ist ?? '(keiner)', $soll ?? '(keiner)'); }
}
printf("\n%d von %d bestanden\n", $ok, count($faelle));
foreach ($fehl as $f) echo "  - $f\n";

// Vollstaendigkeit: wie viele XMLTV-Sender findet der Mapper?
$xml = file_get_contents('/var/lib/symcon/serienrecorder/xmltv.xml');
preg_match_all('/<channel id="([^"]+)">\s*<display-name[^>]*>([^<]*)</s', $xml, $cm, PREG_SET_ORDER);
$k2 = new KanalMapper($empfang, $tab); $treffer=0;
foreach ($cm as $c) { if ($k2->finde(trim($c[2])) !== null) $treffer++; }
printf("\nXMLTV-Sender: %d, davon empfangbar zugeordnet: %d\n", count($cm), $treffer);
exit($fehl === [] ? 0 : 1);
