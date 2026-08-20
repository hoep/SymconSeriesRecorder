<?php
/**
 * SerienRecorderWunschliste
 * 
 * Eine Klasse zum Abrufen und Verwalten von Serien-Favoriten auf wunschliste.de
 * 
 * @version 1.0.0
 * @changelog
 * - 1.0.0: Erste Version - Auslagern der Wunschliste-Funktionalität aus der Hauptklasse
 */
class SerienRecorderWunschliste {
    private $config;
    private $cookieJar;
    private $isLoggedIn = false;
    private $favorites = null;
    private $lastHtml = null;
    private $utilHandler;
    
    /**
     * Konstruktor
     * @param array $config Konfigurationsarray
     * @param SerienRecorderUtil $utilHandler Utility-Handler
     */
    public function __construct($config, $utilHandler) {
        $this->config = $config;
        $this->utilHandler = $utilHandler;
        
        // Cookie-Jar im Cache-Verzeichnis erstellen
        $this->cookieJar = tempnam($this->config['cache_dir'], 'cookie');
        
        // Stelle sicher, dass die Cookie-Datei existiert und beschreibbar ist
        if (!file_exists($this->cookieJar)) {
            touch($this->cookieJar);
        }
        chmod($this->cookieJar, 0600); // Nur der aktuelle Benutzer kann lesen/schreiben
    }
    
    /**
     * Destruktor - Löscht die Cookie-Datei
     */
    public function __destruct() {
        if (file_exists($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }
    
    /**
     * Setzt oder aktualisiert Konfigurationseinstellungen
     * @param array $config Zu aktualisierende Konfigurationseinstellungen
     */
    public function setConfig($config) {
        $this->config = $config;
    }
    
    /**
     * Bereitet eine cURL-Session vor
     * @param bool $followRedirects Weiterleitungen folgen
     * @return resource cURL-Handle
     */
    private function prepareCurl($followRedirects = true) {
        $ch = curl_init();
        
        // Allgemeine Einstellungen
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate, br, zstd');
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->config['timeout']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->config['timeout']);
        
        if ($followRedirects) {
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        }
        
        // Cookie-Handling
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        
        // Standard-Header
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en-US;q=0.7,en;q=0.3',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Connection: keep-alive',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: same-origin',
            'Sec-Fetch-User: ?1',
            'Pragma: no-cache',
            'Cache-Control: no-cache'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        return $ch;
    }
    
    /**
     * Holt mögliche CSRF-Token von der Login-Seite
     * @return array Array mit gefundenen Token
     */
    private function getCSRFToken() {
        $this->utilHandler->log("Hole Login-Seite für CSRF-Token...");
        
        $ch = $this->prepareCurl();
        curl_setopt($ch, CURLOPT_URL, $this->config['base_url'] . '/login');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler beim Holen der Login-Seite: " . curl_error($ch));
            curl_close($ch);
            return [];
        }
        
        curl_close($ch);
        
        // Prüfen, ob die Anfrage erfolgreich war
        if ($httpCode !== 200) {
            $this->utilHandler->log("HTTP-Fehler beim Holen der Login-Seite: " . $httpCode);
            return [];
        }
        
        $tokens = [];
        
        // CSRF-Token suchen
        if (preg_match('/csrfToken\s*=\s*"([^"]+)"/', $result, $matches)) {
            $tokens['csrfToken'] = $matches[1];
            $this->utilHandler->log("CSRF-Token gefunden: " . $tokens['csrfToken']);
        }
        
        // Andere Input-Felder mit Typ "hidden" suchen
        $dom = new DOMDocument();
        @$dom->loadHTML($result);
        $xpath = new DOMXPath($dom);
        
        $hiddenInputs = $xpath->query('//form//input[@type="hidden"]');
        foreach ($hiddenInputs as $input) {
            $name = $input->getAttribute('name');
            $value = $input->getAttribute('value');
            if ($name && $value) {
                $tokens[$name] = $value;
                $this->utilHandler->log("Hidden Input gefunden: {$name} = {$value}");
            }
        }
        
        return $tokens;
    }
    
