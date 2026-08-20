<?php
/**
 * SerienRecorderTVDB
 * 
 * Klasse für die Integration der TheTVDB API (V3) zum Abrufen von Serien- und Episodeninformationen
 * mit umfassendem Caching und Unterstützung für deutsche Metadaten
 * 
 * @version 1.3.0
 * @changelog
 * - 1.3.0: Performance-Optimierungen für schnellere Verarbeitung
 *   - Implementation eines In-Memory-Caches für Episoden und Serien-Metadaten
 *   - Optimierte Cache-Validierung mit öffentlicher isCacheValid-Methode
 *   - Verbesserte Episodenidentifikation mit beschleunigter Suchlogik
 *   - Intelligente API-Anfragen nur bei wirklich notwendigen Cache-Aktualisierungen
 *   - Effizienter Datenaustausch zwischen Cache und Broadcast-Objekten
 * - 1.2.0: Cache-Optimierung für reduzierte API-Anfragen
 *   - Neuer Mechanismus für Cache-basierte Aktualisierungen ohne API-Aufrufe
 *   - Implementierung einer enrichBroadcastFromCache-Methode für direkte Cache-Verwendung
 *   - Export der Hilfsmethode getEpisodesFilePath für externe Cache-Prüfungen
 *   - Verbesserte Episodensuche im Cache mit mehreren Übereinstimmungskriterien
 * - 1.1.1: Optimiertes Caching für Spezialserien
 *   - Eigener Cache-Mechanismus für Serien, die immer aktualisiert werden
 *   - Verbesserte Überschreibung bestehender Episodeninformationen
 *   - Höhere Priorität für Episodentitelmatchings
 *   - Zuverlässigere Metadatenintegration für spezielle Serienformate
 * - 1.1.0: Erweiterte Funktionalität für zuverlässigere Metadaten
 *   - Neuer Parameter in enrichBroadcast() zum Erzwingen der Aktualisierung auch bestehender Daten
 *   - Verbesserte Episodensuche mit fokussierter Verwendung des Episodentitels
 *   - Unterstützung für das Überschreiben von Staffel- und Episodennummern
 *   - Bessere Handhabung mehrsprachiger Episodentitel und Beschreibungen
 *   - Optimierte Caching-Strategie für häufig verwendete Serien
 */

class SerienRecorderTVDB {
    // [Bisherige Klassenvariablen]
    private $config;
    private $utilHandler;
    private $apiKey;
    private $apiToken;
    private $tokenExpiration;
    private $metadataDir;
    private $cacheLifetime;
    private $lastRequestTime = 0;
    private $requestInterval = 0.5; // 500ms zwischen Anfragen (max. 2 Anfragen/Sekunde)
    private $tokenCacheFile;
    private $specialSeriesCacheLifetime = 24; // Standardwert: 24 Stunden für Spezialserien
    
    // Basis-URLs für TheTVDB API V3
    private $apiBaseUrl = 'https://api.thetvdb.com';
    private $apiVersion = '3.0.0';
    
    // In-Memory-Cache für häufig verwendete Daten
    private $episodesMemoryCache = []; // In-Memory-Cache für Episodendaten
    private $seriesMemoryCache = []; // In-Memory-Cache für Seriensuchen
    
    /**
     * Konstruktor
     * @param array $config Konfigurationsarray
     * @param SerienRecorderUtil $utilHandler Utility-Handler
     */
    public function __construct($config, $utilHandler) {
        $this->config = $config;
        $this->utilHandler = $utilHandler;
        
        // API-Key aus Konfiguration lesen
        $this->apiKey = isset($config['tvdb_api_key']) ? $config['tvdb_api_key'] : '';
        
        // Erweiterte TVDB-Konfiguration auslesen
        if (isset($config['tvdb']) && is_array($config['tvdb'])) {
            if (isset($config['tvdb']['api_key']) && !empty($config['tvdb']['api_key'])) {
                $this->apiKey = $config['tvdb']['api_key'];
            }
            
            if (isset($config['tvdb']['cache_lifetime'])) {
                $this->cacheLifetime = (int)$config['tvdb']['cache_lifetime'];
            } else {
                $this->cacheLifetime = 168; // Standard: 7 Tage in Stunden
            }
            
            if (isset($config['tvdb']['metadata_dir']) && !empty($config['tvdb']['metadata_dir'])) {
                $this->metadataDir = $config['tvdb']['metadata_dir'];
            }
        }
        
        // Speziellen Cache-Lifetime für always_use_tvdb_series
        if (isset($config['always_use_tvdb_cache_lifetime'])) {
            $this->specialSeriesCacheLifetime = (int)$config['always_use_tvdb_cache_lifetime'];
        }
        
        // Wenn kein spezielles Metadaten-Verzeichnis angegeben ist, Standard-Cache-Verzeichnis verwenden
        if (empty($this->metadataDir)) {
            $this->metadataDir = $this->config['cache_dir'] . '/tvdb_metadata';
        }
        
        // Verzeichnis für Metadaten erstellen, falls nicht vorhanden
        if (!is_dir($this->metadataDir)) {
            mkdir($this->metadataDir, 0755, true);
        }
        
        // Cache-Datei für das Token
        $this->tokenCacheFile = $this->metadataDir . '/auth_token.json';
        
        // Versuche, ein gespeichertes Token zu laden, oder hole ein neues
        $this->loadOrRequestToken();
    }
    
