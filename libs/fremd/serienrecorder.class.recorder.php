<?php
/**
 * SerienRecorderRecorder
 * 
 * Eine Klasse zur Verwaltung von Recordern für den SerienRecorder
 * 
 * @version 1.4.0
 * @changelog
 * - 1.4.0: Verbesserte Channel-Mappings und Speicherung
 *   - Intelligenter Algorithmus zur automatischen Kanalmapping-Erkennung
 *   - Berücksichtigung von Zahlwörtern und Sender-Kürzeln
 *   - Speicherung der Mappings in lesbarer Textdatei zur einfachen Kontrolle
 * - 1.3.0: Channel-Mapping und Timer-Programmierung
 *   - Implementierung eines konfigurierbaren Channel-Mapping-Systems
 *   - Methoden zum Erstellen von Timern auf dem Receiver
 *   - Verbesserte Kanal-Zuordnung mit strengen Kriterien
 * - 1.2.0: Integration von Timer-Funktionalität
 *   - Implementierung der getTimerList-Methode zum Abrufen programmierter Timer vom Receiver
 *   - Timer-Parsing und Verarbeitung für Enigma2-basierte Receiver
 *   - Verbessertes Matching zwischen Timernamen und Broadcast-Daten
 * - 1.1.0: Implementierung intelligenter Kanalsuche und -abgleich
 * - 1.0.0: Erste Version - Implementierung der Recorder-Funktionalität
 */
class SerienRecorderRecorder {
    private $ip;
    private $tunerCount;
    private $bouquet;
    private $channels = [];
    private $config;
    private $utilHandler;
    private $timerList = [];
    private $lastTimerUpdate = 0;
    private $channelMappings = []; // Channel-Mapping-Array
    
    /**
     * Konstruktor
     * @param array $config Konfigurationsarray für den Recorder
     * @param SerienRecorderUtil $utilHandler Utility-Handler
     */
    public function __construct($config, $utilHandler) {
        $defaultConfig = [
            'ip' => '',
            'tunerCount' => 1,
            'bouquet' => '',
            'channelsFile' => '',
            'channelMappingFile' => '', // Pfad zur Channel-Mapping-Datei
            'refresh' => true,
            'refresh_interval' => 24, // in Stunden
            'timer_refresh_interval' => 300, // in Sekunden (5 Minuten)
            'cache_dir' => sys_get_temp_dir() // Standard-Cache-Verzeichnis
        ];
        
        $this->config = array_merge($defaultConfig, $config);
        $this->utilHandler = $utilHandler;
        
        $this->ip = $this->config['ip'];
        $this->tunerCount = $this->config['tunerCount'];
        $this->bouquet = $this->config['bouquet'];
        
        // Lade Kanalinfos, wenn eine Datei konfiguriert ist
        if (!empty($this->config['channelsFile'])) {
            $this->loadChannels();
        }
        
        // Lade Channel-Mappings, wenn eine Datei konfiguriert ist
        if (!empty($this->config['channelMappingFile'])) {
            $this->loadChannelMappings($this->config['channelMappingFile']);
        } else {
            // Standard-Pfad verwenden
            $this->loadChannelMappings();
        }
    }
    
    /**
     * Gibt die IP-Adresse des Recorders zurück
     * @return string IP-Adresse
     */
    public function getIp() {
        return $this->ip;
    }
    
    /**
     * Gibt die Anzahl der Tuner zurück
     * @return int Anzahl der Tuner
     */
    public function getTunerCount() {
        return $this->tunerCount;
    }
    
    /**
     * Gibt das Bouquet zurück
     * @return string Bouquet
     */
    public function getBouquet() {
        return $this->bouquet;
    }
    
    /**
     * Gibt den Zeitpunkt der letzten Timer-Aktualisierung zurück
     * @return int Zeitpunkt der letzten Timer-Aktualisierung
     */
    public function getLastTimerUpdate() {
        return $this->lastTimerUpdate;
    }
    
    /**
     * Gibt die Kanäle des Recorders zurück
     * @param bool $forceRefresh Erzwinge das Neuladen der Kanäle
     * @return array Kanäle
     */
    public function getChannels($forceRefresh = false) {
        if (empty($this->channels) || $forceRefresh) {
            $this->loadChannels($forceRefresh);
        }
        
        return $this->channels;
    }
    
    /**
     * Gibt die Konfiguration des Recorders zurück
     * @return array Konfiguration
     */
    public function getConfig() {
        return $this->config;
    }
    
