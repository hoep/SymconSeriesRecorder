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

## Wohin aufgenommen wird

Zwei Felder, dieselbe Ablage aus zwei Blickwinkeln:

| Feld | Sicht | wofuer |
|---|---|---|
| `ReceiverAufnahmepfad` | Einhaengepunkt am Receiver | geht als `dirname` an den Timer |
| `AufnahmepfadLokal` | Einhaengepunkt in Symcon | dort wird der Ordner angelegt |

Der Timer bekommt **`<Basis>/<Serie>/Season <n>/`**. Die Serie ist der
*Ablagename* (nicht der Favoritenname - "CSI: Miami" heisst auf der Platte
"CSI Miami"), die Staffel kommt aus der Nummer; ohne bekannte Staffel `Season 0`.
Genau so hat es das Altskript getan, und genau so sucht der Bestandsscan.

Enigma legt ein unbekanntes Aufnahmeverzeichnis **nicht** selbst an - der Timer
entsteht, die Aufnahme scheitert. Deshalb wird der Ordner vorher ueber den
Symcon-Einhaengepunkt erzeugt, aber nur unterhalb des eingestellten Pfades und
nur, wenn dieser existiert. Ob das Paar zusammenpasst, steht als ausgerechnetes
Beispiel im Formular.

Leerzeichen im Pfad sind unkritisch: `http_build_query` kodiert sie, und die Box
liest sie unveraendert zurueck (gemessen am 22.08.2026 mit
`/mnt/net/VUAufnahmen/02 - Filme Sabina`).

## Welche Serien aufgenommen werden

Die Eigenschaft **Serienliste** ist der Master. Jede Zeile hat drei Angaben:
Name, Herkunft (`wunschliste` oder `eigen`) und den Schalter *Aufnehmen*.

Die Wunschliste von wunschliste.de wird bei jedem Bezug **hinein konsolidiert**:
neue Namen kommen als `wunschliste` dazu, dort geloeschte fallen wieder heraus.
Zeilen der Herkunft `eigen` fasst der Abgleich nie an. Eine leere Antwort der
Webseite aendert gar nichts - sie bedeutet fast immer, dass die Anmeldung nicht
durchging, und wuerde sonst die ganze Planung leeren.

Damit haengt der Betrieb nicht mehr an einer fremden Anmeldestrecke: von der
Wunschliste verbrauchen wir nur Namen (Sendetermine kommen aus dem XMLTV,
Nummern aus Katalog/TMDB/TheTVDB), sie war aber der einzige Weg, eine Serie
hinzuzufuegen.

| Fall | Was passiert |
|---|---|
| Serie auf wunschliste.de gemerkt | kommt beim naechsten Bezug als `wunschliste` dazu |
| dort entfernt | faellt heraus - ausser die Zeile steht auf `eigen` |
| Schalter auf *aus* | wird nicht aufgenommen, bleibt aber auf der Webseite stehen |
| aus dem Programmfuehrer hinzugefuegt | kommt als `eigen` dazu |

Von aussen: `SR_SerieHinzufuegen($id, 'Name')`, `SR_SerieEntfernen($id, 'Name')`,
`SR_Serienliste($id)`. Im Programmfuehrer erledigt das die Pille *Serie
aufnehmen* im Sendungsfenster.

## Abdeckung gegenueber der Skript-Fassung

Die alte Fassung hat 284 Methoden in 15 Klassen. Was davon im Modul steckt:

| Bereich                     | alt | Stand |
|-----------------------------|----:|-------|
| Matching (Titel/Sender)     |  23 | neu gebaut, ersetzt |
| XMLTV lesen                 |  15 | Lesen/Parsen ja, Download und Vorfilter-Datei nein |
| Wunschliste (Web)           |  15 | Bezug erledigt; die Liste fuehrt jetzt das Modul (siehe unten) |
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

## Wer haelt den Katalog aktuell?

Heute: **das Altsystem**. `Schedule Recordings` (44702) laeuft alle zwei
Stunden und schreibt dabei die Serien-Dumps und den TVDB-Cache neu - am
20.08.2026 zuletzt um 16:08.

