<?php
/**
 * SerienRecorderAPI
 * 
 * Klasse für externe API-Aufrufe zum Abrufen von Serieninformationen
 * mit erweitertem Caching und TMDB-Integration
 * 
 * @version 2.0.0
 * @changelog
 * - 2.0.0: Grundlegende Überarbeitung der TMDB-Integration:
 *   - Implementierung hierarchisches Caching für effizienteren Datenabruf
 *   - Vollständige Serien- und Episodendaten-Abfrage mit detaillierten Informationen
 *   - Erweiterte Suchfunktionen mit zusätzlichen Parametern und besserer Trefferquote
 *   - Strukturierte Speicherung der Metadaten in Verzeichnishierarchie
 *   - Verbesserte Fehlerbehandlung und Ratelimit-Prüfung
 *   - Hintergrundabruf für komplette Serien
 *   - Konfigurierbare Cache-Lebensdauer
 * - 1.0.0: Erste Version - TMDB API-Integration für Episodeninformationen
 */
class SerienRecorderAPI {
    private $config;
    private $utilHandler;
    private $cache = [];
    private $cacheFile = '';
    private $apiKey = '';
    private $metadataDir = '';
    private $cacheLifetime = 168; // Standard: 7 Tage in Stunden
    private $includeSpecials = false;
    private $lastRequestTime = 0;
    private $requestInterval = 0.25; // 250ms zwischen Anfragen (max. 4 Anfragen/Sekunde)
    
    /**
     * Konstruktor
     * @param array $config Konfigurationsarray
     * @param SerienRecorderUtil $utilHandler Utility-Handler
     */
    public function __construct($config, $utilHandler) {
        $this->config = $config;
        $this->utilHandler = $utilHandler;
        
        // API-Key aus Konfiguration lesen
        $this->apiKey = isset($config['tmdb_api_key']) ? $config['tmdb_api_key'] : '';
        
        // Erweiterte TMDB-Konfiguration auslesen
        if (isset($config['tmdb']) && is_array($config['tmdb'])) {
            if (isset($config['tmdb']['api_key']) && !empty($config['tmdb']['api_key'])) {
                $this->apiKey = $config['tmdb']['api_key'];
            }
            
            if (isset($config['tmdb']['cache_lifetime'])) {
                $this->cacheLifetime = (int)$config['tmdb']['cache_lifetime'];
            }
            
            if (isset($config['tmdb']['include_specials'])) {
                $this->includeSpecials = (bool)$config['tmdb']['include_specials'];
            }
            
            if (isset($config['tmdb']['metadata_dir']) && !empty($config['tmdb']['metadata_dir'])) {
                $this->metadataDir = $config['tmdb']['metadata_dir'];
            }
        }
        
        // Wenn kein spezielles Metadaten-Verzeichnis angegeben ist, Standard-Cache-Verzeichnis verwenden
        if (empty($this->metadataDir)) {
            $this->metadataDir = $this->config['cache_dir'] . '/tmdb_metadata';
        }
        
        // Verzeichnis für Metadaten erstellen, falls nicht vorhanden
        if (!is_dir($this->metadataDir)) {
            mkdir($this->metadataDir, 0755, true);
        }
        
        // Legacy-Cache-Datei für die Rückwärtskompatibilität
        $this->cacheFile = $this->config['cache_dir'] . '/episode_cache.json';
        
        // Legacy-Cache laden, falls vorhanden (für Rückwärtskompatibilität)
        $this->loadLegacyCache();
    }
    
    /**
     * Setzt oder aktualisiert Konfigurationseinstellungen
     * @param array $config Zu aktualisierende Konfigurationseinstellungen
     */
    public function setConfig($config) {
        $this->config = $config;
        
        // API-Key aktualisieren, falls in der Konfiguration vorhanden
        if (isset($config['tmdb_api_key'])) {
            $this->apiKey = $config['tmdb_api_key'];
        }
        
        // Erweiterte TMDB-Konfiguration aktualisieren
        if (isset($config['tmdb']) && is_array($config['tmdb'])) {
            if (isset($config['tmdb']['api_key']) && !empty($config['tmdb']['api_key'])) {
                $this->apiKey = $config['tmdb']['api_key'];
            }
            
            if (isset($config['tmdb']['cache_lifetime'])) {
                $this->cacheLifetime = (int)$config['tmdb']['cache_lifetime'];
            }
            
            if (isset($config['tmdb']['include_specials'])) {
                $this->includeSpecials = (bool)$config['tmdb']['include_specials'];
            }
            
            if (isset($config['tmdb']['metadata_dir']) && !empty($config['tmdb']['metadata_dir'])) {
                $this->metadataDir = $config['tmdb']['metadata_dir'];
            }
        }
    }
    
