# SymconSeriesRecorder

Serienrecorder als IP-Symcon-Modul. Loest die Skript-Fassung
(`44702.ips.php` + `PHPSerienRecorder.php`) schrittweise ab.

## Stand

Phase 1 von 4. Enthalten ist bisher der **Titel-Resolver** - der Teil, in dem
die Skript-Fassung real Sendungen verlor.

## Warum ein Resolver statt eines Namensvergleichs

Die alte Fassung fragte fuer ein Paar aus Titel und Favorit "passen die
zueinander?". Diese Frage ist isoliert nicht entscheidbar:

    "Walking on Sunshine (S03/E05)"  -> Episodenzusatz, gehoert zum Favoriten
    "Navy CIS: Hawaii"               -> eigener Ableger, gehoert NICHT zu "Navy CIS"

Beide Male folgt dem Basisnamen ein Zusatz. Wer nur das Paar sieht, muss raten,
und die alte Fassung entschied sich fuer harte Ausschluesse ("andere Wortanzahl
-> nein"), die reale Sendungen verwarfen.

Der Resolver bewertet stattdessen den ganzen Favoritenbestand gegen den Titel;
der beste Kandidat gewinnt. Der Ablegerfall loest sich damit von selbst: fuer
"Navy CIS: Hawaii" bietet der gleichnamige Favorit die exakte Uebereinstimmung
(100 Punkte) und schlaegt "Navy CIS", das nur ueber die Zusatz-Regel (80)
hereinkaeme.

## Favoritenname und Ablagename sind zweierlei

In der Wunschliste steht "CSI: Miami", die Aufnahmen liegen unter "CSI Miami".
Die alte Override-Tabelle erledigte beides in einem Schritt. Hier ist es
getrennt (`setAblagenamen()`), denn sonst wandern beim Umstieg die
Aufnahmepfade - und die Duplikaterkennung findet ihre eigenen Dateien nicht
mehr wieder.

## Tests

    php tests/TitelResolverTest.php    # 26 Faelle, ohne IP-Symcon lauffaehig
    php tests/echtvergleich3.php       # Alt/Neu gegen die echten Daten

Der Echtvergleich ist der wichtigere: er stellt die Zuordnung des laufenden
Altsystems Sendung fuer Sendung (Kanal + Startzeit) der neuen gegenueber. Ein
Umstieg ist erst vertretbar, wenn dort "NICHT MEHR" und "ANDERS" auf null
stehen.

## Sender: warum fuenf Stufen

Das XMLTV und die Empfangsliste des Receivers schreiben denselben Sender
verschieden. Die Skript-Fassung hatte dafuer eine Tabelle mit 27 Paaren; sie
verglich gegen den XMLTV-Anzeigenamen und traf ihn oft nicht. Der Mapper
probiert der Reihe nach:

    tabelle              ausdrueckliche Zuordnung
    exakt                gleiche Vergleichsform
    ohne Zusatz          HD/SD/DE/AT weg          "Kabel 1 DE"   -> kabel eins HD
    kanonisch            Zahlwoerter als Ziffern  "Pro 7 Maxx"   -> Pro7 MAXX HD
    verdichtet           ohne Leerzeichen         "ZDF Info DE"  -> ZDFinfo HD
    namensanfang         Praefix                  "Anixe DE"     -> AnixeHD Serie

Die letzten drei Stufen greifen NUR, wenn genau ein Empfangskanal passt. "One"
ist Anfang von "ONE HD" und von "One Terra HD" - dort waere jede Wahl geraten,
also gibt es keinen Treffer und einen Eintrag im Protokoll. Ein falsch
zugeordneter Sender nimmt die falsche Sendung auf; das ist teurer als eine
verpasste Folge.

Ergebnis gegen die echten Daten: 57 von 63 XMLTV-Sendern zugeordnet. Die
uebrigen sechs sind Spartenkanaele und ORF 3, die der Receiver nicht kennt.

## Stand des Vergleichs (20.08.2026)

Zeitfenster 20.-26.08., derselbe Datenbestand:

    Altsystem   415 Sendungen
    Modul       444 Sendungen
    Verlust       0
    zusaetzlich  29   (Walking on Sunshine 22, Law & Order 4, Kroatien-Krimi 2,
                       Barcelona-Krimi 2, Barbie 1)

## Abdeckung gegenueber der Skript-Fassung

Die alte Fassung hat 284 Methoden in 15 Klassen. Was davon im Modul steckt:

| Bereich                     | alt | Stand |
|-----------------------------|----:|-------|
| Matching (Titel/Sender)     |  23 | neu gebaut, ersetzt |
| XMLTV lesen                 |  15 | Lesen/Parsen ja, Download und Vorfilter-Datei nein |
| Wunschliste (Web)           |  15 | offen - das Modul liest die Datei, die das Altsystem schreibt |
| Episoden-Logik              |  35 | offen |
| TVDB-Anreicherung           |  18 | offen (kapseln, nicht neu schreiben) |
| Aufnahmen-Bestand (Platte)  |  31 | offen (kapseln) |
| Receiver-API (Enigma)       |  60 | offen (kapseln) |
| Duplikate                   |   5 | offen |
| Orchestrierung              |  82 | teilweise, faellt groesstenteils weg |

Das Modul beantwortet heute **"was laeuft?"**. Es beantwortet noch NICHT
"habe ich das schon?", "programmiere das" und "raeum auf" - genau dafuer sind
die Phasen 2 und 3 da.

Wichtig fuer den Parallellauf: das Altsystem entscheidet je Ausstrahlung
zusaetzlich Vorhanden / Mehrfach / Programmiert / Unklar. Solange das Modul das
nicht kann, vergleicht man nur die Trefferliste, nicht die Entscheidung.

## Phase 2a: die Entscheidung

Der Lauf sagt jetzt nicht nur "was laeuft", sondern auch **was fehlt**. Vier
Urteile, und die Reihenfolge der Pruefungen ist die eigentliche Aussage:

    1. schon einmal in dieser Liste?  -> mehrfach   (nur die erste zaehlt)
    2. liegt auf der Platte?          -> vorhanden
    3. weder Nummer noch Titel?       -> unklar
    4. sonst                          -> aufnehmen

Punkt 1 vor Punkt 2 war beim ersten Anlauf vertauscht. Fachlich fuehren beide
zu "nicht aufnehmen", aber die Zahlen waeren nicht mehr mit dem Altsystem
vergleichbar gewesen - und genau darauf beruht der Parallellauf.

Der Bestand wird ueber ZWEI Schluessel gesucht, Nummer und Episodentitel. Das
ist kein Luxus: fuer 14 der 417 Ausstrahlungen liefert das EPG kein S/E, und
Tatort fuehrt im Bestand die Staffel als JAHR ("S2023E26") - ueber Nummern ist
dort nichts wiederzufinden.

## Vergleich der Entscheidungen (417 Ausstrahlungen des Altsystems)

    Etikett gleich:            388 von 413 (93,9 %)
    nur ALT wuerde aufnehmen:    1
    nur NEU wuerde aufnehmen:    7

Die eine Aufnahme, die nur das Altsystem machen wuerde, ist **Tatort "Liebe
mich"** - die Folge liegt laengst auf der Platte
(`Tatort - S2022E07 - Faber - 22 - Liebe mich!.ts`). Das Altsystem findet sie
nicht und wuerde sie ein zweites Mal aufzeichnen.

Von den sieben, die nur das Modul aufnehmen wuerde, sind drei bereits am
Receiver programmiert (das weiss nur der Receiver - Phase 3) und vier sind
Tatorte, die die Bedingungsregel des Altsystems ohnehin verwirft. Die Regeln
selbst fehlen dem Modul noch.

Die uebrigen 24 Etikett-Abweichungen sind ein Sortier-Unterschied: bei zwei
Ausstrahlungen derselben Folge nennt das Altsystem die in SEINER
Verarbeitungsreihenfolge erste "vorhanden", das Modul die zeitlich fruehere.
Beide Etiketten bedeuten "nicht aufnehmen".

## Woher Staffel und Folge kommen

Eine Kette, billigste Quelle zuerst:

    1. Episodenkatalog   Dateiablage, 193 Serien / 16.565 Episoden   kostenlos
    2. TheTVDB           nur wenn freigegeben                        500 ms je Anfrage

Der TVDB-Handler der Skript-Fassung ist unveraendert uebernommen
(`libs/fremd/`, siehe HERKUNFT.md) - er ruft keine IPS-Funktion auf und braucht
von seiner Umgebung nur eine `log()`-Methode.

**Der Netzzugriff ist ein hartes Gate, kein Schalter im Inneren.** Ohne
Freigabe wird die TVDB-Quelle gar nicht erst gebaut. Der naheliegende Weg -
die Klasse im "Nur-Cache-Modus" zu betreiben - waere eine Falle: ihr Einstieg
`enrichBroadcastFromCache()` traegt zwar den Namen, ruft aber `searchSeries()`,
und das geht bei unbekannter Serie ueber `apiRequest('/search/series')` doch
ins Netz.

Antworten werden je Lauf gemerkt: eine Serie, die zwanzigmal in der Woche
laeuft, kostet eine Abfrage statt zwanzig. Dazu ein Deckel je Lauf, denn die
Klasse wartet 500 ms zwischen zwei Anfragen und bis zu 30 s auf Antwort.

TMDB ist bewusst draussen. Im Altsystem ist es der Ausweichweg hinter TVDB;
sein Cache umfasst 61 Eintraege gegenueber 419. Es kommt erst dazu, wenn sich
zeigt, dass TVDB allein Luecken laesst.
