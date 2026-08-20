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