    /**
     * Setzt oder aktualisiert Konfigurationseinstellungen
     * @param array $config Zu aktualisierende Konfigurationseinstellungen
     */
    public function setConfig($config) {
        $this->config = array_merge($this->config, $config);
        $this->ip = $this->config['ip'];
        $this->tunerCount = $this->config['tunerCount'];
        $this->bouquet = $this->config['bouquet'];
    }
    
    /**
     * Lädt die Kanäle des Recorders
     * @param bool $forceRefresh Erzwinge das Neuladen der Kanäle
     * @return array Kanäle
     */
    public function loadChannels($forceRefresh = false) {
        $channelsFile = $this->config['channelsFile'];
        $shouldRefresh = $this->config['refresh'];
        
        // Wenn eine Datei existiert und kein Refresh erzwungen wird
        if (!empty($channelsFile) && file_exists($channelsFile) && !$forceRefresh) {
            if (!$shouldRefresh || !$this->utilHandler->shouldRefreshFile($channelsFile, $this->config['refresh_interval'])) {
                $this->utilHandler->log("Verwende Cache: Kanäle-Datei ist noch aktuell (Intervall: {$this->config['refresh_interval']}h)");
                $channels = $this->loadChannelsFromFile($channelsFile);
                
                if ($channels !== null) {
                    $this->channels = $channels;
                    return $this->channels;
                }
            }
        }
        
        // Ab hier nur ausführen, wenn Cache nicht verwendet werden kann
        return $this->fetchChannelsFromReceiver();
    }
    
    /**
     * Lädt die Kanäle aus einer Datei
     * @param string $file Pfad zur Datei
     * @return array|null Kanäle oder null bei Fehler
     */
    private function loadChannelsFromFile($file) {
        $this->utilHandler->log("Lade Kanäle aus Datei: " . $file);
        
        if (!file_exists($file)) {
            $this->utilHandler->log("Datei nicht gefunden: " . $file);
            return null;
        }
        
        $json = file_get_contents($file);
        $data = json_decode($json, true);
        
        if (!isset($data['channels']) || !is_array($data['channels'])) {
            $this->utilHandler->log("Ungültiges Dateiformat");
            return null;
        }
        
        $this->utilHandler->log("Kanäle erfolgreich geladen, Zeitstempel: " . date('Y-m-d H:i:s', $data['timestamp']));
        
        return $data['channels'];
    }
    
    /**
     * Speichert die Kanäle in einer Datei
     * @param array $channels Kanäle
     * @param string $file Pfad zur Datei
     * @return bool Erfolg des Speicherns
     */
    private function saveChannelsToFile($channels, $file) {
        $this->utilHandler->log("Speichere Kanäle in Datei: " . $file);
        
        $data = [
            'timestamp' => time(),
            'channels' => $channels
        ];
        
        $json = json_encode($data, JSON_PRETTY_PRINT);
        $result = file_put_contents($file, $json);
        
        return $result !== false;
    }
    
    /**
     * Ruft die Kanäle vom Receiver ab
     * @return array Kanäle
     */
    private function fetchChannelsFromReceiver() {
        if (empty($this->ip) || empty($this->bouquet)) {
            $this->utilHandler->log("Keine IP oder Bouquet konfiguriert");
            return [];
        }
        
        $url = "http://" . $this->ip . "/web/getservices?sRef=" . urlencode($this->bouquet);
        $this->utilHandler->log("Rufe Kanäle vom Receiver ab: " . $url);
        
        // cURL für den Abruf vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Abruf ausführen
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler beim Abruf der Kanäle: " . curl_error($ch));
            curl_close($ch);
            return [];
        }
        
        curl_close($ch);
        
        // Prüfen, ob die Anfrage erfolgreich war
        if ($httpCode !== 200) {
            $this->utilHandler->log("HTTP-Fehler beim Abruf der Kanäle: " . $httpCode);
            return [];
        }
        
        // Kanäle aus dem XML extrahieren
        $channels = $this->extractChannelsFromXml($result);
        
        // Kanäle in Datei speichern, wenn konfiguriert
        if (!empty($this->config['channelsFile'])) {
            $dirName = dirname($this->config['channelsFile']);
            if (!is_dir($dirName)) {
                mkdir($dirName, 0755, true);
            }
            
            $this->saveChannelsToFile($channels, $this->config['channelsFile']);
        }
        
