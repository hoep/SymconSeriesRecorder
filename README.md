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