Das Modul liest diese Ablage und schreibt selbst nur ueber die gekapselten
Handler: was TMDB oder TheTVDB frisch holen, legen sie in ihren eigenen
Verzeichnissen ab. Damit der Katalog davon auch profitiert, liest er seit
diesem Stand **drei** Formate statt zwei - das TMDB-Format legt je Episode eine
eigene Datei an (ueber 5000 Stueck), was zusaetzliche 0,08 s kostet und 2200
Episoden bringt.

**Nach dem Cutover** (Phase 4, Altsystem aus) pflegen nur noch die Handler des
Moduls. Die Serien-Dumps im Hauptverzeichnis und der TVDB-Cache frieren dann
auf ihrem letzten Stand ein - was kein Verlust ist, solange TMDB die neuen
Folgen liefert, aber vor dem Abschalten geprueft gehoert.

## Ablösung des Skript-Bundles

Inventur vom 20.08.2026: **17 Skripte** bilden den Kern, sechs davon laufen
zyklisch.

| Skript | Takt | Aufgabe | Modul |
|---|---|---|---|
| DownLoad Data (46864) | 2 h | Vorschau holen, Wunschliste | Vorschau erledigt, Wunschliste offen |
| DownLoad Data Alt (38017) | 2 h | zweite Quelle | offen |
| Schedule Recordings (44702) | 2 h | zuordnen, Timer setzen, Tabellen | zuordnen erledigt, Rest offen |
| checkDuplicates (12003) | 1 h | Duplikate suchen | offen |
| deleteDuplicates (49284) | — | Duplikate loeschen | offen |
| checkCorrectEpisodeNames (14281) | 1 h | Episodennamen richtigstellen | offen |

Jede Aufgabe bekommt im Modul einen eigenen Takt. Ein gemeinsamer muesste sich
am teuersten Posten orientieren - die Vorschau aendert sich zweimal am Tag, die
Zuordnung soll oefter laufen.

**Zur URL der Programmvorschau:** Im Altskript stehen drei, zwei davon
auskommentiert. Die abgeschaltete `epg.xmltv.host` antwortet heute mit HTTP 522,
die aktive `epg.best` mit 200 in 3,8 s. Als Property ist sichtbar, welche gilt.

## Bestandsscan

Durchsucht die Aufnahmeverzeichnisse und schreibt die Bestandsliste im Format
des Altsystems (`lfd|Serie|S01E01|Titel|Pfad`), damit beide Fassungen dieselbe
Liste lesen koennen. Gemessen: **12.552 Aufnahmen in 202 Serien, 8,2 s** ueber
die CIFS-Freigabe.

**Zwei Unterschiede zum Original, beide aus Schaden gelernt:**

Das Original loescht die alte Liste als ERSTES und schreibt dann neu. Ist die
Freigabe in dem Moment nicht eingebunden, bleibt eine leere Datei zurueck - und
eine leere Bestandsliste heisst fuer die Entscheidung "nichts ist vorhanden".
Der naechste Lauf wuerde alles erneut aufnehmen. Hier wird in eine Nebendatei
geschrieben, geprueft und erst dann uebernommen; bei zu wenigen Funden bleibt
die alte Liste stehen. Nachgestellt und bestaetigt: bei unerreichbarem
Verzeichnis blieb die vorhandene Liste unangetastet.

Das Original entfernt ausserdem alle Unterstriche aus dem Dateinamen
(`str_replace("_","")`) - und speichert diesen veraenderten Namen als PFAD.
Dateien, die tatsaechlich einen Unterstrich tragen (der Receiver setzt ihn fuer
Doppelpunkte: `Magnum P_I_`), sind damit unter einem Pfad verzeichnet, den es
nicht gibt. In einer Stichprobe von 300 Zeilen:

    Altsystem   9 von 300 Pfaden zeigen ins Leere
    Modul       0 von 300

Fuer die reine Existenzpruefung faellt das nicht auf, weil die Vergleichsform
Unterstriche ohnehin entfernt. Fuer alles, was die Datei ANFASST - loeschen,
umbenennen - ist es ein Fehler.

Nicht uebernommen wurde `mount -a`: Das Original ruft es auf, wenn der Scan
fast nichts findet. Eine Freigabe einzuhaengen ist ein Eingriff ins
Betriebssystem und gehoert nicht in einen Lesevorgang - das Modul meldet den
Verdacht und ueberlaesst die Entscheidung.