    /**
     * Einloggen auf wunschliste.de
     * @return bool Erfolg des Logins
     */
    public function login() {
        $this->utilHandler->log("Login-Versuch mit Benutzer: " . $this->config['username']);
        
        // Hole CSRF-Token und versteckte Felder
        $tokens = $this->getCSRFToken();
        
        // Warte einen Moment, um die Website nicht zu überlasten
        sleep(2);
        
        $ch = $this->prepareCurl();
        
        curl_setopt($ch, CURLOPT_URL, $this->config['base_url'] . '/login');
        curl_setopt($ch, CURLOPT_POST, true);
        
        // Formulardaten mit möglichen CSRF-Token
        $postFields = [
            'login' => 1,
            'modus' => 1,
            'modus_orig' => 5,
            'email' => $this->config['username'],
            'passwort' => $this->config['password'],
            'setpermanentcookie' => 'on'
        ];
        
        // Füge CSRF-Tokens hinzu, wenn vorhanden
        if (isset($tokens['csrfToken'])) {
            $postFields['csrfToken'] = $tokens['csrfToken'];
        }
        
        // Füge weitere versteckte Felder hinzu
        foreach ($tokens as $name => $value) {
            if ($name !== 'csrfToken') {
                $postFields[$name] = $value;
            }
        }
        
        $postFieldsString = http_build_query($postFields);
        $this->utilHandler->log("POST-Daten: " . $postFieldsString);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFieldsString);
        
