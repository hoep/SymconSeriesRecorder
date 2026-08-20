<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Bestandsscan.php';
require __DIR__.'/../libs/SeriesRecorder/Bestand.php';
use Hoep\SeriesRecorder\{Bestandsscan, Bestand};

$ziel='/tmp/claude-1000/-var-lib-symcon-scripts/96896826-f3bf-467a-8b4d-50a329e44bd1/scratchpad/rec-test.txt';
@unlink($ziel);

// 1. Schutz: leeres/fehlendes Verzeichnis darf die alte Liste nicht ersetzen
file_put_contents($ziel, "0|Alt|S01E01|Bestand|/x.ts\n");
$s=new Bestandsscan(['/gibt/es/nicht']);
$r=$s->lauf($ziel);
printf("Fehlendes Verzeichnis: ok=%s, alte Datei noch da=%s\n  %s\n",
    var_export($r['ok'],true), file_exists($ziel)?'ja':'NEIN', $r['meldung']);

// 2. Echter Scan
@unlink($ziel);
$t=microtime(true);
$s=new Bestandsscan(['/mnt/Aufnahmen']);
$r=$s->lauf($ziel, $ziel.'.serien');
printf("\nEchter Scan: %s\n  %d Dateien, %d Serien, %d uebersprungen, %.1f s\n",
    $r['meldung'], $r['dateien'], $r['serien'], $r['uebersprungen'], $r['dauerMs']/1000);

// 3. Vergleich mit der Liste des Altsystems
$alt=new Bestand('/var/lib/symcon/serienrecorder/recordings.txt');
$neu=new Bestand($ziel);
printf("\nAltsystem: %d Aufnahmen, %d Serien\nModul    : %d Aufnahmen, %d Serien\n",
    $alt->anzahl(), $alt->serien(), $neu->anzahl(), $neu->serien());

// 4. Stichprobe: findet die neue Liste dieselben Folgen?
$proben=[['CSI Miami',1,9,'Der Heckenschütze'],['Tatort',0,0,'Liebe mich'],['Bull',6,13,'Beste Freunde']];
foreach ($proben as [$serie,$st,$fo,$titel]) {
    $a=$alt->suche($serie,$st,$fo,$titel); $n=$neu->suche($serie,$st,$fo,$titel);
    printf("  %-12s %-22s alt=%-6s neu=%-6s %s\n", $serie, '"'.$titel.'"',
        $a['da']?$a['weg']:'-', $n['da']?$n['weg']:'-', ($a['da']===$n['da'])?'gleich':'ABWEICHUNG');
}