        $this->channels = $channels;
        return $channels;
    }
    
    /**
     * Extrahiert die Kanäle aus dem XML
     * @param string $xml XML-String
     * @return array Kanäle
     */
    private function extractChannelsFromXml($xml) {
        $channels = [];
        
        // Fehlerbehandlung für XML-Parser
        libxml_use_internal_errors(true);
        
        // XML parsen
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            $this->utilHandler->log("Fehler beim Parsen des XML");
            libxml_clear_errors();
            return $channels;
        }
        
        $xpath = new DOMXPath($doc);
        
        // Service-Elemente suchen
        $serviceNodes = $xpath->query('//e2service');
        
        if ($serviceNodes->length === 0) {
            $this->utilHandler->log("Keine Kanäle gefunden");
            return $channels;
        }
        
        foreach ($serviceNodes as $serviceNode) {
            $serviceNameNodes = $xpath->query('./e2servicename', $serviceNode);
            $serviceReferenceNodes = $xpath->query('./e2servicereference', $serviceNode);
            
            if ($serviceNameNodes->length > 0 && $serviceReferenceNodes->length > 0) {
                $serviceName = trim($serviceNameNodes->item(0)->textContent);
                $serviceReference = trim($serviceReferenceNodes->item(0)->textContent);
                
                // Nur gültige Einträge hinzufügen
                if (!empty($serviceName) && !empty($serviceReference)) {
                    $channels[] = [
                        'name' => $serviceName,
                        'reference' => $serviceReference
                    ];
                }
            }
        }
        
        $this->utilHandler->log("Anzahl gefundener Kanäle: " . count($channels));
        
        return $channels;
    }
    
    /**
     * Laden der Channel-Mappings aus einer Konfigurationsdatei
     * @param string $mappingFile Pfad zur Mapping-Datei (JSON)
     * @return bool Erfolg des Ladens
     */
    public function loadChannelMappings($mappingFile = null) {
        // Standard-Mapping-Datei verwenden, falls nicht angegeben
        if ($mappingFile === null) {
            $mappingFile = $this->config['cache_dir'] . '/channel_mappings_' . $this->ip . '.json';
        }
        
        if (file_exists($mappingFile)) {
            $content = file_get_contents($mappingFile);
            $mappings = json_decode($content, true);
            
            if (is_array($mappings)) {
                $this->channelMappings = $mappings;
                $this->utilHandler->log("Channel-Mappings geladen: " . count($this->channelMappings) . " Einträge");
                return true;
            }
        }
        
        $this->utilHandler->log("Keine Channel-Mappings gefunden oder ungültiges Format");
        return false;
    }
    
    /**
     * Speichern der Channel-Mappings in eine Konfigurationsdatei
     * @param string $mappingFile Pfad zur Mapping-Datei (JSON)
     * @return bool Erfolg des Speicherns
     */
    public function saveChannelMappings($mappingFile = null) {
        // Standard-Mapping-Datei verwenden, falls nicht angegeben
        if ($mappingFile === null) {
            $mappingFile = $this->config['cache_dir'] . '/channel_mappings_' . $this->ip . '.json';
        }
        
        // Sicherstellen, dass das Verzeichnis existiert
        $dirName = dirname($mappingFile);
        if (!is_dir($dirName)) {
            mkdir($dirName, 0755, true);
        }
        
        // Sicherstellen dass keine doppelte Kodierung stattfindet
        $cleanedMappings = [];
        foreach ($this->channelMappings as $external => $internal) {
            // Dekodiere mögliche Unicode-Escape-Sequenzen
            $cleanExternal = json_decode('"' . str_replace('"', '\\"', $external) . '"');
            $cleanInternal = json_decode('"' . str_replace('"', '\\"', $internal) . '"');
            
            $cleanedMappings[$cleanExternal ?: $external] = $cleanInternal ?: $internal;
        }
        
        // Mit korrekten Unicode-Optionen speichern
        $content = json_encode($cleanedMappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result = file_put_contents($mappingFile, $content, LOCK_EX);
        
        // Zusätzlich eine leicht lesbare Textdatei erstellen
        $textFile = str_replace('.json', '.txt', $mappingFile);
        $textContent = "# Channel-Mappings - Erstellt am " . date('Y-m-d H:i:s') . "\n";
        $textContent .= "# Format: XMLTV-Kanalname => Receiver-Kanalname\n\n";
        
        // Nach Kanalname sortieren für bessere Lesbarkeit
        ksort($cleanedMappings);
        
        foreach ($cleanedMappings as $external => $internal) {
            $textContent .= sprintf("%-30s => %s\n", $external, $internal);
        }
        
        file_put_contents($textFile, $textContent, LOCK_EX);
        
        $this->utilHandler->log("Channel-Mappings gespeichert: " . count($this->channelMappings) . " Einträge");
        $this->utilHandler->log("JSON-Datei: " . $mappingFile);
        $this->utilHandler->log("Text-Datei: " . $textFile);
        
        return $result !== false;
    }
    
    /**
     * Hinzufügen oder Aktualisieren eines Channel-Mappings
     * @param string $externalName Externer Kanalname (z.B. aus XMLTV)
     * @param string $receiverName Kanalname auf dem Receiver
     * @return bool Erfolg des Hinzufügens
     */
    public function addChannelMapping($externalName, $receiverName) {
        $this->channelMappings[$externalName] = $receiverName;
        $this->utilHandler->log("Channel-Mapping hinzugefügt: '$externalName' -> '$receiverName'");
        
        // Automatisch speichern
        return $this->saveChannelMappings();
    }
    
    /**
     * Entfernen eines Channel-Mappings
     * @param string $externalName Externer Kanalname
     * @return bool Erfolg des Entfernens
     */
    public function removeChannelMapping($externalName) {
        if (isset($this->channelMappings[$externalName])) {
            unset($this->channelMappings[$externalName]);
            $this->utilHandler->log("Channel-Mapping entfernt: '$externalName'");
            
            // Automatisch speichern
            return $this->saveChannelMappings();
        }
        
        return false;
    }
    
    /**
     * Setzt mehrere Channel-Mappings auf einmal
     * @param array $mappings Array mit [externalName => receiverName] Zuordnungen
     * @return bool Erfolg des Setzens
     */
    public function setChannelMappings($mappings) {
        if (!is_array($mappings)) {
            return false;
        }
        
        $count = 0;
        foreach ($mappings as $externalName => $receiverName) {
            if (empty($externalName) || empty($receiverName)) {
                continue;
            }
            
            $this->channelMappings[$externalName] = $receiverName;
            $count++;
        }
        
        $this->utilHandler->log("$count Channel-Mappings gesetzt");
        
        // Automatisch speichern
        return $this->saveChannelMappings();
    }
    
    /**
     * Gibt alle aktuellen Channel-Mappings zurück
     * @return array Aktuelle Mappings
     */
    public function getChannelMappings() {
        return $this->channelMappings;
    }
    
    /**
     * Verbesserte Normalisierung eines Kanalnamens für den Vergleich
     * @param string $channelName Kanalname
     * @return string Normalisierter Name
     */
    private function normalizeChannelName($channelName) {
        // In Kleinbuchstaben umwandeln
        $name = mb_strtolower($channelName, 'UTF-8');
        
        // Länderpräfixe entfernen
        $name = preg_replace('/^(de|ch|at):\s+/i', '', $name);
        
        // HD/SD/UHD und ähnliche Qualitätsbezeichnungen entfernen
        $name = preg_replace('/\s+(hd|sd|uhd|4k|plus|neo|austria|tvi|digital)\b/i', '', $name);
        
        // Spezifische Sender-Zusätze entfernen
        $name = preg_replace('/\s+(fernsehen|television|tv|channel)\b/i', '', $name);
        
        // Entferne spezielle Zeichen, behalte aber Ziffern
        $name = preg_replace('/[^\p{L}\p{N}]/u', '', $name);
        
        return $name;
    }
    
    /**
     * Wandelt Zahlwörter in Ziffern um
     * @param string $text Text mit möglichen Zahlwörtern
     * @return string Text mit umgewandelten Ziffern
     */
    private function convertNumberWordsToDigits($text) {
        $numberWords = [
            'null' => '0',
            'ein' => '1', 'eins' => '1', 'eine' => '1', 'einen' => '1', 'einer' => '1', 'eines' => '1',
            'erste' => '1', 'erster' => '1', 'erstes' => '1',
            'zwei' => '2', 'zweite' => '2', 'zweiter' => '2', 'zweites' => '2',
            'drei' => '3', 'dritte' => '3', 'dritter' => '3', 'drittes' => '3',
            'vier' => '4', 'vierte' => '4', 'vierter' => '4', 'viertes' => '4',
            'fünf' => '5', 'fuenf' => '5', 'fünfte' => '5', 'fuenfte' => '5',
            'sechs' => '6', 'sechste' => '6',
            'sieben' => '7', 'siebte' => '7',
            'acht' => '8', 'achte' => '8',
            'neun' => '9', 'neunte' => '9',
            'zehn' => '10', 'zehnte' => '10',
            'elf' => '11', 'elfte' => '11',
            'zwölf' => '12', 'zwoelf' => '12', 'zwölfte' => '12',
            'dreizehn' => '13',
            'vierzehn' => '14',
            'one' => '1', 'first' => '1',
            'two' => '2', 'second' => '2',
            'three' => '3', 'third' => '3',
            'four' => '4', 'fourth' => '4',
            'five' => '5', 'fifth' => '5',
            'six' => '6', 'sixth' => '6',
            'seven' => '7', 'seventh' => '7',
            'eight' => '8', 'eighth' => '8',
            'nine' => '9', 'ninth' => '9'
        ];
        
        $pattern = '/\b(' . implode('|', array_keys($numberWords)) . ')\b/i';
        
        return preg_replace_callback($pattern, function($matches) use ($numberWords) {
            $word = mb_strtolower($matches[1], 'UTF-8');
            return isset($numberWords[$word]) ? $numberWords[$word] : $matches[0];
        }, $text);
    }
    
    /**
     * Berechnet einen Ähnlichkeitsscore zwischen zwei Kanalnamen
     * @param string $name1 Erster Kanalname
     * @param string $name2 Zweiter Kanalname
     * @return array Ähnlichkeitsscore und Grund
     */
    private function getChannelSimilarity($name1, $name2) {
        // Normalisierte Versionen erstellen
        $norm1 = $this->normalizeChannelName($name1);
        $norm2 = $this->normalizeChannelName($name2);
        
        // Zahlwörter in Ziffern umwandeln
        $num1 = $this->convertNumberWordsToDigits($norm1);
        $num2 = $this->convertNumberWordsToDigits($norm2);
        
        // Wenn nach Umwandlung exakte Übereinstimmung
        if ($num1 === $num2) {
            return ['score' => 100, 'reason' => 'Exakte Übereinstimmung nach Normalisierung'];
        }
        
        $score = 0;
        $reasons = [];
        
        // 1. Ziffernmuster extrahieren
        preg_match_all('/\d+/', $num1, $digits1);
        preg_match_all('/\d+/', $num2, $digits2);
        
        // Wenn beide Kanalnamen Ziffern enthalten
        if (!empty($digits1[0]) && !empty($digits2[0])) {
            // Wenn dieselbe Anzahl von Zahlenblöcken
            if (count($digits1[0]) === count($digits2[0])) {
                $matchingDigits = 0;
                
                // Überprüfe jeden Zahlenblock
                for ($i = 0; $i < count($digits1[0]); $i++) {
                    if (isset($digits2[0][$i]) && $digits1[0][$i] === $digits2[0][$i]) {
                        $matchingDigits++;
                    }
                }
                
                // Wenn alle Zahlenblöcke übereinstimmen
                if ($matchingDigits === count($digits1[0])) {
                    $score += 50;
                    $reasons[] = "Alle Ziffern stimmen überein: " . implode(', ', $digits1[0]);
                }
                // Wenn mindestens ein Zahlenblock übereinstimmt
                else if ($matchingDigits > 0) {
                    $score += 30;
                    $reasons[] = "Teilweise Ziffernübereinstimmung";
                }
            }
            // Wenn unterschiedliche Anzahl von Zahlenblöcken, aber Überschneidungen
            else {
                $commonDigits = array_intersect($digits1[0], $digits2[0]);
                if (!empty($commonDigits)) {
                    $score += 25;
                    $reasons[] = "Gemeinsame Ziffern: " . implode(', ', $commonDigits);
                }
            }
        }
        
        // 2. Sender-Kürzel und spezifische Wörter
        $senderKuerzel = [
            'ard', 'zdf', 'rtl', 'sat', 'pro', 'kabel', 'orf', 'srf', 'wdr', 
            'ndr', 'mdr', 'hr', 'rbb', 'br', 'swr', 'arte', '3sat', 'phoenix'
        ];
        
        foreach ($senderKuerzel as $kuerzel) {
            if (strpos($num1, $kuerzel) !== false && strpos($num2, $kuerzel) !== false) {
                $score += 25;
                $reasons[] = "Gemeinsames Sender-Kürzel: $kuerzel";
                break;
            }
        }
        
        // 3. Levenshtein-Ähnlichkeit mit Normalisierung der Leerzeichen
        $compact1 = str_replace(' ', '', $num1);
        $compact2 = str_replace(' ', '', $num2);
        
        $levDistance = levenshtein($compact1, $compact2);
        $levScore = max(0, 100 - ($levDistance * 10));
        
        // Wenn der Levenshtein-Score hoch ist
        if ($levScore > 60) {
            $score += min(25, $levScore / 4); // Max 25 Punkte für Levenshtein
            $reasons[] = "Hohe Textähnlichkeit: $levScore%";
        }
        
        // Endergebnis: Summe aller Faktoren, maximal 100
        $finalScore = min(100, $score);
        
        return ['score' => $finalScore, 'reason' => implode('; ', $reasons)];
    }
    
    /**
     * Generiert automatisch Channel-Mappings mit verbesserten Erkennungsalgorithmen
     * @param array $externalChannels Liste externer Kanalnamen
     * @param int $minScore Mindest-Score für Übereinstimmungen (0-100)
     * @return array Ergebnisse der Mapping-Generierung
     */
    public function generateChannelMappings($externalChannels, $minScore = 70) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'mappings' => []
        ];
        
        $channels = $this->getChannels();
        
        foreach ($externalChannels as $externalName) {
            // Bereits vorhandene Mappings überspringen
            if (isset($this->channelMappings[$externalName])) {
                $results['mappings'][$externalName] = $this->channelMappings[$externalName] . " (bereits vorhanden)";
                continue;
            }
            
            $bestMatch = null;
            $bestScore = 0;
            $bestReason = '';
            
            foreach ($channels as $channel) {
                $similarity = $this->getChannelSimilarity($externalName, $channel['name']);
                
                if ($similarity['score'] > $bestScore) {
                    $bestScore = $similarity['score'];
                    $bestMatch = $channel;
                    $bestReason = $similarity['reason'];
                }
            }
            
            if ($bestMatch && $bestScore >= $minScore) {
                $this->channelMappings[$externalName] = $bestMatch['name'];
                $results['success']++;
                $results['mappings'][$externalName] = $bestMatch['name'] . " (Score: $bestScore, " . $bestReason . ")";
            } else {
                $results['failed']++;
                $results['mappings'][$externalName] = null;
            }
        }
        
        // Speichern der aktualisierten Mappings
        $this->saveChannelMappings();
        
        return $results;
    }
    
    /**
     * Sucht einen Kanal anhand des Namens mit Channel-Mapping
     * @param string $channelName Name des Kanals (extern)
     * @return array|null Kanal-Informationen oder null, wenn nicht gefunden
     */
    public function findChannelByName($channelName) {
        $channels = $this->getChannels();
        
        //Debug Ausgabe für die Fehlersuche
        $this->utilHandler->log("Suche Kanal für: '$channelName', verfügbare Mappings: " . count($this->channelMappings), 2);
        
        // 1. Prüfen, ob ein direktes Mapping existiert
        if (isset($this->channelMappings[$channelName])) {
            $mappedName = $this->channelMappings[$channelName];
            //$this->utilHandler->log("Channel-Mapping verwendet: '$channelName' -> '$mappedName'");
            
            // Nach gemapptem Kanalnamen suchen
            foreach ($channels as $channel) {
                if (strcasecmp($channel['name'], $mappedName) === 0) {
                    return $channel;
                }
            }
        }
        
        // 2. Fallback: Direkter Vergleich
        foreach ($channels as $channel) {
            if (strcasecmp($channel['name'], $channelName) === 0) {
                return $channel;
            }
        }
        
        // 3. Fallback: Minimale Normalisierung und Ähnlichkeitsvergleich
        $norm1 = $this->normalizeChannelName($channelName);
        $num1 = $this->convertNumberWordsToDigits($norm1);
        
        $bestMatch = null;
        $bestScore = 0;
        
        foreach ($channels as $channel) {
            $norm2 = $this->normalizeChannelName($channel['name']);
            $num2 = $this->convertNumberWordsToDigits($norm2);
            
            // Exakte Übereinstimmung nach Normalisierung
            if ($num1 === $num2) {
                // Automatisch Mapping erstellen für zukünftige Verwendung
                $this->addChannelMapping($channelName, $channel['name']);
                return $channel;
            }
            
            // Ähnlichkeitsvergleich
            $similarity = $this->getChannelSimilarity($channelName, $channel['name']);
            if ($similarity['score'] >= 90 && $similarity['score'] > $bestScore) {
                $bestScore = $similarity['score'];
                $bestMatch = $channel;
            }
        }
        
        if ($bestMatch) {
            // Automatisch Mapping erstellen, wenn hohe Ähnlichkeit
            $this->addChannelMapping($channelName, $bestMatch['name']);
            return $bestMatch;
        }
        
        $this->utilHandler->log("Kein passender Kanal für '$channelName' gefunden");
        return null;
    }
    
    /**
     * Prüft, ob ein Kanal auf diesem Recorder verfügbar ist
     * @param string $channelName Name des Kanals
     * @return bool true, wenn der Kanal verfügbar ist, sonst false
     */
    public function hasChannel($channelName) {
        return $this->findChannelByName($channelName) !== null;
    }
    
    /**
     * Holt die Timer-Liste vom Receiver
     * @param bool $forceRefresh Erzwinge das Neuladen der Timer
     * @return array Array mit Timer-Daten
     */
    public function getTimerList($forceRefresh = false) {
        // Wenn Timer-Cache noch gültig ist und kein Refresh erzwungen wird
        if (!$forceRefresh && !empty($this->timerList) && 
            (time() - $this->lastTimerUpdate) < $this->config['timer_refresh_interval']) {
            //$this->utilHandler->log("Verwende gecachte Timer-Liste (Alter: " . 
                                   //(time() - $this->lastTimerUpdate) . " Sekunden)");
            return $this->timerList;
        }
        
        // Timer-Liste vom Receiver abrufen
        $url = "http://" . $this->ip . "/web/timerlist";
        $this->utilHandler->log("Rufe Timer-Liste vom Receiver ab: " . $url);
        
        // cURL für den Abruf vorbereiten
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        // Abruf ausführen
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler beim Abruf der Timer-Liste: " . curl_error($ch));
            curl_close($ch);
            return [];
        }
        
        curl_close($ch);
        
        // Prüfen, ob die Anfrage erfolgreich war
        if ($httpCode !== 200) {
            $this->utilHandler->log("HTTP-Fehler beim Abruf der Timer-Liste: " . $httpCode);
            return [];
        }
        
        // Timer aus dem XML extrahieren
        $timers = $this->extractTimersFromXml($result);
        
        // Timer-Cache aktualisieren
        $this->timerList = $timers;
        $this->lastTimerUpdate = time();
        
        $this->utilHandler->log("Timer-Liste aktualisiert: " . count($timers) . " Timer gefunden");
        
        return $timers;
    }
    
    /**
     * Extrahiert Timer aus dem XML
     * @param string $xml XML-String
     * @return array Timer
     */
    private function extractTimersFromXml($xml) {
        $timers = [];
        
        // Fehlerbehandlung für XML-Parser
        libxml_use_internal_errors(true);
        
        // XML parsen
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            $this->utilHandler->log("Fehler beim Parsen des Timer-XML");
            libxml_clear_errors();
            return $timers;
        }
        
        $xpath = new DOMXPath($doc);
        
        // Timer-Elemente suchen
        $timerNodes = $xpath->query('//e2timer');
        
        if ($timerNodes->length === 0) {
            $this->utilHandler->log("Keine Timer gefunden");
            return $timers;
        }
        
        foreach ($timerNodes as $timerNode) {
            $timer = [];
            
            // Alle relevanten Timer-Informationen extrahieren
            $fields = [
                'e2servicereference', 'e2servicename', 'e2name', 'e2description',
                'e2timebegin', 'e2timeend', 'e2duration', 'e2location', 'e2filename'
            ];
            
            foreach ($fields as $field) {
                $nodes = $xpath->query('./' . $field, $timerNode);
                if ($nodes->length > 0) {
                    $timer[$field] = trim($nodes->item(0)->textContent);
                } else {
                    $timer[$field] = '';
                }
            }
            
            // Parse Staffel- und Episodennummern aus dem Timernamen
            if (preg_match('/S(\d+)E(\d+)/', $timer['e2name'], $matches)) {
                $timer['season'] = (int)$matches[1];
                $timer['episode'] = (int)$matches[2];
            } else {
                $timer['season'] = 0;
                $timer['episode'] = 0;
            }
            
            // Serie aus dem Timernamen extrahieren
            if (strpos($timer['e2name'], ' - S') !== false) {
                $timer['series'] = trim(substr($timer['e2name'], 0, strpos($timer['e2name'], ' - S')));
            } else {
                $timer['series'] = $timer['e2name'];
            }
            
            // Episodentitel aus dem Timernamen extrahieren
            if (preg_match('/S\d+E\d+\s*-\s*(.+)$/', $timer['e2name'], $matches)) {
                $timer['episodeTitle'] = trim($matches[1]);
            } else {
                $timer['episodeTitle'] = '';
            }
            
            $timers[] = $timer;
        }
        
        return $timers;
    }
    
    /**
     * Prüft, ob ein Timer für einen bestimmten Broadcast existiert
     * @param array $broadcast Broadcast-Daten
     * @return bool|array Timer-Daten oder false, wenn kein Timer gefunden wurde
     */
    public function hasTimerForBroadcast($broadcast) {
        // Timer-Liste abrufen
        $timers = $this->getTimerList();
        
        foreach ($timers as $timer) {
            // Prüfung auf Serie, Staffel und Episode (unabhängig vom Kanal)
            if (isset($timer['series']) && isset($timer['season']) && isset($timer['episode']) &&
                $this->utilHandler->seriesNamesMatch($timer['series'], $broadcast['title']) &&
                $timer['season'] == $broadcast['season'] &&
                $timer['episode'] == $broadcast['episode']) {
                return $timer;
            }
        }
        
        return false;
    }
    
    /**
     * Programmiert einen einzelnen Timer auf dem Receiver
     * @param array $timer Timer-Daten
     * @param array $config Konfiguration mit pre/postRecord-Werten
     * @param bool $simulate Simulationsmodus (nur Ausgabe ohne tatsächliche Programmierung)
     * @return array Ergebnis der Timer-Programmierung
     */
    public function createTimer($timer, $config, $simulate = false) {
        if (empty($timer['serviceRef']) || empty($timer['start_timestamp']) || empty($timer['stop_timestamp'])) {
            $this->utilHandler->log("Unzureichende Timer-Daten für Timer-Programmierung");
            return ['success' => false, 'message' => 'Unzureichende Timer-Daten'];
        }
        
        // Vor- und Nachlaufzeiten hinzufügen
        $startTime = $timer['start_timestamp'] - (isset($config['preRecord']) ? $config['preRecord'] : 0);
        $endTime = $timer['stop_timestamp'] + (isset($config['postRecord']) ? $config['postRecord'] : 0);
        
        // Timer-URL erstellen
        $params = [
            'sRef' => $timer['serviceRef'],
            'begin' => $startTime,
            'end' => $endTime,
            'name' => $timer['timerName'],
            'description' => $timer['timerName'],
            'disabled' => 0,
            'justplay' => 0,
            'autoadjust' => 1,
            'afterevent' => 3,
            'repeated' => 0,
            'dirname' => $timer['recorderPath'],
            'vpsplugin_enabled' => 0,
            'vpsplugin_overwrite' => 1
        ];
        
        $queryString = http_build_query($params);
        $url = "http://" . $this->ip . "/web/timeradd?" . $queryString;
        
        $this->utilHandler->log("Timer-URL: " . $url);
        
        if ($simulate) {
            $this->utilHandler->log("SIMULATION: Timer-Programmierung für '" . $timer['timerName'] . "'");
            return ['success' => true, 'simulated' => true, 'timer' => $timer];
        }
        
        // Timer programmieren
        try {
            $result = simplexml_load_file($url);
            
            if ($result && isset($result->e2state) && (string)$result->e2state === 'True') {
                $this->utilHandler->log("Timer erfolgreich programmiert: " . $timer['timerName']);
                
                // Timer-Liste aktualisieren (Cache leeren)
                $this->timerList = [];
                $this->lastTimerUpdate = 0;
                
                return [
                    'success' => true, 
                    'message' => (string)$result->e2statetext,
                    'timer' => $timer
                ];
            } else {
                $errorMsg = isset($result->e2statetext) ? (string)$result->e2statetext : 'Unbekannter Fehler';
                $this->utilHandler->log("Fehler bei Timer-Programmierung: " . $errorMsg);
                
                return [
                    'success' => false,
                    'message' => $errorMsg,
                    'timer' => $timer
                ];
            }
        } catch (Exception $e) {
            $this->utilHandler->log("Exception bei Timer-Programmierung: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'timer' => $timer
            ];
        }
    }
    
    /**
     * Prüft, ob sich ein Timer mit einem anderen überschneidet
     * @param array $timer1 Erster Timer
     * @param array $timer2 Zweiter Timer
     * @param array $config Konfiguration mit pre/postRecord-Werten
     * @return bool Überschneidung vorhanden?
     */
    public function hasTimerOverlap($timer1, $timer2, $config) {
        // Vor- und Nachlaufzeiten hinzufügen
        $preRecord = isset($config['preRecord']) ? $config['preRecord'] : 0;
        $postRecord = isset($config['postRecord']) ? $config['postRecord'] : 0;
        
        $start1 = $timer1['start_timestamp'] - $preRecord;
        $end1 = $timer1['stop_timestamp'] + $postRecord;
        
        $start2 = $timer2['start_timestamp'] - $preRecord;
        $end2 = $timer2['stop_timestamp'] + $postRecord;
        
        // Überschneidung prüfen
        return ($start1 < $end2 && $start2 < $end1);
    }
}