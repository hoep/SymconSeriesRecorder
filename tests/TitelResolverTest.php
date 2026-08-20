<?php

declare(strict_types=1);

/**
 * Testfaelle fuer den Titel-Resolver. Laeuft ohne IP-Symcon:
 *   php tests/TitelResolverTest.php
 *
 * Die Faelle sind aus echten Daten gezogen: aus favorites.xml, aus dem XMLTV und
 * aus den Override-Regeln des Altsystems. Jeder Fall haelt fest, WARUM er hier
 * steht - die Sammlung ist die Begruendung fuer die Regeln, nicht nur ihr Nachweis.
 */

require __DIR__ . '/../libs/SeriesRecorder/TitelResolver.php';

use Hoep\SeriesRecorder\TitelResolver;

$favoriten = [
    'CSI Miami', 'CSI: Miami', 'CSI', 'CSI Vegas', 'CSI New York',
    'Navy CIS', 'Navy CIS Hawaii', 'Navy CIS LA', 'Navy CIS Origins',
    'Walking on Sunshine', 'Der Kroatien-Krimi', 'Der Barcelona-Krimi',
    'Grill den Henssler', 'Tatort', 'Sherlock', 'Elementary', 'Bull',
    'Bones - Die Knochenjägerin', 'Blind ermittelt', 'Der Wien-Krimi: Blind ermittelt',
    'Hawaii Five-0', 'SOKO Wien', 'Law & Order Special Victims Unit',
    'Die Chefin', 'Der Alte', 'Monk', 'Instinct', 'The Irrational',
];

$aliase = [
    'Law & Order: SVU'                => 'Law & Order Special Victims Unit',
    'Instinct - Auf Mörderjagd'       => 'Instinct',
    'The Irrational - Kriminell logisch' => 'The Irrational',
];

/** @var list<array{0:string,1:?string,2:string}> [Titel, erwarteter Favorit oder null, Begruendung] */
$faelle = [
    // --- Was heute schon geht und weiter gehen muss -------------------------
    ['CSI Miami',            'CSI Miami',       'unveraenderter Normalfall'],
    ['CSI: Miami',           'CSI Miami',       'Doppelpunkt-Schreibweise; beide Favoriten normalisieren gleich, laengerer gewinnt'],
    ['Tatort',               'Tatort',          'einfacher Titel'],
    ['Bones - Die Knochenjägerin', 'Bones - Die Knochenjägerin', 'Favorit enthaelt selbst einen Trenner - exakter Treffer muss vorgehen'],
    ['Hawaii Five-0',        'Hawaii Five-0',   'Bindestrich OHNE Leerzeichen ist kein Trenner'],

    // --- Die heute verlorenen Sendungen -------------------------------------
    ['Walking on Sunshine (S03/E05)',            'Walking on Sunshine', '26 Sendungen, heute verworfen (Laengendifferenz > 10)'],
    ['Der Kroatien-Krimi: Jagd auf einen Toten', 'Der Kroatien-Krimi',  'Untertitel im XMLTV-Titel'],
    ['Der Barcelona-Krimi: Absturz',             'Der Barcelona-Krimi', 'Untertitel im XMLTV-Titel'],
    ['Tatort: Aus dem Dunkel',                   'Tatort',              'Folgentitel hinter Doppelpunkt'],
    ['Die Chefin, Teil 2',                       'Die Chefin',          'Mehrteiler-Angabe'],

    // --- Ableger duerfen NICHT beim Stammtitel landen -----------------------
    ['Navy CIS: Hawaii',   'Navy CIS Hawaii',   'eigener Ableger, exakter Treffer schlaegt Zusatz-Regel'],
    ['Navy CIS: L.A.',     'Navy CIS LA',       'Punkte in der Abkuerzung'],
    ['Navy CIS: Origins',  'Navy CIS Origins',  'eigener Ableger'],
    ['CSI: Vegas',         'CSI Vegas',         'Ableger, nicht das Stamm-CSI'],
    ['CSI: New York',      'CSI New York',      'Ableger, nicht das Stamm-CSI'],

    // --- Fremde Serien, die zufaellig so anfangen ---------------------------
    ['Sherlock Yack - Der Zoodetektiv', null, 'andere Serie; Praefix ohne Trenner darf nicht greifen'],
    ['Abbott Elementary',               null, 'Favorit steht am ENDE, nicht am Anfang'],
    ['Bulletproof',                     null, 'darf nicht auf "Bull" fallen'],
    ['Der Alte und das Meer',           null, 'kein Trenner nach "Der Alte"'],
    ['Instinct',                        'Instinct', 'kurzer Favorit, exakt'],

    // --- Alias-Tabelle ------------------------------------------------------
    ['Law & Order: SVU',                   'Law & Order Special Victims Unit', 'Alias schlaegt die Zusatz-Regel'],
    ['Instinct - Auf Mörderjagd',          'Instinct',       'Alias; die Zusatz-Regel haette dasselbe geliefert'],
    ['The Irrational - Kriminell logisch', 'The Irrational', 'Alias'],

    // --- Bewusste Grenzen ---------------------------------------------------
    ['Grill den Henssler Sommer-Special', null, 'Anhang OHNE Trenner wird nicht geraten - dafuer ist die Alias-Tabelle da'],
    ['',                                  null, 'leerer Titel'],
    ['Nachrichten',                       null, 'kein Favorit'],
];

$r = new TitelResolver($favoriten, $aliase);
$ok = 0;
$fehler = [];

foreach ($faelle as [$titel, $erwartet, $warum]) {
    $t = $r->bestimme($titel);
    $ist = $t['favorit'] ?? null;
    if ($ist === $erwartet) {
        $ok++;
        printf("  ok    %-42s -> %-34s %s\n", '"' . $titel . '"', $ist ?? '(keiner)',
            isset($t['regel']) ? '[' . $t['regel'] . ' ' . $t['punkte'] . ']' : '');
    } else {
        $fehler[] = sprintf('"%s": erwartet %s, bekommen %s   (%s)',
            $titel, $erwartet ?? '(keiner)', $ist ?? '(keiner)', $warum);
        printf("  FEHL  %-42s -> %-34s erwartet: %s\n", '"' . $titel . '"',
            $ist ?? '(keiner)', $erwartet ?? '(keiner)');
    }
}

printf("\n%d von %d Faellen bestanden\n", $ok, count($faelle));
foreach ($fehler as $f) {
    echo "  - $f\n";
}
exit($fehler === [] ? 0 : 1);
