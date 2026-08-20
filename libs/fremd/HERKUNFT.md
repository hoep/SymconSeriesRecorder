# Uebernommener Code

## serienrecorder.class.tvdb.php

Unveraendert uebernommen aus `/var/lib/symcon/scripts/` (Stand 15.06.2025,
1203 Zeilen). Spricht TheTVDB v3 an und verwaltet Token, Rate-Limit und
Dateicache selbst.

**Bewusst nicht neu geschrieben.** Die Klasse kennt die Eigenheiten der
Schnittstelle - Auswahl unter mehreren Suchtreffern, deutsche gegen
Originaltitel, Episodenzuordnung ueber Titel UND Sendedatum. Das nachzubauen
haette Wochen gekostet und Fehler eingefuehrt, die dort laengst behoben sind.

Sie passt hier hinein, weil sie zwei Dinge NICHT tut: sie ruft keine einzige
IPS-Funktion auf, und von ihrer Umgebung braucht sie genau eine Methode -
`log($nachricht, $ebene)`. Dafuer gibt es `TvdbProtokoll`.

**Nicht bearbeiten.** Aenderungen gehoeren in die Fassade `TvdbQuelle`, sonst
laesst sich eine neuere Fassung der Klasse nicht mehr einspielen.

Nicht uebernommen: der TMDB-Handler. Im Altsystem ist er der Ausweichweg, wenn
TheTVDB nichts findet; sein Cache umfasst 61 Eintraege gegenueber 419 bei TVDB.
Er kommt erst dazu, wenn sich zeigt, dass TVDB allein Luecken laesst.

## serienrecorder.class.api.php (TMDB)

Ebenfalls unveraendert uebernommen (Stand 24.04.2025, 1254 Zeilen). Spricht
TMDB an: Seriensuche, Staffel- und Episodendaten, Zuordnung ueber Episodentitel
oder Sendedatum. Gleiche Voraussetzungen wie beim TVDB-Handler - keine
IPS-Aufrufe, als Umgebung genuegt eine `log()`-Methode.

**Warum sie ploetzlich wichtig ist:** Im Altsystem ist TMDB der Ausweichweg
hinter TheTVDB. Am 20.08.2026 gemessen antwortet TheTVDB v3 auf
`/search/series` mit **404** - die Suche ist abgeschaltet, waehrend Auth und
`/series/<id>/episodes` weiterlaufen. Ohne Suche findet der TVDB-Handler keine
Serie, die nicht schon im Cache steht. Der vorhandene v3-Schluessel wird bei v4
mit `InvalidAPIKey` abgelehnt, ein v4-Zugang waere eine Neuanschaffung.

TMDB dagegen laeuft mit dem Schluessel, der ohnehin schon in der Konfiguration
steht - Suche und Episodenabruf beide mit HTTP 200, deutsche Titel inklusive.
Damit kehrt sich die Rangfolge um: TMDB ist die Quelle fuer alles Unbekannte,
TheTVDB bleibt fuer die rund 200 Serien, deren ID im Cache liegt.

## serienrecorder.class.wunschliste.php

Unveraendert uebernommen (Stand 2025, 811 Zeilen). Meldet sich bei
wunschliste.de an, haelt die Sitzung ueber einen Cookie-Speicher und liest die
Favoritenliste. Wie die anderen beiden: keine IPS-Aufrufe. Vom Umfeld braucht
sie zwei Methoden - `log()` und `shouldRefreshFile()`; letztere ist eine reine
Altersfrage und steht deshalb im Adapter.

Eine fremde Anmeldestrecke nachzubauen hiesse, sie ein zweites Mal zu erraten -
mit dem Ergebnis, dass bei der naechsten Aenderung der Webseite zwei Fassungen
brechen statt einer.

## serienrecorder.class.recorder.php (Enigma-Receiver)

Unveraendert uebernommen (999 Zeilen). Spricht den Receiver ueber seine
Web-Schnittstelle an: Kanalliste, Timerliste, Timer anlegen. Keine
IPS-Aufrufe; vom Umfeld braucht sie `log()`, `seriesNamesMatch()` und
`shouldRefreshFile()`.

Genutzt wird davon in diesem Stand **nur `getTimerList()`** - ein GET auf
`/web/timerlist`. Das Anlegen von Timern kann dieselbe Klasse
(`/web/timeradd`), wird von der Fassade aber nicht angeboten: solange das
Altsystem programmiert, waeren zwei Absender auf demselben Receiver ein Rezept
fuer doppelte Aufnahmen.
