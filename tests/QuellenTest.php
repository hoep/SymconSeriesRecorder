<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Quellenkette.php';
require __DIR__.'/../libs/SeriesRecorder/Episodenkatalog.php';
require __DIR__.'/../libs/SeriesRecorder/TvdbQuelle.php';
use Hoep\SeriesRecorder\{Episodenkatalog, Quellenkette, TvdbQuelle};

$d='/var/lib/symcon/serienrecorder/';
$kat=new Episodenkatalog($d);

// 1. Kette mit nur dem Katalog: darf NIE eine Verbindung aufbauen
$kette=new Quellenkette($kat);
$t=$kette->finde('Tatort','Trotzdem');
printf("Katalog allein: Tatort/Trotzdem -> %s\n", $t?sprintf('S%dE%d',$t['staffel'],$t['folge']):'(nichts)');
printf("  %s\n", $kette->bericht());

// 2. TvdbQuelle ohne Schluessel bleibt stumm - und baut keinen Handler
$leer=new TvdbQuelle($d.'tvdb','',5);
printf("\nOhne Schluessel einsatzbereit? %s -> finde() = %s\n",
    $leer->einsatzbereit()?'ja':'nein', var_export($leer->finde('Irgendwas','Folge'),true));
printf("  %s\n", $leer->bericht());

// 3. Deckel: nach N Abfragen wird uebersprungen (ohne Schluessel zaehlt nichts hoch,
//    deshalb hier nur die Zusicherung pruefen, dass der Zaehler existiert)
printf("\nKette Katalog+TVDB(ohne Schluessel): %s\n", (new Quellenkette($kat,$leer))->bericht());

// 4. Merker: dieselbe Frage zweimal darf die Quelle nur einmal belasten
class Zaehlquelle implements Hoep\SeriesRecorder\EpisodenQuelle {
    public int $n=0;
    public function finde(string $s, string $t): ?array { $this->n++; return null; }
    public function bericht(): string { return 'Zaehlquelle: '.$this->n.' Anfragen'; }
}
$z=new Zaehlquelle(); $k2=new Quellenkette($z);
for($i=0;$i<5;$i++) $k2->finde('Serie X','Folge Y');
printf("\n5x dieselbe Frage -> %s (erwartet: 1)\n", $z->bericht());