    /**
     * Setzt oder aktualisiert Konfigurationseinstellungen
     * @param array $config Zu aktualisierende Konfigurationseinstellungen
     */
    public function setConfig($config) {
        $this->config = $config;
        
        // API-Key aktualisieren, falls in der Konfiguration vorhanden
        if (isset($config['tvdb_api_key'])) {
            $this->apiKey = $config['tvdb_api_key'];
        }
        
        // Erweiterte TVDB-Konfiguration aktualisieren
        if (isset($config['tvdb']) && is_array($config['tvdb'])) {
            if (isset($config['tvdb']['api_key']) && !empty($config['tvdb']['api_key'])) {
                $this->apiKey = $config['tvdb']['api_key'];
            }
            
            if (isset($config['tvdb']['cache_lifetime'])) {
                $this->cacheLifetime = (int)$config['tvdb']['cache_lifetime'];
            }
            
            if (isset($config['tvdb']['metadata_dir']) && !empty($config['tvdb']['metadata_dir'])) {
                $this->metadataDir = $config['tvdb']['metadata_dir'];
            }
        }
        
        // Speziellen Cache-Lifetime für always_use_tvdb_series
        if (isset($config['always_use_tvdb_cache_lifetime'])) {
            $this->specialSeriesCacheLifetime = (int)$config['always_use_tvdb_cache_lifetime'];
        }
    }
    
    /**
     * Gibt den Pfad zur Episoden-Datei einer Serie zurück
     * (Public, damit externe Klassen Cache-Status prüfen können)
     * @param int $seriesId TVDB-ID der Serie
     * @return string Pfad zur Episoden-Datei
     */
    public function getEpisodesFilePath($seriesId) {
        return $this->getSeriesDirectoryPath($seriesId) . '/episodes.json';
    }
    
    /**
     * Prüft, ob eine Datei im Cache noch gültig ist
     * @param string $filePath Pfad zur Datei
     * @param int $cacheLifetime Cache-Lebensdauer in Stunden (optional)
     * @param bool $isSpecialSeries Ist dies eine Serie, die immer mit TVDB aktualisiert werden soll?
     * @return bool Ist die Datei noch gültig?
     */
    public function isCacheValid($filePath, $cacheLifetime = null, $isSpecialSeries = false) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Cache-Lebensdauer bestimmen
        if ($cacheLifetime === null) {
            if ($isSpecialSeries) {
                // Verwende kürzere Lebensdauer für Spezialserien
                $cacheLifetime = $this->specialSeriesCacheLifetime;
            } else {
                // Standard-Lebensdauer für normale Serien
                $cacheLifetime = $this->cacheLifetime;
            }
        }
        
        $fileAge = time() - filemtime($filePath);
        $maxAge = $cacheLifetime * 3600; // Umrechnung in Sekunden
        
