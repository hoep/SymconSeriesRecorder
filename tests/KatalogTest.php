<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Entscheidung.php';
use Hoep\SeriesRecorder\{Bedingungen, Bestand, Entscheidung, Episodenkatalog};

$d='/var/lib/symcon/serienrecorder/';
$k=new Episodenkatalog($d);
printf("Katalog: %d Serien, %d Episoden\n\n", $k->serien(), $k->episoden());

foreach ([['Tatort','Aus dem Dunkel'],['Tatort','Liebe mich'],['Tatort','Trotzdem'],
          ['Die Chefin','Alte Freunde'],['Nordlicht – Mörder ohne Reue','Der Teufel von Split']] as [$s,$t]) {
    $r=$k->finde($s,$t);
    printf("  %-28s %-24s -> %s\n", $s, '"'.$t.'"', $r ? sprintf('S%04dE%02d',$r['staffel'],$r['folge']) : '(nicht im Katalog)');
}

$best=new Bestand($d.'recordings.txt');
$bed=new Bedingungen([
    ['serie'=>'Tatort','feld'=>'season','op'=>'>=','wert'=>2024],
    ['serie'=>'Tatort','feld'=>'episode','op'=>'>','wert'=>0],
]);
$bw=json_decode(preg_replace('/^\xEF\xBB\xBF/','',file_get_contents($d.'favorites_with_broadcasts.json')),true);
$alle=[]; foreach($bw as $e) foreach(($e['broadcasts']??[]) as $x) $alle[]=$x;
usort($alle, fn($a,$b)=>$a['start_timestamp']<=>$b['start_timestamp']);

foreach ([['ohne Katalog',null],['mit Katalog',$k]] as [$was,$kat]) {
    $en=new Entscheidung($best,$bed,$kat); $en->beginneLauf();
    $stat=[]; $tatort=[];
    foreach($alle as $x){
        $u=$en->fuer(['serie'=>(string)$x['favorite_name'],'titel'=>(string)$x['title'],
                      'untertitel'=>(string)($x['episodeName']??''),'folgeNum'=>(string)($x['episode-num']??'')]);
        $stat[$u['urteil']]=($stat[$u['urteil']]??0)+1;
        if ($x['favorite_name']==='Tatort')
            $tatort[]=sprintf('S%04dE%-4d %-22s %-14s [%s]',$u['staffel'],$u['folge'],
                mb_strimwidth((string)($x['episodeName']??''),0,22,'…'),$u['urteil'],$u['quelle']?:'-');
    }
    printf("\n%-13s %s\n", $was.':', json_encode($stat,JSON_UNESCAPED_UNICODE));
    foreach(array_slice($tatort,0,6) as $z) echo "   $z\n";
}

// --- Klammerzusaetze: EPG zaehlt Teile, der Katalog nennt das Jahr -----------
// Gefunden am 22.08.2026 an einem echten Timer, der als S00E00 auf der Box stand.
$k2 = new Episodenkatalog('/var/lib/symcon/serienrecorder/');
if ($k2->serien() > 0) {
    $f = $k2->finde('Death in Paradise', 'Weihnachtsmänner in Gefahr (2)');
    printf("  [%s] %-52s ist=%s soll=S00E07\n",
        ($f && $f['staffel'] === 0 && $f['folge'] === 7) ? 'ok' : 'FEHLER',
        'Klammerzusatz (2) trifft Katalogtitel (2024)',
        $f ? sprintf('S%02dE%02d', $f['staffel'], $f['folge']) : 'nichts');
    $g = $k2->finde('Death in Paradise', 'Maria');
    printf("  [%s] %-52s ist=%s soll=S14E01\n",
        ($g && $g['staffel'] === 14 && $g['folge'] === 1) ? 'ok' : 'FEHLER',
        'gewoehnlicher Titel weiterhin exakt',
        $g ? sprintf('S%02dE%02d', $g['staffel'], $g['folge']) : 'nichts');
    // Kein Ratespiel: ein anderes Jahr ist eine andere Folge.
    $h = $k2->finde('Death in Paradise', 'Christmas Special 2024');
    printf("  [%s] %-52s ist=%s soll=nichts\n", $h === null ? 'ok' : 'FEHLER',
        'anderes Jahr wird NICHT verwechselt', $h ? sprintf('S%02dE%02d', $h['staffel'], $h['folge']) : 'nichts');
}
