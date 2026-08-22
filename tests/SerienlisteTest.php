<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Serienliste.php';
use Hoep\SeriesRecorder\Serienliste;

$fehler = 0;
$pruef = function (string $was, $ist, $soll) use (&$fehler): void {
    $ok = $ist === $soll;
    if (!$ok) { $fehler++; }
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FEHL', $was,
        $ok ? '' : ' -> ist '.json_encode($ist, JSON_UNESCAPED_UNICODE).', soll '.json_encode($soll, JSON_UNESCAPED_UNICODE));
};

$t = Serienliste::ausJson('[{"serie":"Tatort","quelle":"wunschliste","aktiv":true},{"serie":" tatort ","quelle":"eigen"},{"serie":"","quelle":"eigen"},{"serie":"Der Geier","quelle":"eigen","aktiv":false}]');
$pruef('Doppelte und Leere fliegen raus', count($t), 2);
$pruef('aktiv fehlt = an', $t[0]['aktiv'], true);
$pruef('nur Aktive kommen zur Zuordnung', Serienliste::namen($t), ['Tatort']);

// Konsolidieren
$k = Serienliste::konsolidiere($t, ['Tatort', 'Allmen']);
$pruef('neuer Name kommt dazu', $k['neu'], ['Allmen']);
$pruef('eigene Zeile bleibt', count($k['tabelle']), 3);
$pruef('geaendert wird gemeldet', $k['geaendert'], true);

$k2 = Serienliste::konsolidiere($k['tabelle'], ['Allmen']);
$pruef('verschwundene Wunschliste faellt raus', $k2['weg'], ['Tatort']);
$k3 = Serienliste::konsolidiere($k2['tabelle'], []);
$pruef('leere Wunschliste aendert NICHTS', $k3['geaendert'], false);
$pruef('leere Wunschliste laesst alles stehen', count($k3['tabelle']), count($k2['tabelle']));

$eigen = Serienliste::ausJson('[{"serie":"Der Geier","quelle":"eigen","aktiv":true}]');
$pruef('eigene Zeile ueberlebt jeden Abgleich', count(Serienliste::konsolidiere($eigen, ['Tatort'])['tabelle']), 2);

// Eintragen und austragen
$e = Serienliste::eintragen([], 'Die Chefin');
$pruef('neu aufgenommen', $e['zustand'], 'neu aufgenommen');
$pruef('kommt als eigen', $e['tabelle'][0]['quelle'], 'eigen');
$pruef('zweimal ist keine zweite Zeile', Serienliste::eintragen($e['tabelle'], 'die chefin')['zustand'], 'stand schon drin');

$a = Serienliste::austragen($e['tabelle'], 'Die Chefin');
$pruef('eigene Zeile verschwindet', $a['tabelle'], []);
$w = Serienliste::austragen([['serie'=>'Tatort','quelle'=>'wunschliste','aktiv'=>true]], 'Tatort');
$pruef('Wunschlisten-Zeile wird nur ausgeschaltet', $w['tabelle'][0]['aktiv'], false);
$pruef('und der naechste Abgleich holt sie NICHT zurueck',
    Serienliste::konsolidiere($w['tabelle'], ['Tatort'])['tabelle'][0]['aktiv'], false);
$pruef('wieder einschalten geht', Serienliste::eintragen($w['tabelle'], 'Tatort')['zustand'], 'wieder eingeschaltet');

echo $fehler ? "\n$fehler Fehler\n" : "\nalles gruen\n";