        return $fileAge <= $maxAge;
    }
    
    /**
     * Sucht eine Serie basierend auf dem Namen
     * @param string $seriesName Serienname
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @param bool $isSpecialSeries Ist dies eine Serie, die immer mit TVDB aktualisiert werden soll?
     * @return array|null Serie-Info oder null bei Fehler
     */
    public function searchSeries($seriesName, $forceRefresh = false, $isSpecialSeries = false) {
        // Memory-Cache prüfen
        $cacheKey = "series_search_" . md5($seriesName);
        if (!$forceRefresh && isset($this->seriesMemoryCache[$cacheKey])) {
            $this->utilHandler->log("Seriensuche für '$seriesName' aus Memory-Cache geladen", 1);
            return $this->seriesMemoryCache[$cacheKey];
        }
        
        // Cache-Schlüssel generieren
        $cacheFile = $this->metadataDir . '/' . $cacheKey . '.json';
        
        // Prüfen, ob Daten im Cache vorhanden sind
        if (!$forceRefresh && $this->isCacheValid($cacheFile, null, $isSpecialSeries)) {
            $this->utilHandler->log("Seriensuche für '$seriesName' aus Cache geladen");
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            
            // Im Memory-Cache speichern für zukünftige Abfragen
            $this->seriesMemoryCache[$cacheKey] = $cachedData;
            
            return $cachedData;
        }
        
        // Parameter für die Suche
        $params = [
            'name' => $seriesName
        ];
        
        // Suche durchführen
        $response = $this->apiRequest('/search/series', $params);
        
        if (!$response || !isset($response['data']) || empty($response['data'])) {
            $this->utilHandler->log("Keine Serien für '$seriesName' gefunden");
            return null;
        }
        
        // Beste Übereinstimmung finden
        $bestMatch = null;
        $highestScore = 0;
        
        foreach ($response['data'] as $show) {
            // Prüfen, ob der Name exakt übereinstimmt
            if (strcasecmp($show['seriesName'], $seriesName) === 0) {
                $this->utilHandler->log("Exakte Übereinstimmung für '$seriesName' gefunden: " . $show['seriesName'] . " (ID: " . $show['id'] . ")");
                $bestMatch = $show;
                break;
            }
            
            // Ähnlichkeit berechnen
            similar_text($seriesName, $show['seriesName'], $score);
            
            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = $show;
            }
        }
        
        // Wenn die Ähnlichkeit hoch genug ist, diese Serie verwenden
        if ($bestMatch && ($highestScore > 60 || strcasecmp($bestMatch['seriesName'], $seriesName) === 0)) {
            $this->utilHandler->log("Beste Übereinstimmung für '$seriesName': " . $bestMatch['seriesName'] . 
                                " (ID: " . $bestMatch['id'] . ", Ähnlichkeit: " . round($highestScore) . "%)");
            
            // Im Cache speichern
            file_put_contents($cacheFile, json_encode($bestMatch, JSON_PRETTY_PRINT));
            
            // Im Memory-Cache speichern
            $this->seriesMemoryCache[$cacheKey] = $bestMatch;
            
            return $bestMatch;
        }
        
        $this->utilHandler->log("Keine ausreichend ähnliche Serie für '$seriesName' gefunden");
        return null;
    }
    
    /**
    * Reichert einen Broadcast mit Daten aus dem Cache an (ohne API-Anfrage)
    * @param array &$broadcast Broadcast-Daten (wird direkt aktualisiert)
    * @param int $seriesId Optionale SeriesID, wenn bereits bekannt (spart Suche)
    * @return bool Erfolg der Aktualisierung
    */
    public function enrichBroadcastFromCache(&$broadcast, $seriesId = null) {
        // Stellen sicher, dass die Basiswerte existieren
        if (!isset($broadcast['season'])) {
            $broadcast['season'] = 0;
        }
        if (!isset($broadcast['episode'])) {
            $broadcast['episode'] = 0;
        }
        if (!isset($broadcast['episodeName'])) {
            $broadcast['episodeName'] = '';
        }
        
        $seriesName = $broadcast['title'];
        $episodeName = isset($broadcast['episodeName']) ? $broadcast['episodeName'] : '';
        $airdate = '';
        
        // Ausstrahlungsdatum extrahieren
        if (isset($broadcast['start'])) {
            $year = substr($broadcast['start'], 0, 4);
            $month = substr($broadcast['start'], 4, 2);
            $day = substr($broadcast['start'], 6, 2);
            $airdate = $year . '-' . $month . '-' . $day;
        }
        
        // SeriesID verwenden, wenn gegeben, ansonsten im Cache suchen
        if (!$seriesId) {
            // Serie suchen, aber ohne neue API-Anfrage
            $series = $this->searchSeries($seriesName, false, true);
            
            if (!$series) {
                $this->utilHandler->log("Serie '$seriesName' nicht im Cache gefunden");
                return false;
            }
            
            $seriesId = $series['id'];
        }
        
        // Prüfen, ob Episoden im Memory-Cache vorhanden sind
        $cacheKey = "series_episodes_" . $seriesId;
        $allEpisodes = null;
        
        if (isset($this->episodesMemoryCache[$cacheKey])) {
            $allEpisodes = $this->episodesMemoryCache[$cacheKey];
            $this->utilHandler->log("Episoden für Serie $seriesId aus Memory-Cache geladen", 1);
        } else {
            // Lade Episoden aus der Cache-Datei
            $episodesFile = $this->getEpisodesFilePath($seriesId);
            
            if (!file_exists($episodesFile)) {
                $this->utilHandler->log("Episoden-Cache für Serie $seriesId nicht gefunden");
                return false;
            }
            
            $allEpisodes = json_decode(file_get_contents($episodesFile), true);
            
            if (!$allEpisodes) {
                $this->utilHandler->log("Fehler beim Lesen des Episoden-Cache für Serie $seriesId");
                return false;
            }
            
            // In Memory-Cache speichern
            $this->episodesMemoryCache[$cacheKey] = $allEpisodes;
        }
        
        // Finde passende Episode im Cache
        $episode = $this->findEpisodeInCache($allEpisodes, $episodeName, $airdate);
        
        if (!$episode) {
            $this->utilHandler->log("Keine passende Episode im Cache für '$seriesName'" . 
                                (!empty($episodeName) ? " - '$episodeName'" : "") . 
                                (!empty($airdate) ? " am $airdate" : ""));
            return false;
        }
        
        // Broadcast-Daten aktualisieren
        $broadcast['season'] = (int)$episode['airedSeason'];
        $broadcast['episode'] = (int)$episode['airedEpisodeNumber'];
        
        // Episodentitel aktualisieren - bevorzuge deutschen Titel
        if (isset($episode['germanName']) && !empty($episode['germanName'])) {
            $broadcast['episodeName'] = $episode['germanName'];
        } else if (isset($episode['episodeName']) && !empty($episode['episodeName'])) {
            $broadcast['episodeName'] = $episode['episodeName'];
        }
        
        // XMLTV-spezifische Werte hinzufügen
        $broadcast['original_season'] = max(0, $broadcast['season'] - 1);
        $broadcast['original_episode'] = max(0, $broadcast['episode'] - 1);
        
        $this->utilHandler->log("Episodendaten aus Cache für '$seriesName' gefunden: S" . 
                            $broadcast['season'] . "E" . $broadcast['episode'] . 
                            " - " . $broadcast['episodeName']);
        
        return true;
    }
    
    /**
    * Verbesserte Episodensuche in SerienRecorderTVDB
    * @param array $allEpisodes Array mit allen Episoden
    * @param string $episodeName Episodentitel
    * @param string $airdate Ausstrahlungsdatum
    * @return array|null Gefundene Episode oder null
    */
    private function findEpisodeInCache($allEpisodes, $episodeName = null, $airdate = null) {
        if (empty($allEpisodes)) {
            return null;
        }
        
        // 1. Suche nach exakter Übereinstimmung des Episodentitels
        if (!empty($episodeName)) {
            foreach ($allEpisodes as $episode) {
                if ((isset($episode['germanName']) && strcasecmp($episode['germanName'], $episodeName) === 0) || 
                    (isset($episode['episodeName']) && strcasecmp($episode['episodeName'], $episodeName) === 0)) {
                    return $episode;
                }
            }
        }
        
        // 2. Suche nach Datum
        if (!empty($airdate)) {
            foreach ($allEpisodes as $episode) {
                if (isset($episode['firstAired']) && substr($episode['firstAired'], 0, 10) === $airdate) {
                    return $episode;
                }
            }
        }
        
        // 3. Suche nach hoher Ähnlichkeit des Titels
        if (!empty($episodeName)) {
            $bestMatch = null;
            $bestScore = 0;
            
            foreach ($allEpisodes as $episode) {
                $tvdbTitle = isset($episode['germanName']) ? $episode['germanName'] : 
                            (isset($episode['episodeName']) ? $episode['episodeName'] : '');
                
                if (!empty($tvdbTitle)) {
                    similar_text(strtolower($episodeName), strtolower($tvdbTitle), $score);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $episode;
                    }
                }
            }
            
            // Nur zurückgeben, wenn Ähnlichkeit hoch genug ist
            if ($bestMatch && $bestScore > 70) {
                return $bestMatch;
            }
        }
        
        // 4. NEU: Suche nach Teilzeichenketten-Übereinstimmung
        if (!empty($episodeName)) {
            foreach ($allEpisodes as $episode) {
                $tvdbTitle = isset($episode['germanName']) ? $episode['germanName'] : 
                            (isset($episode['episodeName']) ? $episode['episodeName'] : '');
                
                if (!empty($tvdbTitle)) {
                    // Prüfen, ob der XMLTV-Titel ein Teilstring des TVDB-Titels ist
                    if (stripos($tvdbTitle, $episodeName) !== false) {
                        $this->utilHandler->log("Teilzeichenketten-Match gefunden: '$episodeName' ist Teil von '$tvdbTitle'");
                        return $episode;
                    }
                    
                    // Oder umgekehrt: Ist der TVDB-Titel ein Teilstring des XMLTV-Titels?
                    if (stripos($episodeName, $tvdbTitle) !== false) {
                        $this->utilHandler->log("Teilzeichenketten-Match gefunden: '$tvdbTitle' ist Teil von '$episodeName'");
                        return $episode;
                    }
                    
                    // Wortweise Übereinstimmung prüfen (z.B. für Titel wie "Zugzwang" in "Batic & Leitmayr - 98 - Zugzwang")
                    $xmltvWords = explode(' ', strtolower($episodeName));
                    $tvdbWords = explode(' ', strtolower($tvdbTitle));
                    
                    $commonWords = array_intersect($xmltvWords, $tvdbWords);
                    if (count($commonWords) > 0 && 
                        count($commonWords) / count($xmltvWords) > 0.5) {
                        $this->utilHandler->log("Gemeinsame Wörter gefunden zwischen '$episodeName' und '$tvdbTitle'");
                        return $episode;
                    }
                }
            }
        }
        
        // 5. Suche nach nahem Datum (±3 Tage) als letzter Ausweg
        if (!empty($airdate)) {
            $airdateTS = strtotime($airdate);
            $bestMatch = null;
            $bestDiff = PHP_INT_MAX;
            
            foreach ($allEpisodes as $episode) {
                if (isset($episode['firstAired']) && !empty($episode['firstAired'])) {
                    $episodeTS = strtotime($episode['firstAired']);
                    if ($episodeTS) {
                        $diff = abs($episodeTS - $airdateTS);
                        if ($diff < $bestDiff && $diff <= 259200) { // 3 Tage in Sekunden
                            $bestDiff = $diff;
                            $bestMatch = $episode;
                        }
                    }
                }
            }
            
            if ($bestMatch) {
                return $bestMatch;
            }
        }
        
        // Keine passende Episode gefunden
        return null;
    }

    /**
    * NEUE private Hilfsmethode in SerienRecorderTVDB Klasse
    * Füge diese Methode HINZU (nicht ersetzen)
    */
    private function normalizeForTitleComparison($title) {
        if (empty($title)) {
            return '';
        }
        
        // Nur minimal normalisieren für Satzzeichen-Probleme
        $normalized = trim($title);
        
        // NUR problematische Satzzeichen am Ende entfernen (!, ?, .)
        $normalized = rtrim($normalized, '!?.;');
        
        // Für case-insensitive Vergleich
        $normalized = mb_strtolower($normalized, 'UTF-8');
        
        return trim($normalized);
    }

    /**
    * Verbesserter episodenFinder für den TVDB-Handler
    * Diese Funktion sollte in die SerienRecorderTVDB-Klasse eingefügt werden
    * 
    * @param array $allEpisodes Array mit allen Episoden
    * @param string $searchTitle Der zu suchende Episodentitel
    * @param string $airdate Optional: Ausstrahlungsdatum als Zusatzkriterium
    * @return array|null Gefundene Episode oder null
    */
    public function findEpisodeByFlexibleMatch($allEpisodes, $searchTitle, $airdate = null) {
        if (empty($allEpisodes) || empty($searchTitle)) {
            return null;
        }
        
        $this->utilHandler->log("Suche Episode mit flexiblem Titelabgleich: '$searchTitle'");
        
        // 1. Exakte Übereinstimmung (UNVERÄNDERT)
        foreach ($allEpisodes as $episode) {
            if ((isset($episode['germanName']) && strcasecmp($episode['germanName'], $searchTitle) === 0) ||
                (isset($episode['episodeName']) && strcasecmp($episode['episodeName'], $searchTitle) === 0)) {
                $this->utilHandler->log("Exakte Übereinstimmung gefunden: '$searchTitle'");
                return $episode;
            }
        }
        
        // 2. NEU: Exakte Übereinstimmung nach minimaler Normalisierung (NUR für Satzzeichen-Probleme)
        $normalizedSearchTitle = $this->normalizeForTitleComparison($searchTitle);
        
        foreach ($allEpisodes as $episode) {
            $tvdbTitleGerman = isset($episode['germanName']) ? $episode['germanName'] : '';
            $tvdbTitleOriginal = isset($episode['episodeName']) ? $episode['episodeName'] : '';
            
            if (!empty($tvdbTitleGerman)) {
                $normalizedGerman = $this->normalizeForTitleComparison($tvdbTitleGerman);
                if (!empty($normalizedGerman) && $normalizedGerman === $normalizedSearchTitle) {
                    $this->utilHandler->log("Exakte Übereinstimmung nach Normalisierung (DE): '$searchTitle' ≈ '$tvdbTitleGerman'");
                    return $episode;
                }
            }
            
            if (!empty($tvdbTitleOriginal)) {
                $normalizedOriginal = $this->normalizeForTitleComparison($tvdbTitleOriginal);
                if (!empty($normalizedOriginal) && $normalizedOriginal === $normalizedSearchTitle) {
                    $this->utilHandler->log("Exakte Übereinstimmung nach Normalisierung (EN): '$searchTitle' ≈ '$tvdbTitleOriginal'");
                    return $episode;
                }
            }
        }
        
        // 3. Teilzeichenkettenübereinstimmung (UNVERÄNDERT)
        foreach ($allEpisodes as $episode) {
            $tvdbTitle = isset($episode['germanName']) ? $episode['germanName'] : 
                    (isset($episode['episodeName']) ? $episode['episodeName'] : '');
            
            if (!empty($tvdbTitle)) {
                if (stripos($tvdbTitle, $searchTitle) !== false) {
                    $this->utilHandler->log("Teilzeichenketten-Match: '$searchTitle' ist Teil von '$tvdbTitle'");
                    return $episode;
                }
                
                if (stripos($searchTitle, $tvdbTitle) !== false) {
                    $this->utilHandler->log("Teilzeichenketten-Match: '$tvdbTitle' ist Teil von '$searchTitle'");
                    return $episode;
                }
            }
        }
        
        // 4. Wortübereinstimmung (UNVERÄNDERT)
        foreach ($allEpisodes as $episode) {
            $tvdbTitle = isset($episode['germanName']) ? $episode['germanName'] : 
                    (isset($episode['episodeName']) ? $episode['episodeName'] : '');
            
            if (!empty($tvdbTitle)) {
                $searchWords = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($searchTitle)));
                $tvdbWords = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', strtolower($tvdbTitle)));
                
                $searchWords = array_filter($searchWords, function($word) { return strlen($word) > 3; });
                
                $commonWords = array_intersect($searchWords, $tvdbWords);
                $matchScore = count($searchWords) > 0 ? count($commonWords) / count($searchWords) : 0;
                
                if (count($commonWords) > 0 && $matchScore > 0.5) {
                    $this->utilHandler->log("Wortübereinstimmungs-Match für '$searchTitle' und '$tvdbTitle' (Score: ".round($matchScore*100)."%)");
                    return $episode;
                }
            }
        }
        
        // 5. Levenshtein-Distanz (UNVERÄNDERT)
        $bestMatch = null;
        $bestDistance = PHP_INT_MAX;
        $maxDistance = min(5, floor(strlen($searchTitle) * 0.3));
        
        foreach ($allEpisodes as $episode) {
            $tvdbTitle = isset($episode['germanName']) ? $episode['germanName'] : 
                    (isset($episode['episodeName']) ? $episode['episodeName'] : '');
            
            if (!empty($tvdbTitle)) {
                $distance = levenshtein(strtolower($searchTitle), strtolower($tvdbTitle));
                
                if ($distance < $bestDistance && $distance <= $maxDistance) {
                    $bestDistance = $distance;
                    $bestMatch = $episode;
                }
            }
        }
        
        if ($bestMatch) {
            $this->utilHandler->log("Levenshtein-Match für '$searchTitle' gefunden (Distanz: $bestDistance)");
            return $bestMatch;
        }
        
        // 6. Datumsbasierte Suche (UNVERÄNDERT)
        if (!empty($airdate)) {
            $bestMatch = null;
            $bestDiff = 259200; // 3 Tage in Sekunden
            $airdateTs = strtotime($airdate);
            
            foreach ($allEpisodes as $episode) {
                if (isset($episode['firstAired']) && !empty($episode['firstAired'])) {
                    $epDate = strtotime($episode['firstAired']);
                    if ($epDate) {
                        $diff = abs($epDate - $airdateTs);
                        if ($diff < $bestDiff) {
                            $bestDiff = $diff;
                            $bestMatch = $episode;
                        }
                    }
                }
            }
            
            if ($bestMatch) {
                $this->utilHandler->log("Datum-basierter Match gefunden (Abweichung: ".round($bestDiff/86400, 1)." Tage)");
                return $bestMatch;
            }
        }
        
        $this->utilHandler->log("Kein flexibler Match für '$searchTitle' gefunden");
        return null;
    }

    // Hilfsmethoden für Cache-Pfade
    private function getSeriesDirectoryPath($seriesId) {
        return $this->metadataDir . '/series_' . $seriesId;
    }
    
    private function getSeriesInfoFilePath($seriesId) {
        return $this->getSeriesDirectoryPath($seriesId) . '/series_info.json';
    }
    
    // API-Token-Handling
    private function loadOrRequestToken() {
        // Prüfen, ob ein Token-Cache existiert und noch gültig ist
        if (file_exists($this->tokenCacheFile)) {
            $tokenData = json_decode(file_get_contents($this->tokenCacheFile), true);
            
            if (isset($tokenData['token']) && isset($tokenData['expiration'])) {
                // Prüfen, ob das Token noch gültig ist (mit Puffer von 1 Stunde)
                if ($tokenData['expiration'] > (time() + 3600)) {
                    $this->apiToken = $tokenData['token'];
                    $this->tokenExpiration = $tokenData['expiration'];
                    $this->utilHandler->log("Gespeichertes TheTVDB API-Token geladen, gültig bis: " . 
                                          date('Y-m-d H:i:s', $this->tokenExpiration));
                    return true;
                }
            }
        }
        
        // Wenn kein gültiges Token gefunden wurde, ein neues anfordern
        return $this->requestNewToken();
    }
    
    private function requestNewToken() {
        // Token-Anforderungslogik bleibt unverändert
        if (empty($this->apiKey)) {
            $this->utilHandler->log("TheTVDB API-Key nicht gesetzt");
            return false;
        }
        
        $this->utilHandler->log("Fordere neues TheTVDB API-Token an");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiBaseUrl . '/login');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['apikey' => $this->apiKey]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Language: de-DE' // Explizit deutsche Sprache anfordern
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler bei Token-Anforderung: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->utilHandler->log("HTTP-Fehler bei Token-Anforderung: " . $httpCode);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['token'])) {
            $this->utilHandler->log("Ungültige Antwort bei Token-Anforderung: Token nicht gefunden");
            return false;
        }
        
        $this->apiToken = $data['token'];
        // TheTVDB Tokens sind 24 Stunden gültig
        $this->tokenExpiration = time() + (24 * 3600);
        
        // Token im Cache speichern
        $tokenData = [
            'token' => $this->apiToken,
            'expiration' => $this->tokenExpiration
        ];
        
        file_put_contents($this->tokenCacheFile, json_encode($tokenData));
        
        $this->utilHandler->log("Neues TheTVDB API-Token erhalten und gespeichert, gültig bis: " . 
                              date('Y-m-d H:i:s', $this->tokenExpiration));
        
        return true;
    }
    
    // API-Anfrage-Handling
    private function apiRequest($endpoint, $params = [], $method = 'GET') {
        // API-Anfragenlogik bleibt unverändert
        if (empty($this->apiToken)) {
            if (!$this->loadOrRequestToken()) {
                $this->utilHandler->log("Kein gültiges TheTVDB API-Token verfügbar");
                return null;
            }
        }
        
        // Ratelimit berücksichtigen - maximal 2 Anfragen pro Sekunde
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        if ($timeSinceLastRequest < $this->requestInterval) {
            $sleepTime = ($this->requestInterval - $timeSinceLastRequest) * 1000000; // Mikrosekunden
            usleep($sleepTime);
        }
        
        // Aktualisiere die Zeit der letzten Anfrage
        $this->lastRequestTime = microtime(true);
        
        $url = $this->apiBaseUrl . $endpoint;
        
        // Bei GET-Anfragen Parameter als Query-String anhängen
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        // Debug-Ausgabe der URL
        //$this->utilHandler->log("TheTVDB API-Anfrage: " . $url . " (Methode: $method)");
        
        // cURL für den API-Aufruf vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // HTTP-Header setzen
        $headers = [
            'Accept: application/json',
            'Accept-Language: de-DE', // Deutsche Sprache anfordern
            'Authorization: Bearer ' . $this->apiToken
        ];
        
        // Bei POST-Anfragen Content-Type setzen und Daten im Body übergeben
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // API-Aufruf ausführen
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler bei API-Anfrage: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        // Prüfen, ob das Token abgelaufen ist (401) und ggf. erneuern
        if ($httpCode === 401) {
            $this->utilHandler->log("API-Token abgelaufen, fordere ein neues an");
            
            if ($this->requestNewToken()) {
                // Anfrage mit neuem Token wiederholen
                return $this->apiRequest($endpoint, $params, $method);
            } else {
                $this->utilHandler->log("Token-Erneuerung fehlgeschlagen");
                return null;
            }
        }
        
        // Prüfen, ob die Anfrage erfolgreich war
        if ($httpCode !== 200) {
            $this->utilHandler->log("HTTP-Fehler bei API-Anfrage: " . $httpCode);
            return null;
        }
        
        // Daten verarbeiten
        $data = json_decode($result, true);
        if (!$data) {
            $this->utilHandler->log("Fehler beim Decodieren der API-Antwort");
            return null;
        }
        
        return $data;
    }
    
    /**
     * Gibt statistische Informationen über den Cache zurück
     * @return array Cache-Statistiken
     */
    public function getCacheStats() {
        $stats = [
            'series_count' => 0,
            'episodes_count' => 0,
            'cache_size_bytes' => 0,
            'oldest_cache_entry' => null,
            'newest_cache_entry' => null,
            'memory_cache_series' => count($this->seriesMemoryCache),
            'memory_cache_episodes' => count($this->episodesMemoryCache)
        ];
        
        if (!is_dir($this->metadataDir)) {
            return $stats;
        }
        
        // Alle Serien-Verzeichnisse durchsuchen
        $seriesDirs = glob($this->metadataDir . '/series_*', GLOB_ONLYDIR);
        $stats['series_count'] = count($seriesDirs);
        
        $oldestTime = PHP_INT_MAX;
        $newestTime = 0;
        
        foreach ($seriesDirs as $seriesDir) {
            // Episodendatei
            $episodesFile = $seriesDir . '/episodes.json';
            if (file_exists($episodesFile)) {
                $stats['cache_size_bytes'] += filesize($episodesFile);
                
                $modTime = filemtime($episodesFile);
                if ($modTime < $oldestTime) {
                    $oldestTime = $modTime;
                }
                if ($modTime > $newestTime) {
                    $newestTime = $modTime;
                }
                
                // Anzahl der Episoden zählen
                $episodesData = json_decode(file_get_contents($episodesFile), true);
                if (is_array($episodesData)) {
                    $stats['episodes_count'] += count($episodesData);
                }
            }
            
            // Serieninfo-Datei
            $seriesInfoFile = $seriesDir . '/series_info.json';
            if (file_exists($seriesInfoFile)) {
                $stats['cache_size_bytes'] += filesize($seriesInfoFile);
            }
        }
        
        // Suche-Cache-Dateien
        $searchFiles = glob($this->metadataDir . '/search_*.json');
        foreach ($searchFiles as $file) {
            $stats['cache_size_bytes'] += filesize($file);
        }
        
        if ($oldestTime != PHP_INT_MAX) {
            $stats['oldest_cache_entry'] = date('Y-m-d H:i:s', $oldestTime);
        }
        
        if ($newestTime > 0) {
            $stats['newest_cache_entry'] = date('Y-m-d H:i:s', $newestTime);
        }
        
        // Formatierte Größe
        $stats['cache_size_formatted'] = $this->formatSize($stats['cache_size_bytes']);
        
        return $stats;
    }
    
    /**
     * Formatiert eine Byte-Größe in lesbare Form
     * @param int $bytes Größe in Bytes
     * @return string Formatierte Größe
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
    * Sucht fehlende Episodeninformationen für einen Broadcast und aktualisiert diesen
    * @param array &$broadcast Broadcast-Daten (wird direkt aktualisiert)
    * @param bool $forceRefresh Erzwinge das Neuladen der Daten und Überschreiben vorhandener Informationen
    * @return bool Erfolg der Aktualisierung
    */
    public function enrichBroadcast(&$broadcast, $forceRefresh = false) {
        // Stellen sicher, dass die Basiswerte existieren
        if (!isset($broadcast['season'])) {
            $broadcast['season'] = 0;
        }
        if (!isset($broadcast['episode'])) {
            $broadcast['episode'] = 0;
        }
        if (!isset($broadcast['episodeName'])) {
            $broadcast['episodeName'] = '';
        }
        
        $seriesName = $broadcast['title'];
        $episodeName = isset($broadcast['episodeName']) ? $broadcast['episodeName'] : '';
        
        // Prüfen, ob dies eine Spezialserie ist (zum kürzeren Caching)
        $isSpecialSeries = false;
        if (isset($this->config['always_use_tvdb_series']) && 
            is_array($this->config['always_use_tvdb_series']) && 
            in_array($seriesName, $this->config['always_use_tvdb_series'])) {
            $isSpecialSeries = true;
            $this->utilHandler->log("Behandle '$seriesName' als Spezialserie mit kürzerem Cache");
        }
        
        // Wenn bereits Informationen vorhanden sind und kein Force-Refresh, überspringen
        if (!$forceRefresh && isset($broadcast['season']) && isset($broadcast['episode']) && 
            ($broadcast['season'] > 0 || $broadcast['episode'] > 0)) {
            return true;
        }
        
        $this->utilHandler->log("Suche Episodeninformationen für '$seriesName'" . 
                                (!empty($episodeName) ? " - Episodentitel: '$episodeName'" : ""));
        
        // Ausstrahlungsdatum im Format YYYY-MM-DD extrahieren
        $airdate = '';
        if (isset($broadcast['start'])) {
            // Format der start-Variable: YYYYMMDDHHMMSS +0000
            $year = substr($broadcast['start'], 0, 4);
            $month = substr($broadcast['start'], 4, 2);
            $day = substr($broadcast['start'], 6, 2);
            $airdate = $year . '-' . $month . '-' . $day;
        }
        
        // Serie in TheTVDB suchen
        $series = $this->searchSeries($seriesName, $forceRefresh, $isSpecialSeries);
        
        if (!$series) {
            // Versuche alternative Suche bei Titeln mit Doppelpunkt
            if (strpos($seriesName, ':') !== false) {
                $altSeriesName = trim(explode(':', $seriesName)[0]);
                $this->utilHandler->log("Versuche alternative Suche für '$altSeriesName'");
                $series = $this->searchSeries($altSeriesName, $forceRefresh, $isSpecialSeries);
            }
            
            // Weitere Alternativen: Versuche Bindestriche und andere Trennzeichen
            if (!$series && strpos($seriesName, ' - ') !== false) {
                $altSeriesName = trim(explode(' - ', $seriesName)[0]);
                $this->utilHandler->log("Versuche alternative Suche für '$altSeriesName' (mit Bindestrich)");
                $series = $this->searchSeries($altSeriesName, $forceRefresh, $isSpecialSeries);
            }
            
            if (!$series) {
                $this->utilHandler->log("Keine Serie für '$seriesName' gefunden");
                return false;
            }
        }
        
        $seriesId = $series['id'];
        $this->utilHandler->log("Serie gefunden: " . $series['seriesName'] . " (ID: $seriesId)");
        
        // Holen aller Episoden mit dem korrekten Cache-Handling für Spezialserien
        $allEpisodes = $this->getSeriesEpisodes($seriesId, $forceRefresh, $isSpecialSeries);
        if (!$allEpisodes) {
            $this->utilHandler->log("Keine Episoden für Serie $seriesId gefunden");
            return false;
        }
        
        // Episode zuerst nach Titel suchen (wichtig für Formate wie "Tatort")
        $episode = null;
        
        if (!empty($episodeName)) {
            // VERBESSERT: Verwende flexibles Titelmatching
            $episode = $this->findEpisodeByFlexibleMatch($allEpisodes, $episodeName, $airdate);
            
            if ($episode) {
                $this->utilHandler->log("Episode durch flexibles Titelmatching gefunden: " . 
                                        ($episode['germanName'] ?? $episode['episodeName'] ?? 'Unbekannt'));
            }
        }
        
        // Wenn keine Episode nach Titel gefunden wurde, versuche es mit Datum
        if (!$episode && !empty($airdate)) {
            // Suche nach Datum
            foreach ($allEpisodes as $ep) {
                if (isset($ep['firstAired']) && substr($ep['firstAired'], 0, 10) === $airdate) {
                    $episode = $ep;
                    $this->utilHandler->log("Episode nach Datum gefunden: " . 
                                        ($ep['germanName'] ?? $ep['episodeName'] ?? 'Unbekannt') . 
                                        " (Datum: " . $ep['firstAired'] . ")");
                    break;
                }
            }
            
            // Wenn kein exaktes Datum passt, suche in ±3 Tagen
            if (!$episode) {
                $airdateTimestamp = strtotime($airdate);
                $bestMatch = null;
                $smallestDiff = PHP_INT_MAX;
                
                foreach ($allEpisodes as $ep) {
                    if (isset($ep['firstAired'])) {
                        $epTimestamp = strtotime($ep['firstAired']);
                        if ($epTimestamp) {
                            $diff = abs($epTimestamp - $airdateTimestamp);
                            $dayDiff = $diff / 86400; // Umrechnung in Tage
                            
                            if ($dayDiff <= 3 && $diff < $smallestDiff) {
                                $smallestDiff = $diff;
                                $bestMatch = $ep;
                            }
                        }
                    }
                }
                
                if ($bestMatch) {
                    $episode = $bestMatch;
                    $this->utilHandler->log("Episode mit ähnlichem Datum gefunden: " . 
                                        ($episode['germanName'] ?? $episode['episodeName'] ?? 'Unbekannt') . 
                                        " (Abweichung: " . round($smallestDiff/86400, 1) . " Tage)");
                }
            }
        }
        
        if (!$episode) {
            $this->utilHandler->log("Keine passende Episode für '$seriesName'" . 
                                    (!empty($episodeName) ? " ($episodeName)" : "") . 
                                    (!empty($airdate) ? " am $airdate" : "") . " gefunden");
            return false;
        }
        
        // Broadcast-Daten aktualisieren
        $broadcast['season'] = (int)$episode['airedSeason'];
        $broadcast['episode'] = (int)$episode['airedEpisodeNumber'];
        
        // Episodentitel aktualisieren - bevorzuge den deutschen Titel
        if (isset($episode['germanName']) && !empty($episode['germanName'])) {
            $broadcast['episodeName'] = $episode['germanName'];
            $this->utilHandler->log("Deutscher Episodentitel verwendet: " . $episode['germanName']);
        } else if (isset($episode['episodeName']) && !empty($episode['episodeName'])) {
            $broadcast['episodeName'] = $episode['episodeName'];
            $this->utilHandler->log("Originaler Episodentitel verwendet: " . $episode['episodeName']);
        }
        
        // XMLTV-spezifische 0-basierte Werte hinzufügen
        $broadcast['original_season'] = max(0, $broadcast['season'] - 1);
        $broadcast['original_episode'] = max(0, $broadcast['episode'] - 1);
        
        $this->utilHandler->log("Episodendaten für '$seriesName' gefunden: S" . 
                                $broadcast['season'] . "E" . 
                                $broadcast['episode'] . " - " . 
                                $broadcast['episodeName']);
        
        return true;
    }

    /**
     * Lädt alle Episoden einer Serie
     * @param int $seriesId TheTVDB-ID der Serie
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @param bool $isSpecialSeries Ist dies eine Serie, die immer mit TVDB aktualisiert werden soll?
     * @return array|null Liste aller Episoden oder null bei Fehler
     */
    public function getSeriesEpisodes($seriesId, $forceRefresh = false, $isSpecialSeries = false) {
        // Memory-Cache prüfen zuerst
        $cacheKey = "series_episodes_" . $seriesId;
        if (!$forceRefresh && isset($this->episodesMemoryCache[$cacheKey])) {
            $this->utilHandler->log("Episodenliste für Serie $seriesId aus Memory-Cache geladen", 1);
            return $this->episodesMemoryCache[$cacheKey];
        }
        
        $seriesDir = $this->getSeriesDirectoryPath($seriesId);
        $episodesFile = $this->getEpisodesFilePath($seriesId);
        
        // Prüfen, ob die Verzeichnisse existieren, wenn nicht, erstellen
        if (!is_dir($seriesDir)) {
            @mkdir($seriesDir, 0755, true);
        }
        
        // Prüfen, ob Episoden im Cache vorhanden sind und noch gültig sind
        if (!$forceRefresh && $this->isCacheValid($episodesFile, null, $isSpecialSeries)) {
            $this->utilHandler->log("Episodenliste für Serie $seriesId aus Cache geladen");
            $allEpisodes = json_decode(file_get_contents($episodesFile), true);
            
            // In Memory-Cache speichern
            $this->episodesMemoryCache[$cacheKey] = $allEpisodes;
            
            return $allEpisodes;
        }
        
        // Episoden abrufen
        $allEpisodes = [];
        $page = 1;
        $hasMorePages = true;
        
        while ($hasMorePages) {
            $response = $this->apiRequest('/series/' . $seriesId . '/episodes', ['page' => $page]);
            
            if (!$response || !isset($response['data'])) {
                break;
            }
            
            $episodes = $response['data'];
            $allEpisodes = array_merge($allEpisodes, $episodes);
            
            // Prüfen, ob es weitere Seiten gibt
            $hasMorePages = isset($response['links']['next']);
            $page++;
            
            // Kurze Pause zwischen Anfragen
            usleep(500000); // 500ms Pause
        }
        
        if (empty($allEpisodes)) {
            $this->utilHandler->log("Keine Episoden für Serie mit ID $seriesId gefunden");
            return null;
        }
        
        // Deutsche Übersetzungen für Episoden abrufen
        $germanEpisodes = [];
        $translationsResponse = $this->apiRequest('/series/' . $seriesId . '/episodes/translations/deu');
        
        if ($translationsResponse && isset($translationsResponse['data'])) {
            $translations = $translationsResponse['data'];
            
            // Übersetzungen nach Episoden-ID indizieren
            foreach ($translations as $translation) {
                if (isset($translation['episodeId'])) {
                    $germanEpisodes[$translation['episodeId']] = $translation;
                }
            }
            
            $this->utilHandler->log("Deutsche Übersetzungen für " . count($germanEpisodes) . " Episoden gefunden");
        }
        
        // Deutsche Übersetzungen in die Episoden einfügen und als Hauptwerte setzen
        foreach ($allEpisodes as $key => $episode) {
            if (isset($episode['id']) && isset($germanEpisodes[$episode['id']])) {
                $translation = $germanEpisodes[$episode['id']];
                
                // Originale Werte als Fallback speichern
                if (!isset($allEpisodes[$key]['originalEpisodeName'])) {
                    $allEpisodes[$key]['originalEpisodeName'] = $episode['episodeName'];
                }
                if (!isset($allEpisodes[$key]['originalOverview'])) {
                    $allEpisodes[$key]['originalOverview'] = $episode['overview'];
                }
                
                // Deutsche Übersetzungen als Hauptwerte setzen
                if (isset($translation['name']) && !empty($translation['name'])) {
                    $allEpisodes[$key]['episodeName'] = $translation['name'];
                    $allEpisodes[$key]['germanName'] = $translation['name'];
                }
                if (isset($translation['overview']) && !empty($translation['overview'])) {
                    $allEpisodes[$key]['overview'] = $translation['overview'];
                    $allEpisodes[$key]['germanOverview'] = $translation['overview'];
                }
            }
            
            // Zeitstempel hinzufügen
            $allEpisodes[$key]['cachedAt'] = time();
        }
        
        // Im Cache speichern
        file_put_contents($episodesFile, json_encode($allEpisodes, JSON_PRETTY_PRINT));
        
        // Im Memory-Cache speichern
        $this->episodesMemoryCache[$cacheKey] = $allEpisodes;
        
        $this->utilHandler->log("Episodenliste für Serie $seriesId von TheTVDB abgerufen und im Cache gespeichert (Anzahl: " . count($allEpisodes) . ")");
        
        return $allEpisodes;
    }
}