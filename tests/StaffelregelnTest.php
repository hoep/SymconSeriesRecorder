<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Staffelregeln.php';
use Hoep\SeriesRecorder\Staffelregeln;

/**
 * Staffel-Berichtigung - die Faelle, die im Altsystem schiefgingen.
 *
 * Aufruf: php tests/StaffelregelnTest.php
 */
$fehler = 0;
$pruefe = function (string $was, mixed $ist, mixed $soll) use (&$fehler): void {
    $ok = $ist === $soll;
    if (!$ok) { $fehler++; }
    printf("  [%s] %-58s ist=%-6s soll=%s\n", $ok ? 'ok' : 'FEHLER', $was,
        var_export($ist, true), var_export($soll, true));
};

$r = new Staffelregeln([
    ['serie' => 'Das Traumschiff',   'von' => '0', 'nach' => 1],
    ['serie' => 'Rosamunde Pilcher', 'von' => '*', 'nach' => 1],
    ['serie' => 'Der Bozen-Krimi',   'von' => '',  'nach' => 1],   // leer = wie *
    ['serie' => 'CSI: Vegas',        'von' => '0', 'nach' => 3],
]);

echo "Grundfaelle\n";
$pruefe('S00 wird zu S01, wenn "von" 0 ist', $r->fuer('Das Traumschiff', 0), 1);
$pruefe('eine echte Staffel bleibt bei "von" 0 unberuehrt', $r->fuer('Das Traumschiff', 4), null);
$pruefe('Platzhalter berichtigt auch echte Staffeln', $r->fuer('Rosamunde Pilcher', 7), 1);
$pruefe('leeres "von" wirkt wie der Platzhalter', $r->fuer('Der Bozen-Krimi', 0), 1);
$pruefe('Serie ohne Regel bleibt, wie sie ist', $r->fuer('Death in Paradise', 0), null);
$pruefe('bereits richtige Staffel meldet keine Aenderung', $r->fuer('Rosamunde Pilcher', 1), null);
$pruefe('Anzahl der Regeln', $r->anzahl(), 4);

echo "\nSchreibweisen - hier scheiterte das Altsystem\n";
// "Rosamund Pilcher" (ohne e) stand in vier der sechs Altskripte und hat nie
// gegriffen; hier faellt es sofort auf, weil die Regel schlicht nicht passt.
$pruefe('Tippfehler in der Regel trifft nicht', $r->fuer('Rosamund Pilcher', 0), null);
$pruefe('Gross/Klein egal', $r->fuer('das traumschiff', 0), 1);
$pruefe('Satzzeichen egal', $r->fuer('CSI Vegas', 0), 3);
$pruefe('Bindestrich egal', $r->fuer('Der Bozen Krimi', 2), 1);

echo "\nGemischte Serien - warum \"von 0\" die sichere Form ist\n";
// Gemessen am 21.08.2026 im XMLTV-Bestand: CSI Vegas hatte 30 Ausstrahlungen MIT
// Staffel und 4 ohne. Ein Platzhalter haette die 30 richtigen platt gemacht.
$pruefe('nur die staffellose Ausstrahlung wird berichtigt', $r->fuer('CSI: Vegas', 0), 3);
$pruefe('die 30 mit echter Staffel bleiben', $r->fuer('CSI: Vegas', 2), null);

echo "\nUnbrauchbare Zeilen werden verworfen\n";
$leer = new Staffelregeln([
    ['serie' => '', 'von' => '0', 'nach' => 1],
    ['serie' => 'X', 'von' => '0', 'nach' => -5],
    ['serie' => 'Y'],
]);
$pruefe('keine gueltige Regel uebrig', $leer->vorhanden(), false);
$pruefe('leere Tabelle bleibt wirkungslos', (new Staffelregeln([]))->fuer('Egal', 0), null);

echo "\n" . ($fehler === 0 ? "alles bestanden\n" : "$fehler Abweichung(en)\n");
exit($fehler === 0 ? 0 : 1);