        // Setze zusätzliche Header für den POST-Request
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:137.0) Gecko/20100101 Firefox/137.0',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en-US;q=0.7,en;q=0.3',
            'Accept-Encoding: gzip, deflate, br, zstd',
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $this->config['base_url'],
            'Referer: ' . $this->config['base_url'] . '/login',
            'Connection: keep-alive'
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        $this->utilHandler->log("HTTP-Code: " . $httpCode);
        $this->utilHandler->log("Effektive URL nach Login: " . $effectiveUrl);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        // Prüfe Cookies
        if (file_exists($this->cookieJar)) {
            $cookies = file_get_contents($this->cookieJar);
            $this->utilHandler->log("Cookies: " . $cookies);
        } else {
            $this->utilHandler->log("Cookie-Datei existiert nicht!");
        }
        
        // Verschiedene Methoden zur Überprüfung des Login-Erfolgs
        $method1 = (strpos($cookies, 'user_id') !== false && strpos($cookies, 'auth') !== false);
        $method2 = (strpos($effectiveUrl, '/login/serienwunschliste') !== false || 
                   strpos($effectiveUrl, '/main') !== false || 
                   strpos($effectiveUrl, '/tvplaner') !== false);
        $method3 = (strpos($result, 'Log-Out') !== false && strpos($result, 'Meine Serien') !== false);
        
        $this->utilHandler->log("Login-Überprüfung durch Cookies: " . ($method1 ? "JA" : "NEIN"));
        $this->utilHandler->log("Login-Überprüfung durch URL: " . ($method2 ? "JA" : "NEIN"));
        $this->utilHandler->log("Login-Überprüfung durch HTML-Inhalt: " . ($method3 ? "JA" : "NEIN"));
        
        // Markiere als eingeloggt, wenn mindestens eine Methode zustimmt
        $this->isLoggedIn = ($method1 || $method2 || $method3);
        
        $this->utilHandler->log("Login-Status: " . ($this->isLoggedIn ? "ERFOLGREICH" : "FEHLGESCHLAGEN"));
        
        // Wenn Login fehlgeschlagen, aber HTML-Inhalt OK ist, versuche direkt die Favoritenseite zu laden
        if (!$this->isLoggedIn && $method3) {
            $this->utilHandler->log("HTML-Inhalt sieht gut aus, direkter Zugriff auf Favoritenseite...");
            
            // Warte einen Moment, bevor wir die nächste Anfrage senden
            sleep(3);
            
            if ($this->checkFavoritesAccess()) {
                $this->isLoggedIn = true;
                $this->utilHandler->log("Favoriten-Zugriff erfolgreich, markiere als eingeloggt");
            }
        }
        
        return $this->isLoggedIn;
    }
    
    /**
     * Überprüft den Zugriff auf die Favoritenseite
     * @return bool Erfolg des Zugriffs
     */
    private function checkFavoritesAccess() {
        $ch = $this->prepareCurl();
        
        curl_setopt($ch, CURLOPT_URL, $this->config['base_url'] . '/login/serienwunschliste/ALLE');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        
        $this->utilHandler->log("Favoriten-Zugriff HTTP-Code: " . $httpCode);
        $this->utilHandler->log("Favoriten-Zugriff Effektive URL: " . $effectiveUrl);
        
        if (curl_errno($ch)) {
            $this->utilHandler->log("Curl-Fehler bei Favoriten-Zugriff: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        
        curl_close($ch);
        
        // Prüfe, ob HTML-Inhalt die erwarteten Elemente enthält
        $accessSuccessful = false;
        
        if ($httpCode === 200) {
            // Überprüfe, ob die Seite die erwarteten Elemente enthält
            $dom = new DOMDocument();
            @$dom->loadHTML($result);
            $xpath = new DOMXPath($dom);
            
            // Suche nach der Serienliste
            $seriesItems = $xpath->query('//ul[@class="tl serien"]/li[starts-with(@id, "opt_")]');
            
            if ($seriesItems->length > 0) {
                $this->utilHandler->log("Serien gefunden auf der Favoritenseite: " . $seriesItems->length);
                $accessSuccessful = true;
            } else {
                $this->utilHandler->log("Keine Serien auf der Favoritenseite gefunden");
                
                // Falls keine Serien gefunden, aber die richtige Seite geladen wurde
                $rightPage = (strpos($effectiveUrl, '/login/serienwunschliste') !== false &&
                             strpos($result, 'Meine Serien') !== false);
                
                if ($rightPage) {
                    $this->utilHandler->log("Die richtige Seite wurde geladen, aber keine Serien gefunden - möglicherweise eine leere Wunschliste");
                    $accessSuccessful = true;
                }
            }
        }
        
        $this->utilHandler->log("Favoriten-Zugriff: " . ($accessSuccessful ? "ERFOLGREICH" : "FEHLGESCHLAGEN"));
        
        // Speichere HTML für spätere Verwendung, falls Zugriff erfolgreich
        if ($accessSuccessful) {
            $this->lastHtml = $result;
        }
        
        return $accessSuccessful;
    }
    
    /**
    * Speichert Favoriten in einer JSON-Datei
    * @param array $favorites Favoriten-Array
    * @param string $file Pfad zur Zieldatei
    * @return bool Erfolg des Speicherns
    */
    private function saveFavoritesToFile($favorites, $file) {
        $this->utilHandler->log("Speichere Favoriten in Datei: " . $file);
        
        // UTF-8 Validierung für alle Favoriten-Einträge
        foreach ($favorites as &$favorite) {
            foreach ($favorite as $key => &$value) {
                if (is_string($value)) {
                    // Sicherstellen, dass der String valides UTF-8 ist
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'auto');
                        $this->utilHandler->log("UTF-8 Konvertierung für Favoriten-Wert: $key");
                    }
                }
            }
        }
        
        $data = [
            'timestamp' => time(),
            'favorites' => $favorites
        ];
        
        // JSON_UNESCAPED_UNICODE hinzufügen, um Unicode-Zeichen direkt zu speichern
        // JSON_PRETTY_PRINT für lesbare Formatierung behalten
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        // Falls json_encode fehlschlägt (bei ungültigen UTF-8-Strings)
        if ($json === false) {
            $this->utilHandler->log("Fehler beim JSON-Encoding: " . json_last_error_msg());
            
            // Fallback: Erzwinge gültiges UTF-8 und probiere erneut
            $data = $this->forceValidUtf8($data);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
            if ($json === false) {
                $this->utilHandler->log("JSON-Encoding fehlgeschlagen auch nach UTF-8 Bereinigung");
                return false;
            }
        }
        
        // Speichere mit UTF-8 BOM für bessere Kompatibilität mit Windows
        $result = file_put_contents($file, "\xEF\xBB\xBF" . $json);
        
        return $result !== false;
    }

    /**
    * Stellt sicher, dass alle Strings in einem Array gültiges UTF-8 sind
    * @param mixed $data Zu überprüfende Daten
    * @return mixed Bereinigte Daten
    */
    private function forceValidUtf8($data) {
        if (is_string($data)) {
            // Ungültige UTF-8-Sequenzen ersetzen
            return iconv('UTF-8', 'UTF-8//IGNORE', $data);
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->forceValidUtf8($value);
            }
        }
        return $data;
    }
    
    /**
    * Lädt Favoriten aus einer JSON-Datei
    * @param string $file Pfad zur Quelldatei
    * @return array|null Favoriten-Array oder null bei Fehler
    */
    private function loadFavoritesFromFile($file) {
        $this->utilHandler->log("Lade Favoriten aus Datei: " . $file);
        
        if (!file_exists($file)) {
            $this->utilHandler->log("Datei nicht gefunden: " . $file);
            return null;
        }
        
        // Datei mit UTF-8-Unterstützung lesen
        $json = file_get_contents($file);
        
        // UTF-8 BOM entfernen, falls vorhanden
        if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
            $json = substr($json, 3);
        }
        
        // Prüfen, ob der String valides UTF-8 ist
        if (!mb_check_encoding($json, 'UTF-8')) {
            $this->utilHandler->log("Ungültiges UTF-8 in Favoriten-Datei, versuche zu konvertieren");
            $json = mb_convert_encoding($json, 'UTF-8', 'auto');
        }
        
        // JSON dekodieren
        $data = json_decode($json, true);
        
        // Fehlerbehandlung
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->utilHandler->log("JSON Fehler: " . json_last_error_msg());
            
            // Versuche, spezielle Zeichen zu behandeln (z.B. bei Encodingproblemen)
            $json = preg_replace('/[\x00-\x1F\x7F]/u', '', $json);
            $data = json_decode($json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->utilHandler->log("Ungültiges Dateiformat auch nach Bereinigung");
                return null;
            }
        }
        
        if (!isset($data['favorites']) || !is_array($data['favorites'])) {
            $this->utilHandler->log("Ungültiges Dateiformat: 'favorites' Schlüssel fehlt oder ist kein Array");
            return null;
        }
        
        // Sicherstellen, dass alle Strings valides UTF-8 sind
        foreach ($data['favorites'] as &$favorite) {
            foreach ($favorite as $key => &$value) {
                if (is_string($value)) {
                    if (!mb_check_encoding($value, 'UTF-8')) {
                        $value = mb_convert_encoding($value, 'UTF-8', 'auto');
                    }
                }
            }
        }
        
        $this->utilHandler->log("Favoriten erfolgreich geladen, Zeitstempel: " . date('Y-m-d H:i:s', $data['timestamp']));
        
        return $data['favorites'];
    }
    
    /**
     * Holt die Liste aller Serien-Favoriten
     * @param bool $forceRefresh Erzwinge das Neuladen der Favoriten
     * @return array Liste der Serien
     */
    public function getFavorites($forceRefresh = false) {
        // Wenn wir die Favoriten bereits abgerufen haben und kein Refresh erzwungen wird, verwenden wir den Cache
        if ($this->favorites !== null && !$forceRefresh) {
            return $this->favorites;
        }
        
        $favoritesFile = $this->config['favorites']['file'];
        $shouldRefresh = $this->config['favorites']['refresh'];
        
        // Wenn eine Datei konfiguriert ist und nicht neu geladen werden soll
        if (!empty($favoritesFile) && !$forceRefresh && !$shouldRefresh) {
            $this->utilHandler->log("Verwende gespeicherte Favoriten ohne Aktualisierung");
            $favorites = $this->loadFavoritesFromFile($favoritesFile);
            
            if ($favorites !== null) {
                $this->favorites = $favorites;
                return $this->favorites;
            }
        }
        
        // Wenn eine Datei konfiguriert ist und das Aktualisierungsintervall berücksichtigt werden soll
        if (!empty($favoritesFile) && !$forceRefresh && $shouldRefresh) {
            $interval = $this->config['favorites']['refresh_interval'];
            
            if (!$this->utilHandler->shouldRefreshFile($favoritesFile, $interval)) {
                $this->utilHandler->log("Favoriten-Datei ist noch aktuell (Intervall: {$interval}h)");
                $favorites = $this->loadFavoritesFromFile($favoritesFile);
                
                if ($favorites !== null) {
                    $this->favorites = $favorites;
                    return $this->favorites;
                }
            } else {
                $this->utilHandler->log("Favoriten-Datei ist veraltet oder nicht vorhanden, lade neu");
            }
        }
        
        // Favoriten online abrufen
        if (!$this->isLoggedIn) {
            if (!$this->login()) {
                throw new Exception('Nicht eingeloggt. Login fehlgeschlagen.');
            }
        }
        
        // Wenn wir schon HTML vom checkFavoritesAccess haben, verwenden wir das
        if (isset($this->lastHtml)) {
            $this->utilHandler->log("Verwende vorhandenes HTML für Serien-Extraktion");
            $result = $this->lastHtml;
            unset($this->lastHtml); // Einmal verwendetes HTML löschen
        } else {
            $this->utilHandler->log("Hole Favoritenseite...");
            
            $ch = $this->prepareCurl();
            curl_setopt($ch, CURLOPT_URL, $this->config['base_url'] . '/login/serienwunschliste/ALLE');
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                $this->utilHandler->log("Curl-Fehler bei Favoriten: " . curl_error($ch));
                throw new Exception('Curl error: ' . curl_error($ch));
            }
            
            curl_close($ch);
            
            // Prüfen, ob die Anfrage erfolgreich war
            if ($httpCode !== 200) {
                $this->utilHandler->log("HTTP-Fehler bei Favoriten: " . $httpCode);
                throw new Exception('HTTP-Fehler: ' . $httpCode);
            }
        }
        
        // Debug-Ausgabe der ersten 500 Zeichen
        if ($this->config['debug']) {
            $this->utilHandler->log("HTML-Vorschau: " . substr($result, 0, 500) . "...");
        }
        
        // Serien aus dem HTML extrahieren
        $this->favorites = $this->extractSeriesFromHtml($result);
        
        // Speichere Favoriten in Datei, wenn konfiguriert
        if (!empty($favoritesFile)) {
            $dirName = dirname($favoritesFile);
            if (!is_dir($dirName)) {
                mkdir($dirName, 0755, true);
            }
            
            $this->saveFavoritesToFile($this->favorites, $favoritesFile);
        }
        
        return $this->favorites;
    }
    
    /**
    * Extrahiert Serien aus dem HTML-Code
    * @param string $html HTML-Code
    * @return array Liste der Serien
    */
    private function extractSeriesFromHtml($html) {
        $series = [];
        
        // Sicherstellen, dass der HTML-Code als UTF-8 vorliegt
        if (!mb_check_encoding($html, 'UTF-8')) {
            $this->utilHandler->log("HTML-Code nicht in UTF-8, konvertiere...");
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }
        
        // DOMDocument zum Parsen des HTML-Codes erstellen
        $dom = new DOMDocument('1.0', 'UTF-8');
        
        // Unterdrücke Warnungen beim Parsen
        libxml_use_internal_errors(true);
        
        // HTML-Entity-Handling verbessern
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        
        // HTML laden
        @$dom->loadHTML($html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // Alle Serien-Elemente finden
        $items = $xpath->query('//ul[@class="tl serien"]/li[starts-with(@id, "opt_")]');
        
        $this->utilHandler->log("Gefundene Serien-Elemente: " . $items->length);
        
        foreach ($items as $item) {
            // Serie-ID extrahieren
            $id = str_replace('opt_', '', $item->getAttribute('id'));
            
            // Serienname extrahieren
            $nameElement = $xpath->query('.//strong[@class="sendung"]', $item)->item(0);
            $name = $nameElement ? trim($nameElement->textContent) : '';
            
            // Stellen sicher, dass der Name valides UTF-8 ist
            if (!mb_check_encoding($name, 'UTF-8')) {
                $name = mb_convert_encoding($name, 'UTF-8', 'auto');
            }
            
            // HTML-Entities dekodieren, die möglicherweise in den Texten vorhanden sind
            $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // Überspringe leere Elemente oder Elemente ohne Namen
            if (empty($name) || $id === '0') {
                $this->utilHandler->log("Überspringe leeres Element mit ID: $id");
                continue;
            }
            
            // Serien-URL extrahieren
            $linkElement = $xpath->query('.//a[1]', $item)->item(0);
            $url = $linkElement ? $this->config['base_url'] . $linkElement->getAttribute('href') : '';
            
            // Bildpfad extrahieren
            $imgElement = $xpath->query('.//img', $item)->item(0);
            $imagePath = '';
            
            if ($imgElement) {
                // Versuche zuerst data-src (für Lazy Loading)
                $imagePath = $imgElement->getAttribute('data-src');
                
                // Wenn data-src leer ist, versuche src
                if (empty($imagePath)) {
                    $imagePath = $imgElement->getAttribute('src');
                }
            }
            
            // Status der E-Mail-Benachrichtigung
            $emailNotification = $this->getOptionStatus($xpath, $item, 'a_' . $id);
            
            // Status der E-Mail-Erinnerung
            $emailReminder = $this->getOptionStatus($xpath, $item, 'b_' . $id);
            
            // Status des TV-Planers
            $tvPlanner = $this->getOptionStatus($xpath, $item, 'c_' . $id);
            
            // Status der Stream-Benachrichtigung
            $streamNotification = $this->getOptionStatus($xpath, $item, 'e_' . $id);
            
            // Anzahl gesehener Episoden und Gesamtanzahl extrahieren
            $episodesElement = $xpath->query('.//a[@class="link"]', $item)->item(0);
            $episodesText = $episodesElement ? trim($episodesElement->textContent) : '';
            
            $episodesSeen = 0;
            $episodesTotal = 0;
            
            if (preg_match('/(\d+)\/(\d+) Episoden gesehen/', $episodesText, $matches)) {
                $episodesSeen = (int)$matches[1];
                $episodesTotal = (int)$matches[2];
            }
            
            // Serie zum Array hinzufügen
            $series[] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'imagePath' => $imagePath,
                'emailNotification' => $emailNotification,
                'emailReminder' => $emailReminder,
                'tvPlanner' => $tvPlanner,
                'streamNotification' => $streamNotification,
                'episodesSeen' => $episodesSeen,
                'episodesTotal' => $episodesTotal
            ];
            
            $this->utilHandler->log("Serie gefunden: $name (ID: $id)");
        }
        
        // Array neu indizieren, damit die Indizes fortlaufend sind
        $series = array_values($series);
        
        $this->utilHandler->log("Anzahl der Serien nach Filterung: " . count($series));
        
        return $series;
    }
    
    /**
     * Holt den Status einer Option (aktiviert/deaktiviert)
     * @param DOMXPath $xpath XPath-Objekt
     * @param DOMElement $item Element der Serie
     * @param string $id ID der Option
     * @return bool Status der Option (true = aktiviert, false = deaktiviert)
     */
    private function getOptionStatus($xpath, $item, $id) {
        $element = $xpath->query('.//*[@id="' . $id . '"]', $item)->item(0);
        
        if (!$element) {
            return false;
        }
        
        // Je nach Klasse des Elements, Status zurückgeben
        $spanElement = $xpath->query('.//span', $element)->item(0);
        
        if (!$spanElement) {
            return false;
        }
        
        $class = $spanElement->getAttribute('class');
        
        // Prüfen, ob die Option aktiviert ist (z.B. "bell" vs "bell-slash")
        if (strpos($class, '-slash') !== false) {
            return false;
        }
        
        return true;
    }

    /**
 * DATEI: serienrecorder.class.wunschliste.php
 * 
 * NEUE METHODE HINZUFÜGEN am Ende der SerienRecorderWunschliste-Klasse
 * (vor der schließenden Klammer der Klasse)
 */

    /**
     * Holt Serien aus dem wunschliste.de TV-Planer
     * @return array Array mit Seriennamen oder Fehler-Array
     */
    public function getTVPlanerShows() {
        $this->utilHandler->log("🌐 Rufe wunschliste.de TV-Planer ab...");
        
        // Stelle sicher, dass wir eingeloggt sind
        if (!$this->isLoggedIn) {
            if (!$this->login()) {
                return ['error' => 'Nicht eingeloggt - Login fehlgeschlagen'];
            }
        }
        
        $ch = $this->prepareCurl();
        curl_setopt($ch, CURLOPT_URL, 'https://www.wunschliste.de/ajax/tvplaner.pl?ajax=1&start=1&tag=');
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->utilHandler->log("❌ Curl-Fehler: $error");
            return ['error' => "wunschliste.de nicht erreichbar: $error"];
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $this->utilHandler->log("❌ HTTP-Fehler: $httpCode");
            return ['error' => "wunschliste.de HTTP-Fehler: $httpCode"];
        }
        
        if (empty($result)) {
            $this->utilHandler->log("❌ Leere Antwort");
            return ['error' => "wunschliste.de lieferte leere Antwort"];
        }
        
        $this->utilHandler->log("✅ TV-Planer-Daten erhalten: " . strlen($result) . " Zeichen");
        
        // HTML parsen und Serien extrahieren
        return $this->parseTVPlanerHTML($result);
    }

    /**
     * Parst HTML vom TV-Planer und extrahiert Seriennamen
     * @param string $html HTML-Inhalt
     * @return array Array mit eindeutigen Seriennamen
     */
    private function parseTVPlanerHTML($html) {
        // Sicherstellen, dass der HTML-Code als UTF-8 vorliegt
        if (!mb_check_encoding($html, 'UTF-8')) {
            $this->utilHandler->log("HTML-Code nicht in UTF-8, konvertiere...");
            $html = mb_convert_encoding($html, 'UTF-8', 'auto');
        }
        
        // Pattern für <label class="sendung">Serienname</label>
        $pattern = '/<label\s+class="sendung"[^>]*>(.*?)<\/label>/i';
        
        if (!preg_match_all($pattern, $html, $matches)) {
            $this->utilHandler->log("⚠️ Keine Serien in TV-Planer HTML gefunden");
            return [];
        }
        
        $seriesNames = [];
        
        foreach ($matches[1] as $rawName) {
            // HTML-Entities dekodieren
            $cleanName = html_entity_decode($rawName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            
            // HTML-Tags entfernen
            $cleanName = strip_tags($cleanName);
            
            // Trimmen
            $cleanName = trim($cleanName);
            
            // Leere Namen überspringen
            if (empty($cleanName)) {
                continue;
            }
            
            // Duplikate vermeiden (case-insensitive)
            $lowerName = mb_strtolower($cleanName, 'UTF-8');
            if (!isset($seriesNames[$lowerName])) {
                $seriesNames[$lowerName] = $cleanName;
            }
        }
        
        $uniqueNames = array_values($seriesNames);
        $this->utilHandler->log("📺 " . count($uniqueNames) . " eindeutige Serien aus " . count($matches[1]) . " Einträgen extrahiert");
        
        return $uniqueNames;
    }
}