    /**
     * Lädt den Legacy-Cache aus der Datei (für Rückwärtskompatibilität)
     */
    private function loadLegacyCache() {
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            $data = json_decode($content, true);
            
            if (is_array($data)) {
                $this->cache = $data;
                $this->utilHandler->log("Legacy-Episode-Cache geladen: " . count($this->cache) . " Einträge");
            }
        }
    }
    
    /**
     * Speichert den Legacy-Cache in der Datei (für Rückwärtskompatibilität)
     */
    private function saveLegacyCache() {
        $content = json_encode($this->cache);
        file_put_contents($this->cacheFile, $content);
        $this->utilHandler->log("Legacy-Episode-Cache gespeichert: " . count($this->cache) . " Einträge");
    }
    
    /**
     * Generiert den Pfad zu einer Serie in der Metadaten-Verzeichnisstruktur
     * @param int $seriesId TMDB-ID der Serie
     * @return string Pfad zum Serien-Verzeichnis
     */
    private function getSeriesDirectoryPath($seriesId) {
        return $this->metadataDir . '/series_' . $seriesId;
    }
    
    /**
     * Generiert den Pfad zu einer Staffel in der Metadaten-Verzeichnisstruktur
     * @param int $seriesId TMDB-ID der Serie
     * @param int $seasonNumber Staffelnummer
     * @return string Pfad zum Staffel-Verzeichnis
     */
    private function getSeasonDirectoryPath($seriesId, $seasonNumber) {
        return $this->getSeriesDirectoryPath($seriesId) . '/season_' . $seasonNumber;
    }
    
    /**
     * Generiert den Pfad zu einer Episoden-Datei in der Metadaten-Verzeichnisstruktur
     * @param int $seriesId TMDB-ID der Serie
     * @param int $seasonNumber Staffelnummer
     * @param int $episodeNumber Episodennummer
     * @return string Pfad zur Episoden-Datei
     */
    private function getEpisodeFilePath($seriesId, $seasonNumber, $episodeNumber) {
        return $this->getSeasonDirectoryPath($seriesId, $seasonNumber) . '/episode_' . $episodeNumber . '.json';
    }
    
    /**
     * Generiert den Pfad zur Serieninfo-Datei in der Metadaten-Verzeichnisstruktur
     * @param int $seriesId TMDB-ID der Serie
     * @return string Pfad zur Serieninfo-Datei
     */
    private function getSeriesInfoFilePath($seriesId) {
        return $this->getSeriesDirectoryPath($seriesId) . '/series_info.json';
    }
    
    /**
     * Generiert den Pfad zur Staffelinfo-Datei in der Metadaten-Verzeichnisstruktur
     * @param int $seriesId TMDB-ID der Serie
     * @param int $seasonNumber Staffelnummer
     * @return string Pfad zur Staffelinfo-Datei
     */
    private function getSeasonInfoFilePath($seriesId, $seasonNumber) {
        return $this->getSeasonDirectoryPath($seriesId, $seasonNumber) . '/season_info.json';
    }
    
    /**
     * Prüft, ob eine Datei im Cache noch gültig ist
     * @param string $filePath Pfad zur Datei
     * @param int $cacheLifetime Cache-Lebensdauer in Stunden (optional)
     * @return bool Ist die Datei noch gültig?
     */
    private function isCacheValid($filePath, $cacheLifetime = null) {
        if (!file_exists($filePath)) {
            return false;
        }
        
        // Wenn keine spezifische Lebensdauer angegeben wurde, Standard-Lebensdauer verwenden
        if ($cacheLifetime === null) {
            $cacheLifetime = $this->cacheLifetime;
        }
        
        $fileAge = time() - filemtime($filePath);
        $maxAge = $cacheLifetime * 3600; // Umrechnung in Sekunden
        
        return $fileAge <= $maxAge;
    }
    
    /**
     * Führt einen API-Request an TMDB durch mit Ratelimit-Berücksichtigung
     * @param string $endpoint API-Endpunkt
     * @param array $params Zusätzliche Parameter
     * @return array|null API-Antwort oder null bei Fehler
     */
    private function apiRequest($endpoint, $params = []) {
        if (empty($this->apiKey)) {
            echo "<br>ERROR: TMDB API-Key nicht gesetzt<br>";
            $this->utilHandler->log("TMDB API-Key nicht gesetzt");
            return null;
        }
        
        // Ratelimit berücksichtigen - maximal 4 Anfragen pro Sekunde (250ms zwischen Anfragen)
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        if ($timeSinceLastRequest < $this->requestInterval) {
            $sleepTime = ($this->requestInterval - $timeSinceLastRequest) * 1000000; // Mikrosekunden
            usleep($sleepTime);
        }
        
        // Aktualisiere die Zeit der letzten Anfrage
        $this->lastRequestTime = microtime(true);
        
        // Basis-URL für TMDB API V3
        $baseUrl = 'https://api.themoviedb.org/3';
        
        // API-Key und Sprache zu den Parametern hinzufügen
        $params['api_key'] = $this->apiKey;
        $params['language'] = 'de-DE'; // Deutsche Sprache für die Ergebnisse
        
        // URL mit Parametern erstellen
        $url = $baseUrl . $endpoint . '?' . http_build_query($params);
        
        // Debug-Ausgabe der vollständigen URL (API-Key maskieren für Sicherheit)
        $debugUrl = str_replace($this->apiKey, 'API_KEY_HIDDEN', $url);
        //echo "<br>DEBUG: TMDB API-Anfrage URL: $debugUrl<br>";
        $this->utilHandler->log("TMDB API-Anfrage: " . $debugUrl);
        
        // cURL für den API-Aufruf vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Längeres Timeout für große Datenmengen
        curl_setopt($ch, CURLOPT_USERAGENT, 'PHPSerienRecorder/2.12.5');
        
        // Verbindungsfehler vermeiden
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // API-Aufruf ausführen
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Debug-Ausgabe des HTTP-Status
        //echo "<br>DEBUG: HTTP-Status: $httpCode<br>";
        
        // Prüfung auf Rate-Limit-Überschreitung
        if ($httpCode === 429) {
            $retryAfter = curl_getinfo($ch, CURLINFO_RETRY_AFTER);
            $retryAfter = ($retryAfter > 0) ? $retryAfter : 10; // Mindestens 10 Sekunden warten
            
            $this->utilHandler->log("TMDB Rate-Limit überschritten. Warte " . $retryAfter . " Sekunden.");
            echo "<br>RATE-LIMIT: TMDB Rate-Limit überschritten. Warte " . $retryAfter . " Sekunden.<br>";
            
            // Verbindung schließen, warten und neuen Versuch starten
            curl_close($ch);
            sleep($retryAfter);
            return $this->apiRequest($endpoint, $params); // Rekursiver Aufruf nach dem Warten
        }
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            echo "<br>ERROR: Curl-Fehler bei API-Anfrage: $error<br>";
            $this->utilHandler->log("Curl-Fehler bei API-Anfrage: " . $error);
            curl_close($ch);
            return null;
        }
        
        curl_close($ch);
        
        // Prüfen, ob die Anfrage erfolgreich war
        if ($httpCode !== 200) {
            echo "<br>ERROR: HTTP-Fehler bei API-Anfrage: $httpCode<br>";
            if ($result) {
                $errorData = json_decode($result, true);
                if (isset($errorData['status_message'])) {
                    echo "<br>ERROR-Details: " . htmlspecialchars($errorData['status_message']) . "<br>";
                } else {
                    echo "<br>ERROR-Details: " . htmlspecialchars($result) . "<br>";
                }
            }
            $this->utilHandler->log("HTTP-Fehler bei API-Anfrage: " . $httpCode);
            return null;
        }
        
        // Daten verarbeiten
        $data = json_decode($result, true);
        if (!$data) {
            echo "<br>ERROR: Fehler beim Decodieren der JSON-Antwort<br>";
            $this->utilHandler->log("Fehler beim Decodieren der API-Antwort");
            return null;
        }
        
        return $data;
    }
    
    /**
     * Erweiterte Seriensuche mit zusätzlichen Fallback-Optionen
     * @param string $seriesName Serienname
     * @param int $year Optional: Jahr der Serie für genauere Ergebnisse
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @return array|null Serie-ID und -Name oder null bei Fehler
     */
    public function enhancedSeriesSearch($seriesName, $year = null, $forceRefresh = false) {
        // Cache-Schlüssel generieren
        $cacheKey = 'search_' . md5($seriesName . ($year ? '_' . $year : ''));
        $cacheFile = $this->metadataDir . '/' . $cacheKey . '.json';
        
        // Prüfen, ob Daten im Cache vorhanden sind
        if (!$forceRefresh && $this->isCacheValid($cacheFile)) {
            $this->utilHandler->log("Seriensuche für '$seriesName' aus Cache geladen");
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            return $cachedData;
        }
        
        // Parameter für die Suche
        $params = [
            'query' => $seriesName,
            'include_adult' => 'false'
        ];
        
        // Jahr hinzufügen, falls vorhanden
        if ($year !== null) {
            $params['first_air_date_year'] = $year;
        }
        
        // Suche durchführen
        $results = $this->apiRequest('/search/tv', $params);
        
        if (!$results || !isset($results['results']) || empty($results['results'])) {
            $this->utilHandler->log("Keine Serien für '$seriesName'" . ($year ? " (Jahr: $year)" : "") . " gefunden");
            return null;
        }
        
        // Beste Übereinstimmung finden
        $bestMatch = null;
        $highestScore = 0;
        
        foreach ($results['results'] as $show) {
            // Prüfen, ob der Name exakt übereinstimmt
            if (strcasecmp($show['name'], $seriesName) === 0) {
                $this->utilHandler->log("Exakte Übereinstimmung für '$seriesName' gefunden: " . $show['name'] . " (ID: " . $show['id'] . ")");
                $bestMatch = [
                    'id' => $show['id'],
                    'name' => $show['name'],
                    'original_name' => $show['original_name'],
                    'first_air_date' => $show['first_air_date'],
                    'poster_path' => $show['poster_path'],
                    'backdrop_path' => $show['backdrop_path'],
                    'overview' => $show['overview'],
                    'popularity' => $show['popularity'],
                    'match_type' => 'exact',
                    'match_score' => 100
                ];
                break;
            }
            
            // Ähnlichkeit berechnen
            similar_text($seriesName, $show['name'], $score);
            
            // Zusätzliche Punkte für Jahr-Übereinstimmung
            if ($year !== null && isset($show['first_air_date']) && strpos($show['first_air_date'], $year) === 0) {
                $score += 10; // Bonus für Jahr-Übereinstimmung
            }
            
            if ($score > $highestScore) {
                $highestScore = $score;
                $bestMatch = [
                    'id' => $show['id'],
                    'name' => $show['name'],
                    'original_name' => $show['original_name'],
                    'first_air_date' => $show['first_air_date'],
                    'poster_path' => $show['poster_path'],
                    'backdrop_path' => $show['backdrop_path'],
                    'overview' => $show['overview'],
                    'popularity' => $show['popularity'],
                    'match_type' => 'similarity',
                    'match_score' => $score
                ];
            }
        }
        
        // Wenn die Ähnlichkeit hoch genug ist, diese Serie verwenden
        if ($bestMatch && ($bestMatch['match_type'] === 'exact' || $bestMatch['match_score'] > 60)) {
            $this->utilHandler->log("Beste Übereinstimmung für '$seriesName': " . $bestMatch['name'] . 
                                " (ID: " . $bestMatch['id'] . ", Ähnlichkeit: " . round($bestMatch['match_score']) . "%)");
            
            // Im Cache speichern
            file_put_contents($cacheFile, json_encode($bestMatch, JSON_PRETTY_PRINT));
            
            return $bestMatch;
        }
        
        $this->utilHandler->log("Keine ausreichend ähnliche Serie für '$seriesName' gefunden");
        return null;
    }
    
    /**
     * Ruft vollständige Serien-Details mit allen Staffel- und Episodeninformationen ab
     * @param int $seriesId TMDB-ID der Serie
     * @param bool $includeImages Sollen Bilder abgerufen werden?
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @return array|null Vollständige Serien-Informationen
     */
    public function getCompleteSeriesInfo($seriesId, $includeImages = false, $forceRefresh = false) {
        // Pfade für die Serieninfo-Datei
        $seriesDir = $this->getSeriesDirectoryPath($seriesId);
        $seriesInfoFile = $this->getSeriesInfoFilePath($seriesId);
        
        // Prüfen, ob die Verzeichnisse existieren, wenn nicht, erstellen
        if (!is_dir($seriesDir)) {
            mkdir($seriesDir, 0755, true);
        }
        
        // Prüfen, ob Serien-Infos im Cache vorhanden sind
        $needsRefresh = $forceRefresh || !$this->isCacheValid($seriesInfoFile);
        
        // Wenn kein Refresh nötig ist, vorhandene Daten laden
        if (!$needsRefresh) {
            $this->utilHandler->log("Lade Serieninfo für ID $seriesId aus Cache");
            
            // Prüfen, ob die Datei existiert und gültig ist
            if (file_exists($seriesInfoFile)) {
                $seriesData = json_decode(file_get_contents($seriesInfoFile), true);
                
                // Vollständigkeits-Check der Daten
                if ($seriesData && isset($seriesData['id']) && isset($seriesData['name'])) {
                    return $this->loadCompleteSeriesFromCache($seriesId);
                }
            }
        }
        
        $this->utilHandler->log("Hole komplette Serieninfos für ID $seriesId von TMDB");
        
        // Details der Serie mit zusätzlichen Daten abrufen
        $appendToResponse = ['external_ids', 'credits'];
        if ($includeImages) {
            $appendToResponse[] = 'images';
        }
        
        $params = [
            'append_to_response' => implode(',', $appendToResponse)
        ];
        
        $seriesData = $this->apiRequest("/tv/$seriesId", $params);
        
        if (!$seriesData || !isset($seriesData['id'])) {
            $this->utilHandler->log("Fehler beim Abrufen der Serieninfos für ID $seriesId");
            return null;
        }
        
        // Serien-Kerninfos speichern
        $seriesInfo = [
            'id' => $seriesData['id'],
            'name' => $seriesData['name'],
            'original_name' => $seriesData['original_name'],
            'overview' => $seriesData['overview'],
            'first_air_date' => $seriesData['first_air_date'],
            'last_air_date' => $seriesData['last_air_date'],
            'status' => $seriesData['status'],
            'number_of_seasons' => $seriesData['number_of_seasons'],
            'number_of_episodes' => $seriesData['number_of_episodes'],
            'popularity' => $seriesData['popularity'],
            'vote_average' => $seriesData['vote_average'],
            'poster_path' => $seriesData['poster_path'],
            'backdrop_path' => $seriesData['backdrop_path'],
            'external_ids' => isset($seriesData['external_ids']) ? $seriesData['external_ids'] : [],
            'updated_at' => time()
        ];
        
        // Serien-Infos speichern
        file_put_contents($seriesInfoFile, json_encode($seriesInfo, JSON_PRETTY_PRINT));
        
        // Für jede Staffel die Details und Episoden abrufen
        if (isset($seriesData['seasons']) && is_array($seriesData['seasons'])) {
            foreach ($seriesData['seasons'] as $season) {
                // Spezielle Staffeln überspringen, wenn nicht gewünscht
                if ($season['season_number'] === 0 && !$this->includeSpecials) {
                    continue;
                }
                
                $this->fetchAndStoreSeasonInfo($seriesId, $season['season_number']);
            }
        }
        
        // Vollständige Serieninformationen zurückgeben
        return $this->loadCompleteSeriesFromCache($seriesId);
    }
    
    /**
     * Lädt eine komplette Serie mit allen Staffeln und Episoden aus dem Cache
     * @param int $seriesId TMDB-ID der Serie
     * @return array Vollständige Serieninformationen
     */
    private function loadCompleteSeriesFromCache($seriesId) {
        $seriesInfoFile = $this->getSeriesInfoFilePath($seriesId);
        
        if (!file_exists($seriesInfoFile)) {
            $this->utilHandler->log("Serien-Info-Datei für ID $seriesId nicht gefunden");
            return null;
        }
        
        $seriesData = json_decode(file_get_contents($seriesInfoFile), true);
        
        if (!$seriesData || !isset($seriesData['id'])) {
            $this->utilHandler->log("Ungültige Serien-Info-Datei für ID $seriesId");
            return null;
        }
        
        // Staffel-Informationen laden
        $seriesData['seasons'] = [];
        $seriesDir = $this->getSeriesDirectoryPath($seriesId);
        
        // Alle Staffel-Verzeichnisse durchsuchen
        $seasonDirs = glob($seriesDir . '/season_*', GLOB_ONLYDIR);
        
        foreach ($seasonDirs as $seasonDir) {
            // Staffelnummer aus dem Verzeichnisnamen extrahieren
            if (preg_match('/season_(\d+)$/', $seasonDir, $matches)) {
                $seasonNumber = (int)$matches[1];
                $seasonInfoFile = $this->getSeasonInfoFilePath($seriesId, $seasonNumber);
                
                if (file_exists($seasonInfoFile)) {
                    $seasonData = json_decode(file_get_contents($seasonInfoFile), true);
                    
                    if ($seasonData && isset($seasonData['season_number'])) {
                        // Episoden-Informationen laden
                        $seasonData['episodes'] = [];
                        $episodeFiles = glob($seasonDir . '/episode_*.json');
                        
                        foreach ($episodeFiles as $episodeFile) {
                            if (preg_match('/episode_(\d+)\.json$/', $episodeFile, $matches)) {
                                $episodeNumber = (int)$matches[1];
                                $episodeData = json_decode(file_get_contents($episodeFile), true);
                                
                                if ($episodeData && isset($episodeData['episode_number'])) {
                                    $seasonData['episodes'][] = $episodeData;
                                }
                            }
                        }
                        
                        // Episoden nach Nummer sortieren
                        usort($seasonData['episodes'], function($a, $b) {
                            return $a['episode_number'] - $b['episode_number'];
                        });
                        
                        $seriesData['seasons'][] = $seasonData;
                    }
                }
            }
        }
        
        // Staffeln nach Nummer sortieren
        usort($seriesData['seasons'], function($a, $b) {
            return $a['season_number'] - $b['season_number'];
        });
        
        return $seriesData;
    }
    
    /**
     * Ruft Staffel- und Episodeninformationen ab und speichert sie im Cache
     * @param int $seriesId TMDB-ID der Serie
     * @param int $seasonNumber Staffelnummer
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @return array|null Staffelinformationen mit Episoden
     */
    public function fetchAndStoreSeasonInfo($seriesId, $seasonNumber, $forceRefresh = false) {
        // Pfade für die Staffelinfo-Datei und das Verzeichnis
        $seasonDir = $this->getSeasonDirectoryPath($seriesId, $seasonNumber);
        $seasonInfoFile = $this->getSeasonInfoFilePath($seriesId, $seasonNumber);
        
        // Prüfen, ob die Verzeichnisse existieren, wenn nicht, erstellen
        if (!is_dir($seasonDir)) {
            mkdir($seasonDir, 0755, true);
        }
        
        // Prüfen, ob Staffel-Infos im Cache vorhanden sind
        $needsRefresh = $forceRefresh || !$this->isCacheValid($seasonInfoFile);
        
        // Wenn kein Refresh nötig ist, vorhandene Daten laden
        if (!$needsRefresh && file_exists($seasonInfoFile)) {
            $this->utilHandler->log("Lade Staffelinfo für Serie $seriesId, Staffel $seasonNumber aus Cache");
            
            $seasonData = json_decode(file_get_contents($seasonInfoFile), true);
            
            // Vollständigkeits-Check der Daten
            if ($seasonData && isset($seasonData['season_number'])) {
                return $seasonData;
            }
        }
        
        $this->utilHandler->log("Hole Staffelinfos für Serie $seriesId, Staffel $seasonNumber von TMDB");
        
        // Staffelinformationen abrufen
        $seasonData = $this->apiRequest("/tv/$seriesId/season/$seasonNumber");
        
        if (!$seasonData || !isset($seasonData['season_number'])) {
            $this->utilHandler->log("Fehler beim Abrufen der Staffelinfos für Serie $seriesId, Staffel $seasonNumber");
            return null;
        }
        
        // Staffel-Kerninfos speichern
        $seasonInfo = [
            'season_number' => $seasonData['season_number'],
            'name' => $seasonData['name'],
            'overview' => $seasonData['overview'],
            'air_date' => $seasonData['air_date'],
            'poster_path' => $seasonData['poster_path'],
            'episode_count' => count($seasonData['episodes']),
            'updated_at' => time()
        ];
        
        // Staffel-Infos speichern
        file_put_contents($seasonInfoFile, json_encode($seasonInfo, JSON_PRETTY_PRINT));
        
        // Episoden-Informationen speichern
        if (isset($seasonData['episodes']) && is_array($seasonData['episodes'])) {
            foreach ($seasonData['episodes'] as $episode) {
                $episodeFile = $this->getEpisodeFilePath($seriesId, $seasonNumber, $episode['episode_number']);
                file_put_contents($episodeFile, json_encode($episode, JSON_PRETTY_PRINT));
            }
        }
        
        return $seasonData;
    }
    
    /**
     * Sucht Serieninformationen über die TMDB API
     * Rückwärtskompatible Methode mit dem alten Verhalten
     * @param string $seriesName Name der Serie
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @return array|null Serieninformationen oder null bei Fehler
     */
    public function getSeriesInfo($seriesName, $forceRefresh = false) {
        // Legacy-Cache verwenden für Rückwärtskompatibilität
        $cacheKey = md5($seriesName);
        
        // Prüfe, ob Daten im Legacy-Cache vorhanden sind
        if (!$forceRefresh && isset($this->cache[$cacheKey])) {
            $cachedData = $this->cache[$cacheKey];
            
            // Prüfe, ob der Cache noch gültig ist (maximal 7 Tage)
            if (isset($cachedData['timestamp']) && (time() - $cachedData['timestamp']) < (7 * 24 * 3600)) {
                $this->utilHandler->log("Serieninfo für '$seriesName' aus Legacy-Cache geladen");
                return $cachedData['data'];
            }
        }
        
        // Neue API-Methoden verwenden
        $series = $this->enhancedSeriesSearch($seriesName, null, $forceRefresh);
        
        if (!$series) {
            return null;
        }
        
        // Vollständige Serieninformationen abrufen
        $seriesData = $this->getCompleteSeriesInfo($series['id'], false, $forceRefresh);
        
        if (!$seriesData) {
            return null;
        }
        
        // Daten für Legacy-Format transformieren
        $legacyData = $this->transformToLegacyFormat($seriesData);
        
        // Im Legacy-Cache speichern
        $this->cache[$cacheKey] = [
            'timestamp' => time(),
            'data' => $legacyData
        ];
        
        // Legacy-Cache speichern
        $this->saveLegacyCache();
        
        return $legacyData;
    }
    
    /**
     * Transformiert das neue Datenformat in das Legacy-Format für Rückwärtskompatibilität
     * @param array $seriesData Serieninformationen im neuen Format
     * @return array Serieninformationen im Legacy-Format
     */
    private function transformToLegacyFormat($seriesData) {
        $seriesInfo = [
            'seriesData' => [
                'id' => $seriesData['id'],
                'name' => $seriesData['name'],
                'original_name' => $seriesData['original_name'],
                'overview' => $seriesData['overview'],
                'first_air_date' => $seriesData['first_air_date'],
                'last_air_date' => $seriesData['last_air_date'],
                'status' => $seriesData['status'],
                'number_of_seasons' => $seriesData['number_of_seasons'],
                'number_of_episodes' => $seriesData['number_of_episodes'],
                'popularity' => $seriesData['popularity'],
                'vote_average' => $seriesData['vote_average'],
                'poster_path' => $seriesData['poster_path'],
                'backdrop_path' => $seriesData['backdrop_path'],
                'seasons' => []
            ],
            'episodes' => []
        ];
        
        // Episoden-Array erstellen
        if (isset($seriesData['seasons']) && is_array($seriesData['seasons'])) {
            foreach ($seriesData['seasons'] as $season) {
                // Staffel dem Legacy-Format hinzufügen
                $seriesInfo['seriesData']['seasons'][] = [
                    'season_number' => $season['season_number'],
                    'name' => $season['name'],
                    'overview' => $season['overview'],
                    'air_date' => $season['air_date'],
                    'episode_count' => count($season['episodes']),
                    'poster_path' => $season['poster_path']
                ];
                
                // Episoden extrahieren
                if (isset($season['episodes']) && is_array($season['episodes'])) {
                    foreach ($season['episodes'] as $episode) {
                        $seriesInfo['episodes'][] = [
                            'season' => $episode['season_number'],
                            'episode' => $episode['episode_number'],
                            'name' => $episode['name'],
                            'air_date' => $episode['air_date'],
                            'overview' => $episode['overview']
                        ];
                    }
                }
            }
        }
        
        return $seriesInfo;
    }
    
    /**
     * Sucht eine Episode basierend auf verschiedenen Kriterien
     * @param int $seriesId TMDB-ID der Serie
     * @param string $episodeName Episodentitel
     * @param string $airdate Ausstrahlungsdatum (YYYY-MM-DD)
     * @return array|null Gefundene Episode oder null
     */
    public function findEpisodeByNameOrDate($seriesId, $episodeName = null, $airdate = null) {
        if (empty($seriesId) || (empty($episodeName) && empty($airdate))) {
            return null;
        }
        
        // Lade komplette Serieninformationen
        $seriesData = $this->loadCompleteSeriesFromCache($seriesId);
        
        if (!$seriesData || !isset($seriesData['seasons'])) {
            // Versuche Daten von der API zu laden
            $seriesData = $this->getCompleteSeriesInfo($seriesId);
            if (!$seriesData || !isset($seriesData['seasons'])) {
                return null;
            }
        }
        
        $possibleMatches = [];
        
        // Durchsuche alle Staffeln und Episoden
        foreach ($seriesData['seasons'] as $season) {
            if (!isset($season['episodes']) || !is_array($season['episodes'])) {
                continue;
            }
            
            foreach ($season['episodes'] as $episode) {
                $score = 0;
                $matchType = [];
                
                // Match nach Episodentitel
                if (!empty($episodeName) && !empty($episode['name'])) {
                    $normalizedInputName = $this->normalizeTitle($episodeName);
                    $normalizedEpisodeName = $this->normalizeTitle($episode['name']);
                    
                    // Exakte Übereinstimmung
                    if ($normalizedInputName === $normalizedEpisodeName) {
                        $score += 100;
                        $matchType[] = "Exakter Titel";
                    }
                    // Titel ist im anderen enthalten
                    else if (strpos($normalizedEpisodeName, $normalizedInputName) !== false) {
                        $score += 70;
                        $matchType[] = "Titel als Teil";
                    }
                    else if (strpos($normalizedInputName, $normalizedEpisodeName) !== false) {
                        $score += 60;
                        $matchType[] = "Teiltitel";
                    }
                    // Ähnlichkeitsscore
                    else {
                        similar_text($normalizedInputName, $normalizedEpisodeName, $similarityScore);
                        if ($similarityScore > 60) {
                            $score += $similarityScore * 0.6;
                            $matchType[] = "Ähnlichkeit ($similarityScore%)";
                        }
                    }
                }
                
                // Match nach Ausstrahlungsdatum
                if (!empty($airdate) && !empty($episode['air_date'])) {
                    if ($episode['air_date'] === $airdate) {
                        $score += 80;
                        $matchType[] = "Ausstrahlungsdatum";
                    }
                    // Nahe am Datum (±3 Tage)
                    else {
                        $dateDiff = abs(strtotime($episode['air_date']) - strtotime($airdate)) / 86400;
                        if ($dateDiff <= 3) {
                            $score += 40;
                            $matchType[] = "Nahes Datum (±$dateDiff Tage)";
                        }
                    }
                }
                
                // Wenn der Score hoch genug ist, als mögliche Übereinstimmung speichern
                if ($score > 20) {
                    $possibleMatches[] = [
                        'episode' => $episode,
                        'score' => $score,
                        'matchType' => implode(", ", $matchType)
                    ];
                }
            }
        }
        
        // Sortiere mögliche Übereinstimmungen nach Score
        usort($possibleMatches, function($a, $b) {
            return $b['score'] - $a['score'];
        });
        
        // Debug-Ausgabe für gefundene Übereinstimmungen
        $this->utilHandler->log("Anzahl gefundener möglicher Übereinstimmungen: " . count($possibleMatches));
        foreach ($possibleMatches as $index => $match) {
            if ($index < 3) { // Zeige nur die besten 3
                $this->utilHandler->log("Match #" . ($index+1) . ": S" . $match['episode']['season_number'] . 
                                     "E" . $match['episode']['episode_number'] . " - " . 
                                     $match['episode']['name'] . " (Score: " . $match['score'] . 
                                     ", Match: " . $match['matchType'] . ")");
            }
        }
        
        // Beste Übereinstimmung zurückgeben, falls vorhanden
        if (!empty($possibleMatches) && $possibleMatches[0]['score'] >= 40) {
            return $possibleMatches[0]['episode'];
        }
        
        return null;
    }
    
    /**
     * Sucht fehlende Episodeninformationen für eine Sendung
     * @param array &$broadcast Broadcast-Daten (wird direkt aktualisiert)
     * @param bool $forceRefresh Erzwinge das Neuladen der Daten
     * @param bool $includeSpecials Sollen Spezial-Episoden (Staffel 0) eingeschlossen werden?
     * @return bool Erfolg der Aktualisierung
     */
    public function fetchMissingEpisodeInfo(&$broadcast, $forceRefresh = false, $includeSpecials = null) {
        // Wenn includeSpecials nicht spezifiziert ist, verwende die Klassenkonfiguration
        if ($includeSpecials === null) {
            $includeSpecials = $this->includeSpecials;
        }
        
        // Temporär Specials einschließen für diese Anfrage
        $originalIncludeSpecials = $this->includeSpecials;
        $this->includeSpecials = $includeSpecials;
        
        // Zu Debugging-Zwecken die aktuelle Konfiguration ausgeben
        //echo "<br>DEBUG: fetchMissingEpisodeInfo mit includeSpecials=" . ($this->includeSpecials ? "true" : "false") . "<br>";
        
        // Bei S00E00 Formaten IMMER nach neuen Infos suchen, auch wenn bereits Daten vorhanden sind
        $hasZeroValues = isset($broadcast['season']) && isset($broadcast['episode']) && 
                          $broadcast['season'] == 0 && $broadcast['episode'] == 0;
        
        // Nur anwenden, wenn Episodeninformationen fehlen oder explizit ein Refresh angefordert wird
        // oder wenn wir bei S00E00 sind (was auf fehlende Informationen hindeutet)
        if (!$forceRefresh && !$hasZeroValues && isset($broadcast['season']) && isset($broadcast['episode']) && 
            ($broadcast['season'] > 0 || $broadcast['episode'] > 0)) {
            $this->includeSpecials = $originalIncludeSpecials; // Ursprungszustand wiederherstellen
            return true;
        }
        
        $seriesName = $broadcast['title'];
        $episodeName = isset($broadcast['episodeName']) ? $broadcast['episodeName'] : '';
        
        //echo "<br>DEBUG: Suche Episodeninformationen für '$seriesName'" . 
            (!empty($episodeName) ? " - Episodentitel: '$episodeName'" : "") . "<br>";
        
        // Ausstrahlungsdatum im Format YYYY-MM-DD extrahieren
        $airdate = '';
        if (isset($broadcast['start'])) {
            // Format der start-Variable: YYYYMMDDHHMMSS +0000
            $year = substr($broadcast['start'], 0, 4);
            $month = substr($broadcast['start'], 4, 2);
            $day = substr($broadcast['start'], 6, 2);
            $airdate = $year . '-' . $month . '-' . $day;
            //echo "<br>DEBUG: Ausstrahlungsdatum: $airdate<br>";
        }
        
        // Spezialbehandlung für bestimmte Serien erkennen
        $isSpecial = $this->isSpecialSeries($seriesName);
        
        // Für Specials immer includeSpecials auf true setzen
        if ($isSpecial || $hasZeroValues) {
            $this->includeSpecials = true;
            //echo "<br>DEBUG: Spezialbehandlung aktiviert für Serie '$seriesName' (S00E00 Format oder Spezial-Serie)<br>";
        }
        
        // Serie in TMDB suchen
        $series = $this->enhancedSeriesSearch($seriesName, null, $forceRefresh);
        
        if (!$series) {
            // Versuche alternative Suche bei Titeln mit Doppelpunkt
            if (strpos($seriesName, ':') !== false) {
                $altSeriesName = trim(explode(':', $seriesName)[0]);
                //echo "<br>DEBUG: Versuche alternative Suche für '$altSeriesName'<br>";
                $series = $this->enhancedSeriesSearch($altSeriesName, null, $forceRefresh);
            }
            
            if (!$series) {
                $this->utilHandler->log("Keine Serie für '$seriesName' gefunden");
                //echo "<br>DEBUG: Keine Serie für '$seriesName' gefunden<br>";
                $this->includeSpecials = $originalIncludeSpecials; // Ursprungszustand wiederherstellen
                return false;
            }
        }
        
        //echo "<br>DEBUG: Serie gefunden: " . $series['name'] . " (ID: " . $series['id'] . ")<br>";
        
        // Nach Episode suchen
        $episode = $this->findEpisodeByNameOrDate($series['id'], $episodeName, $airdate);
        
        if (!$episode) {
            // Versuche mehrteilige Episodentitel zu erkennen und zu bereinigen
            $baseEpisodeName = $episodeName;
            $isMehrteilig = false;
            
            // Format "Der Weihnachtsgeist - Teil 1"
            if (preg_match('/(.*?)(?:\s*[-–]\s*Teil\s+\d+)$/i', $episodeName, $matches)) {
                $baseEpisodeName = trim($matches[1]);
                $isMehrteilig = true;
            } 
            // Format "Ein falsches Leben (1)"
            else if (preg_match('/(.*?)(?:\s*\(\d+\))$/i', $episodeName, $matches)) {
                $baseEpisodeName = trim($matches[1]);
                $isMehrteilig = true;
            }
            
            if ($isMehrteilig) {
                //echo "<br>DEBUG: Mehrteilige Episode erkannt, versuche Basis-Titel: '$baseEpisodeName'<br>";
                $episode = $this->findEpisodeByNameOrDate($series['id'], $baseEpisodeName, $airdate);
            }
            
            if (!$episode) {
                $this->utilHandler->log("Keine passende Episode für '$seriesName'" . 
                                    (!empty($episodeName) ? " ($episodeName)" : "") . 
                                    (!empty($airdate) ? " am $airdate" : "") . " gefunden");
                
                //echo "<br>DEBUG: ✗ Keine passende Episode gefunden<br>";
                $this->includeSpecials = $originalIncludeSpecials; // Ursprungszustand wiederherstellen
                return false;
            }
        }
        
        // Broadcast-Daten aktualisieren
        $broadcast['season'] = (int)$episode['season_number'];
        $broadcast['episode'] = (int)$episode['episode_number'];
        
        // Falls Episodentitel fehlt, ergänzen
        if (empty($broadcast['episodeName']) && !empty($episode['name'])) {
            $broadcast['episodeName'] = $episode['name'];
        }
        
        $this->utilHandler->log("Episodendaten für '$seriesName' gefunden: S" . 
                              $episode['season_number'] . "E" . 
                              $episode['episode_number'] . " - " . 
                              $episode['name']);
        
        //echo "<br>DEBUG: ✓ Episode gefunden: S" . 
            $episode['season_number'] . "E" . $episode['episode_number'] . 
            " - " . $episode['name'] . "<br>";
        
        // Ursprungszustand wiederherstellen
        $this->includeSpecials = $originalIncludeSpecials;
        
        return true;
    }
    
    /**
     * Lädt komplette Episodenlisten für mehrere Serien im Hintergrund
     * @param array $seriesNames Array mit Seriennamen
     * @return array Statusmeldungen zur Verarbeitung
     */
    public function fetchSeriesBatch($seriesNames) {
        $results = [];
        
        foreach ($seriesNames as $seriesName) {
            $results[$seriesName] = ['status' => 'unknown', 'message' => ''];
            
            // Serie suchen
            $series = $this->enhancedSeriesSearch($seriesName);
            
            if (!$series) {
                $results[$seriesName] = [
                    'status' => 'error',
                    'message' => "Serie nicht gefunden"
                ];
                continue;
            }
            
            // Vollständige Serieninformationen abrufen
            try {
                $seriesData = $this->getCompleteSeriesInfo($series['id'], false, true);
                
                if ($seriesData) {
                    $episodeCount = 0;
                    $seasonCount = 0;
                    
                    if (isset($seriesData['seasons']) && is_array($seriesData['seasons'])) {
                        $seasonCount = count($seriesData['seasons']);
                        
                        foreach ($seriesData['seasons'] as $season) {
                            if (isset($season['episodes']) && is_array($season['episodes'])) {
                                $episodeCount += count($season['episodes']);
                            }
                        }
                    }
                    
                    $results[$seriesName] = [
                        'status' => 'success',
                        'message' => "Serie geladen, $seasonCount Staffeln, $episodeCount Episoden",
                        'id' => $series['id'],
                        'seasons' => $seasonCount,
                        'episodes' => $episodeCount
                    ];
                } else {
                    $results[$seriesName] = [
                        'status' => 'error',
                        'message' => "Fehler beim Laden der Serieninformationen"
                    ];
                }
            } catch (Exception $e) {
                $results[$seriesName] = [
                    'status' => 'error',
                    'message' => "Exception: " . $e->getMessage()
                ];
            }
        }
        
        return $results;
    }

    /**
     * Normalisiert einen Titel für besseren Vergleich
     * @param string $title Titel
     * @return string Normalisierter Titel
     */
    private function normalizeTitle($title) {
        // In Kleinbuchstaben umwandeln
        $result = mb_strtolower($title, 'UTF-8');
        
        // Umlaute ersetzen
        $search = ['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'á', 'à', 'â', 'ó', 'ò', 'ô', 'ñ', 'ç'];
        $replace = ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'e', 'a', 'a', 'a', 'o', 'o', 'o', 'n', 'c'];
        $result = str_replace($search, $replace, $result);
        
        // Satzzeichen entfernen
        $result = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $result);
        
        // Mehrfache Leerzeichen entfernen
        $result = preg_replace('/\s+/', ' ', $result);
        
        // Trimmen
        $result = trim($result);
        
        return $result;
    }
    
    /**
     * Prüft, ob es sich um eine Serie mit spezieller Behandlung handelt
     * @param string $seriesName Name der Serie
     * @return bool True, wenn es eine Spezialserie ist
     */
    private function isSpecialSeries($seriesName) {
        $specialSeries = [
            'tatort', 'polizeiruf 110', 'wilsberg', 'ein starkes team', 
            'krimi', 'kommissar', 'inspector', 'detective', 'soko', 
            'criminal intent', 'death in paradise', 'navy cis', 
            'edgar wallace', 'bella block', 'der alte', 'der bulle'
        ];
        
        $normalizedName = strtolower($seriesName);
        
        foreach ($specialSeries as $special) {
            if (strpos($normalizedName, $special) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Löscht den Cache für eine bestimmte Serie
     * @param int $seriesId TMDB-ID der Serie
     * @return bool Erfolg des Löschens
     */
    public function clearSeriesCache($seriesId) {
        $seriesDir = $this->getSeriesDirectoryPath($seriesId);
        
        if (!is_dir($seriesDir)) {
            return true; // Nichts zu löschen
        }
        
        // Rekursives Löschen des Verzeichnisses
        $this->recursiveDelete($seriesDir);
        
        return !is_dir($seriesDir);
    }
    
    /**
     * Löscht ein Verzeichnis rekursiv
     * @param string $dir Pfad zum Verzeichnis
     * @return bool Erfolg des Löschens
     */
    private function recursiveDelete($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
            return true;
        }
        return false;
    }
    
    /**
     * Gibt statistische Informationen über den Cache zurück
     * @return array Cache-Statistiken
     */
    public function getCacheStats() {
        $stats = [
            'series_count' => 0,
            'seasons_count' => 0,
            'episodes_count' => 0,
            'cache_size_bytes' => 0,
            'oldest_cache_entry' => null,
            'newest_cache_entry' => null,
            'legacy_cache_entries' => count($this->cache),
            'legacy_cache_size_bytes' => filesize($this->cacheFile)
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
            // Dateigröße der Serien-Info-Datei
            $seriesInfoFile = $seriesDir . '/series_info.json';
            if (file_exists($seriesInfoFile)) {
                $stats['cache_size_bytes'] += filesize($seriesInfoFile);
                
                $modTime = filemtime($seriesInfoFile);
                if ($modTime < $oldestTime) {
                    $oldestTime = $modTime;
                }
                if ($modTime > $newestTime) {
                    $newestTime = $modTime;
                }
            }
            
            // Alle Staffel-Verzeichnisse zählen
            $seasonDirs = glob($seriesDir . '/season_*', GLOB_ONLYDIR);
            $stats['seasons_count'] += count($seasonDirs);
            
            foreach ($seasonDirs as $seasonDir) {
                // Dateigröße der Staffel-Info-Datei
                $seasonInfoFile = $seasonDir . '/season_info.json';
                if (file_exists($seasonInfoFile)) {
                    $stats['cache_size_bytes'] += filesize($seasonInfoFile);
                }
                
                // Alle Episoden-Dateien zählen
                $episodeFiles = glob($seasonDir . '/episode_*.json');
                $stats['episodes_count'] += count($episodeFiles);
                
                // Dateigröße der Episoden-Dateien
                foreach ($episodeFiles as $episodeFile) {
                    $stats['cache_size_bytes'] += filesize($episodeFile);
                }
            }
        }
        
        if ($oldestTime != PHP_INT_MAX) {
            $stats['oldest_cache_entry'] = date('Y-m-d H:i:s', $oldestTime);
        }
        
        if ($newestTime > 0) {
            $stats['newest_cache_entry'] = date('Y-m-d H:i:s', $newestTime);
        }
        
        // Formatierte Größe
        $stats['cache_size_formatted'] = $this->formatSize($stats['cache_size_bytes']);
        $stats['legacy_cache_size_formatted'] = $this->formatSize($stats['legacy_cache_size_bytes']);
        
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
}