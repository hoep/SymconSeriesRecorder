<?php
declare(strict_types=1);
require __DIR__.'/../libs/SeriesRecorder/Dateiname.php';
require __DIR__.'/../libs/SeriesRecorder/Bestand.php';
use Hoep\SeriesRecorder\Dateiname;
use Hoep\SeriesRecorder\Bestand;

$faelle = [
    // roh                                   soll                              warum
    ['Navy CIS: Sydney',                     'Navy CIS Sydney',                'der Fall, der die komischen Zeichen erzeugte: Doppelpunkt wird auf SMB zu U+F022'],
    ['CSI: Miami',                           'CSI Miami',                      'so heisst der Ordner auf der Platte seit jeher'],
    ['Navy CIS: L.A.',                       'Navy CIS L.A',                   'Doppelpunkt raus, Punkt am Ende raus'],
    ['Magnum P.I.',                          'Magnum P.I',                     'Windows schneidet den Punkt ohnehin ab - hier bewusst, damit nicht zwei Ordner entstehen'],
    ['Was wäre wenn...',                     'Was wäre wenn',                  'drei Punkte am Ende sind dasselbe Problem'],
    ['Wer ist Alan?',                        'Wer ist Alan',                   'Fragezeichen ist auf NTFS verboten'],
    ['Tod/Leben',                            'Tod Leben',                      'Schraegstrich wird LEERZEICHEN, nicht ersatzlos gestrichen'],
    ['Bereit für ein Spiel?',                'Bereit für ein Spiel',           'Umlaut bleibt, Fragezeichen faellt'],
    ['Law & Order: Special Victims Unit',    'Law & Order Special Victims Unit', 'kaufmaennisches Und ist erlaubt'],
    ['  Doppelte   Leerzeichen  ',           'Doppelte Leerzeichen',           'Leerzeichen fallen zusammen'],
    ['Er sagte "Nein"',                      'Er sagte Nein',                  'Anfuehrungszeichen sind verboten'],
    ['a<b>c|d*e',                            'a b c d e',                      'alle uebrigen verbotenen Zeichen'],
    ["Zeile\tmit\nUmbruch",                  'Zeile mit Umbruch',              'Steuerzeichen'],
    ["Geschütztes\u{00A0}Leerzeichen",       'Geschütztes Leerzeichen',        'unsichtbares Leerzeug'],
    ['NUL',                                  'NUL_',                           'reservierter Geraetename'],
    ['nul.ts',                               'nul.ts_',                        'auch mit Endung reserviert'],
    ['Normale Serie',                        'Normale Serie',                  'unveraendert, wenn nichts zu tun ist'],
    [':::',                                  '',                               'bleibt nichts uebrig - der Aufrufer muss das behandeln'],
    ['',                                     '',                               'leer bleibt leer'],
];
$ok=0; $fehl=[];
foreach ($faelle as [$roh,$soll,$warum]) {
    $ist = Dateiname::sicher($roh);
    if ($ist === $soll) { $ok++; printf("  ok    %-38s -> %s\n", '['.$roh.']', '['.$ist.']'); }
    else { $fehl[] = "[$roh]: erwartet [$soll], bekommen [$ist]  ($warum)";
           printf("  FEHL  %-38s -> %-30s erwartet %s\n", '['.$roh.']', '['.$ist.']', '['.$soll.']'); }
}

// Laengengrenze
$lang = str_repeat('a', 300);
if (mb_strlen(Dateiname::sicher($lang)) === Dateiname::GRENZE) { $ok++; echo "  ok    Laengengrenze greift bei ".Dateiname::GRENZE."\n"; }
else { $fehl[] = 'Laengengrenze greift nicht'; echo "  FEHL  Laengengrenze\n"; }

// DER entscheidende Punkt: der Bestand muss die alten Aufnahmen weiterhin finden.
// Bestand::form() wirft Satzzeichen ohnehin weg, also vergleichen sich alter
// Ordnername und gesaeuberter Ablagename gleich.
echo "\nBestand findet die vorhandenen Aufnahmen trotz Saeuberung wieder:\n";
$paare = [
    ['Navy CIS: Sydney', 'Navy CIS Sydney'],
    ['Magnum P.I.',      'Magnum P.I'],
    ['CSI: Miami',       'CSI Miami'],
];
foreach ($paare as [$alt,$neu]) {
    $gleich = Bestand::form($alt) === Bestand::form($neu);
    if ($gleich) { $ok++; printf("  ok    [%s] und [%s] vergleichen sich gleich\n", $alt, $neu); }
    else { $fehl[] = "$alt / $neu vergleichen sich NICHT gleich"; printf("  FEHL  [%s] / [%s]\n", $alt, $neu); }
}

printf("\n%d bestanden, %d gescheitert\n", $ok, count($fehl));
foreach ($fehl as $f) echo "  - $f\n";
exit($fehl === [] ? 0 : 1);
