<?php
/**
 * helpers.php - Zentrale Utility-Funktionen für E3DC-Control Web-Interface
 */

date_default_timezone_set('Europe/Berlin');

// ==================== ERROR HANDLING ====================

/**
 * Prüft den Dateizugriff und zeigt eine benutzerfreundliche Fehlermeldung
 * 
 * @param string $path Dateipfad
 * @param string $operation 'read', 'write', or 'exists'
 * @return bool|string true bei Erfolg, oder HTML mit Fehlermeldung
 */
function checkFileAccess($path, $operation = 'read') {
    // Sicherheit: Nur absolute Pfade oder relative im bekannten Verzeichnis
    if (strpos($path, '..') !== false) {
        return errorMessage("Ungültiger Pfad: Pfad-Traversal erkannt.");
    }

    if (!file_exists($path)) {
        return errorMessage(
            "Datei nicht gefunden",
            "Der Zugriff auf <code>" . htmlspecialchars($path) . "</code> ist fehlgeschlagen. " .
            "Möglicherweise existiert die Datei nicht oder der Pfad ist falsch."
        );
    }

    if ($operation === 'read' && !is_readable($path)) {
        return errorMessage(
            "Leseberechtigung fehlt",
            "Die Datei <code>" . htmlspecialchars($path) . "</code> kann nicht gelesen werden. " .
            "Bitte prüfen Sie die Dateiberechtigungen (chmod 755 oder 644)."
        );
    }

    if ($operation === 'write' && !is_writable($path)) {
        $parent = dirname($path);
        $isParentWritable = is_writable($parent) ? "ja" : "nein";
        
        return errorMessage(
            "Schreibberechtigung fehlt",
            "Die Datei <code>" . htmlspecialchars($path) . "</code> kann nicht geschrieben werden. " .
            "Bitte prüfen Sie die Dateiberechtigungen. Eltern-Verzeichnis schreibbar: " . $isParentWritable
        );
    }

    if ($operation === 'mkdir' && !is_dir($path)) {
        if (!@mkdir($path, 0755, true)) {
            return errorMessage(
                "Verzeichnis konnte nicht erstellt werden",
                "Das Verzeichnis <code>" . htmlspecialchars($path) . 
                "</code> existiert nicht und konnte nicht erstellt werden."
            );
        }
    }

    return true;
}

/**
 * Formatiert eine Fehlermeldung als HTML-Box
 */
function errorMessage($title, $details = '') {
    $html = '<div class="error-box" style="background:#3a2f2f; border-left:4px solid #e74c3c; padding:20px; margin:20px 0; border-radius:4px;">';
    $html .= '<h3 style="color:#e74c3c; margin-top:0;">' . htmlspecialchars($title) . '</h3>';
    if ($details) {
        $html .= '<p style="margin:10px 0 0 0; color:#ccc; line-height:1.6;">' . $details . '</p>';
    }
    $html .= '</div>';
    return $html;
}

// ==================== INSTALL PATH ====================

/**
 * Liefert Installationspfade aus e3dc_paths.json oder Fallback.
 */
function getInstallPaths() {
    $defaultUser = 'pi';
    $defaultPath = '/home/pi/E3DC-Control/';
    $configFile = '/var/www/html/e3dc_paths.json';

    if (is_readable($configFile)) {
        $json = @file_get_contents($configFile);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data) && !empty($data['install_path'])) {
                $path = rtrim($data['install_path'], '/') . '/';
                return [
                    'install_user' => $data['install_user'] ?? $defaultUser,
                    'install_path' => $path,
                    'home_dir' => $data['home_dir'] ?? '/home/' . ($data['install_user'] ?? $defaultUser)
                ];
            }
        }
    }

    return [
        'install_user' => $defaultUser,
        'install_path' => $defaultPath,
        'home_dir' => '/home/' . $defaultUser
    ];
}

function getInstallPath() {
    $paths = getInstallPaths();
    return $paths['install_path'];
}

/**
 * Ermittelt den korrekten Python-Interpreter (venv oder system).
 */
function getPythonInterpreter() {
    $paths = getInstallPaths();
    // Lese venv_name aus e3dc_paths.json (wird dort von installer_main.py hinterlegt)
    $json = @json_decode(@file_get_contents('/var/www/html/e3dc_paths.json'), true);
    $venvName = $json['venv_name'] ?? '.venv_e3dc';
    
    // 1. Expliziter Pfad aus Config (neu)
    if (!empty($json['venv_path']) && file_exists($json['venv_path'] . '/bin/python3')) {
        return $json['venv_path'] . '/bin/python3';
    }

    // 2. Standard Pfad (Home)
    $venvPython = rtrim($paths['home_dir'], '/') . '/' . $venvName . '/bin/python3';
    if (file_exists($venvPython) && is_executable($venvPython)) {
        return $venvPython;
    }
    
    // 3. Legacy Pfad (Install Dir) - Fallback
    $legacyPython = rtrim($paths['install_path'], '/') . '/' . $venvName . '/bin/python3';
    if (file_exists($legacyPython) && is_executable($legacyPython)) {
        return $legacyPython;
    }

    return '/usr/bin/python3';
}

/**
 * Erzeugt eine Seiten-URL im aktuellen Kontext (mobile.php oder index.php).
 *
 * @param string $seite Zielseite
 * @param array $params Zusätzliche Query-Parameter
 * @return string
 */
function getContextPageUrl($seite, $params = []) {
    $script = basename($_SERVER['PHP_SELF'] ?? 'index.php');
    $entrypoint = ($script === 'mobile.php') ? 'mobile.php' : 'index.php';

    $query = array_merge(['seite' => $seite], $params);
    return $entrypoint . '?' . http_build_query($query);
}

/**
 * Prüft, ob ein Diagramm-Update gemäß der 15-Minuten-Regel fällig ist.
 * Regel: Maximal alle 15 Minuten, ca. 2 Minuten nach jeder Viertelstunde.
 */
function isDiagramUpdateDue($stampFile) {
    if (!file_exists($stampFile)) return true;
    
    $lastUpdate = filemtime($stampFile);
    $now = time();
    $minSinceLast = ($now - $lastUpdate) / 60;
    
    // Aktuelle Minute der Stunde
    $currentMin = (int)date('i', $now);
    
    // Bestimme den Start der aktuellen Viertelstunde (0, 15, 30, 45)
    $currentSlotStartMin = floor($currentMin / 15) * 15;
    
    // Der "Update-Zeitpunkt" ist 2 Minuten nach Slot-Start
    $updateThresholdMin = $currentSlotStartMin + 2;
    
    // Fall 1: Wir sind in der "Pufferzeit" (z.B. Minute 0-1) nach einer Viertelstunde.
    // Wir warten, bis Minute 2 erreicht ist. Damit weichen wir unnötigen Skriptläufen aus.
    if ($currentMin < $updateThresholdMin) {
        // Falls das letzte Update länger als 20 Minuten her ist (Sicherheitsnetz), trotzdem fällig
        return ($minSinceLast > 20);
    }
    
    // Fall 2: Wir sind nach Minute 2 (z.B. Minute 18). 
    // Wir schauen nach, ob das letzte Update heute schon nach Minute 17 (nach Slot-Start + 2) gelaufen ist.
    $fälligAb = strtotime(date('Y-m-d H:', $now) . sprintf('%02d', $updateThresholdMin) . ':00');
    
    return ($lastUpdate < $fälligAb);
}

/**
 * Gibt Erfolgs- oder Info-Meldung aus
 */
function successMessage($message) {
    return '<div class="success-box" style="background:#2d3d2a; border-left:4px solid #27ae60; padding:15px; margin:15px 0; border-radius:4px; color:#27ae60; font-weight:bold;">' 
           . htmlspecialchars($message) . '</div>';
}

// ==================== DATEIOPERATIONEN ====================

/**
 * Sichere Datei-Leseoperation mit Fehlerbehandlung
 * 
 * @param string $path Dateipfad
 * @param bool $asArray true = array, false = string
 * @return array|string|false Dateiinhalt oder false bei Fehler
 */
function safeReadFile($path, $asArray = false) {
    $check = checkFileAccess($path, 'read');
    if ($check !== true) {
        return false;
    }

    if ($asArray) {
        return file($path, FILE_IGNORE_NEW_LINES) ?: false;
    }
    return file_get_contents($path) ?: false;
}

/**
 * Sichere Datei-Schreiboperation mit Fehlerbehandlung
 */
function safeWriteFile($path, $content, $flags = LOCK_EX) {
    $check = checkFileAccess($path, 'write');
    if ($check !== true) {
        return false;
    }

    return @file_put_contents($path, $content, $flags) !== false;
}

// ==================== VALIDIERUNG ====================

/**
 * Validiert einen Dateinamen gegen Path-Traversal-Attacken
 */
function validateFilename($filename) {
    // Nur alphanumerisch, Punkte, Unterstriche, Bindestriche
    if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $filename)) {
        return false;
    }
    // basename() entfernt Pfade
    if (basename($filename) !== $filename) {
        return false;
    }
    return true;
}

/**
 * Sanitiert Benutzereingaben
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Prüft auf erforderliche POST-Parameter
 */
function requirePostParams($required = []) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return true;
    }

    foreach ($required as $param) {
        if (!isset($_POST[$param]) || $_POST[$param] === '') {
            die(errorMessage("Erforderlicher Parameter fehlt", "Parameter: " . htmlspecialchars($param)));
        }
    }
    return true;
}

// ==================== KONFIGURATION ====================

/**
 * Lädt die E3DC-Konfiguration mit Fehlerbehandlung
 */
function loadE3dcConfig($basePath = null) {
    if ($basePath === null) {
        $basePath = getInstallPath();
    }
    $configFile = $basePath . 'e3dc.config.txt';
    
    $check = checkFileAccess($configFile, 'read');
    if ($check !== true) {
        return ['error' => $check, 'config' => []];
    }

    $lines = file($configFile, FILE_IGNORE_NEW_LINES);
    $config = [];

    foreach ($lines as $line) {
        $line = trim($line);
        // Kommentare überspringen
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!empty($key)) {
                $config[strtolower($key)] = $value;
            }
        }
    }

    return ['error' => null, 'config' => $config];
}

// ==================== LOGGING ====================

/**
 * Optionales Logging (für Debugging)
 */
function debugLog($message, $data = null) {
    if (!defined('DEBUG_MODE') || !DEBUG_MODE) {
        return;
    }

    $logFile = '/var/www/html/tmp/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] " . $message;
    
    if ($data !== null) {
        $logMessage .= " | " . json_encode($data);
    }
    
    @error_log($logMessage . "\n", 3, $logFile);
}

// ==================== HTML UTILITIES ====================

/**
 * Formatiert einen Datetime-String
 */
function formatDateTime($timestamp, $format = 'd.m.Y H:i') {
    if (is_numeric($timestamp)) {
        return date($format, $timestamp);
    }
    return htmlspecialchars($timestamp);
}

/**
 * Erstellt ein sicheres Button-HTML-Element
 */
function createButton($label, $url = '', $class = 'form-button', $onclick = '') {
    if ($url) {
        return '<a href="' . htmlspecialchars($url) . '" class="' . $class . '">' 
               . htmlspecialchars($label) . '</a>';
    }
    return '<button type="button" class="' . $class . '" onclick="' . htmlspecialchars($onclick) . '">' 
           . htmlspecialchars($label) . '</button>';
}

/**
 * Liest den Update-Status aus dem Cache (für PHP-Rendering).
 */
function getUpdateStatusFromCache() {
    $cacheFile = '/tmp/e3dc_update_status.json';
    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data) && isset($data['success']) && $data['success'] && isset($data['missing'])) {
                return (int)$data['missing'];
            }
        }
    }
    return 0;
}

/**
 * Behandelt die Vorbereitung des Updates (Flags setzen).
 * Sollte am Anfang von index.php und mobile.php aufgerufen werden.
 */
function handleUpdatePreparation() {
    if (isset($_GET['action']) && $_GET['action'] === 'prepare_update') {
        $force = isset($_GET['force']) && $_GET['force'] === 'true';
        $discard = isset($_GET['discard']) && $_GET['discard'] === 'true';
        $flagFile = '/tmp/e3dc_update_flags.json';
        // Flags speichern, damit Python sie lesen kann
        file_put_contents($flagFile, json_encode(['force' => $force, 'discard' => $discard]));
        @chmod($flagFile, 0666); // Lesbar für alle
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

/**
 * Prüft auf verfügbare Updates (Git Fetch).
 * Liefert JSON zurück. Cache-Dauer: 4 Stunden.
 */
function handleUpdateCheck() {
    if (isset($_GET['action']) && $_GET['action'] === 'check_update') {
        // Caching verhindern (Wichtig für Cloudflare/Browser)
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: application/json');
        
        // Config prüfen: Wenn check_updates=0, dann abbrechen (außer force_check ist gesetzt)
        $confData = loadE3dcConfig();
        $checkUpdates = $confData['config']['check_updates'] ?? '1';
        if ($checkUpdates === '0' && !isset($_GET['force_check'])) {
            echo json_encode(['success' => true, 'missing' => 0, 'skipped' => true]);
            exit;
        }

        $cacheFile = '/tmp/e3dc_update_status.json';
        
        // Cache nutzen (4 Stunden = 14400 Sekunden)
        // Mit ?force_check=1 kann der Cache umgangen werden
        if (!isset($_GET['force_check']) && file_exists($cacheFile) && (time() - filemtime($cacheFile) < 14400)) {
            echo file_get_contents($cacheFile);
            exit;
        }
        
        $paths = getInstallPaths();
        $user = escapeshellarg($paths['install_user']);
        $path = escapeshellarg($paths['install_path']);

        // 1. Fetch updates (fetch origin updates remote refs zuverlässiger als fetch origin master)
        $cmd = "timeout 20s sudo -u $user git -C $path fetch origin 2>&1";
        exec($cmd, $out, $ret);
        
        $missing = 0;
        if ($ret === 0) {
            // 2. Ziel-Branch ermitteln (Upstream oder Fallback auf origin/master bzw. main)
            $gitBase = "sudo -u $user git -C $path";
            
            // Default Fallback
            $target = 'origin/master';
            
            // Prüfen ob origin/master existiert, sonst main probieren
            $hasMaster = shell_exec("$gitBase rev-parse --verify origin/master 2>/dev/null");
            if (!trim($hasMaster)) {
                $target = 'origin/main';
            }
            
            // Wenn ein Upstream konfiguriert ist (@{u}), diesen bevorzugen
            $upstream = shell_exec("$gitBase rev-parse --abbrev-ref --symbolic-full-name @{u} 2>/dev/null");
            if (trim($upstream)) {
                $target = trim($upstream);
            }
            
            // 3. Commits zählen
            $countCmd = "$gitBase rev-list --count HEAD.." . escapeshellarg($target) . " 2>/dev/null";
            $count = shell_exec($countCmd);
            
            if (is_numeric(trim($count))) {
                $missing = (int)$count;
            }
        }

        $res = ['success' => ($ret === 0), 'missing' => $missing];
        if ($ret !== 0) {
            $res['error'] = implode("\n", $out);
        }
        
        file_put_contents($cacheFile, json_encode($res));
        echo json_encode($res);
        exit;
    }
}

/**
 * Führt den Neustart des E3DC-Services aus.
 */
function handleServiceRestart() {
    if (isset($_GET['action']) && $_GET['action'] === 'restart_service') {
        header('Content-Type: application/json');
        exec("sudo /bin/systemctl restart e3dc 2>&1", $out, $ret);
        if ($ret === 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => implode("\n", $out)]);
        }
        exit;
    }
}

/**
 * Prüft den Status des Watchdog-Services (piguard).
 * Liefert JSON zurück: {installed: bool, active: bool, warning: bool, message: string}
 */
function handleWatchdogStatus() {
    if (isset($_GET['action']) && $_GET['action'] === 'watchdog_status') {
        header('Content-Type: application/json');
        
        $scriptPath = '/usr/local/bin/pi_guard.sh';
        if (!file_exists($scriptPath)) {
            echo json_encode(['installed' => false]);
            exit;
        }
        
        // Status prüfen (systemctl is-active gibt 'active' oder 'inactive' zurück)
        exec("systemctl is-active piguard 2>&1", $out, $ret);
        $isActive = (trim(implode('', $out)) === 'active');
        
        $warning = false;
        $message = $isActive ? 'Watchdog aktiv' : 'Watchdog inaktiv';

        // Warnung prüfen (Datei-Alter)
        // Wir lesen MONITOR_FILE aus dem Skript, um die Logik synchron zu halten
        $content = @file_get_contents($scriptPath);
        if ($content && preg_match('/MONITOR_FILE="([^"]*)"/', $content, $m)) {
            $monFile = trim($m[1]);
            if ($monFile) {
                // Platzhalter {{day}} auflösen (wie im Bash-Skript)
                if (strpos($monFile, '{{day}}') !== false) {
                    $pattern = str_replace('{{day}}', '*', $monFile);
                    $files = glob($pattern);
                    if ($files) {
                        // Neueste Datei zuerst (analog zu ls -t)
                        usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
                        $monFile = $files[0];
                    } else {
                        // Fallback auf Wochentag
                        $days = [1=>'Mo', 2=>'Di', 3=>'Mi', 4=>'Do', 5=>'Fr', 6=>'Sa', 7=>'So'];
                        $monFile = str_replace('{{day}}', $days[date('N')], $monFile);
                    }
                }
                
                if (file_exists($monFile)) {
                    $age = time() - filemtime($monFile);
                    if ($age > 900) { // > 15 Min (900 Sek)
                        $warning = true;
                        $min = floor($age / 60);
                        $message = "Warnung: Protokoll seit {$min} Min. nicht aktualisiert!";
                    }
                }
            }
        }
        
        echo json_encode([
            'installed' => true, 
            'active' => $isActive, 
            'warning' => $warning, 
            'message' => $message
        ]);
        exit;
    }
}

/**
 * Liefert das Watchdog-Log (journalctl) zurück.
 */
function handleWatchdogLog() {
    if (isset($_GET['action']) && $_GET['action'] === 'watchdog_log') {
        header('Content-Type: text/plain');
        // Letzte 50 Einträge, neueste zuerst
        passthru("journalctl -t PIGUARD -n 50 --no-pager --reverse 2>&1");
        exit;
    }
}

/**
 * Erzeugt das HTML für das Verbindungs-Badge (Online/Offline).
 * Einheitlich für Desktop und Mobile.
 */
function renderConnectionBadge() {
    return '<span id="connection-status" class="badge bg-secondary rounded-pill" style="cursor:pointer;" onclick="handleConnectionClick()" title="Status: Klicken zum Aktualisieren">Verbinde...</span>';
}

/**
 * Prüft auf Versions-Anfrage (?check_version) und gibt den Zeitstempel zurück.
 * Beendet das Skript, falls zutreffend.
 */
function handleVersionCheck($file) {
    if (isset($_GET['check_version'])) {
        header('Content-Type: text/plain');
        echo filemtime($file);
        exit;
    }
}

/**
 * Generiert das HTML für das Watchdog-Protokoll Modal.
 */
function renderWatchdogModal($dialogClass = 'modal-lg modal-dialog-scrollable') {
    return '
    <div class="modal fade" id="watchdogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog ' . $dialogClass . '">
            <div class="modal-content bg-dark text-light border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Watchdog Protokoll</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body bg-black p-2">
                    <pre id="watchdog-log-content" style="font-family: monospace; font-size: 0.8rem; color: #ccc; white-space: pre-wrap;">Lade...</pre>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Schließen</button>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Generiert das HTML für das System-Update Modal.
 */
function renderUpdateModal($dialogClass = 'modal-lg modal-dialog-scrollable') {
    return '
    <div class="modal fade" id="updateModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog ' . $dialogClass . '">
            <div class="modal-content bg-dark text-light border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="fas fa-sync fa-spin me-2" id="update-spinner"></i>System Update</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="update-close-btn" style="display:none;"></button>
                </div>
                <div class="modal-body bg-black p-2">
                    <pre id="update-log" style="font-family: monospace; font-size: 0.8rem; color: #0f0; white-space: pre-wrap;">Starte Update-Prozess...</pre>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal" id="update-finish-btn" disabled>Schließen</button>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Generiert das HTML für das Changelog Modal.
 */
function renderChangelogModal($dialogClass = 'modal-lg modal-dialog-scrollable') {
    $logFile = 'CHANGELOG.md';
    $content = "Kein Changelog verfügbar.";
    if (file_exists($logFile)) {
        $content = htmlspecialchars(file_get_contents($logFile));
    }
    
    return '
    <div class="modal fade" id="changelogModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog ' . $dialogClass . '">
            <div class="modal-content bg-dark text-light border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Changelog</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <pre style="white-space: pre-wrap; font-family: monospace; font-size: 0.85rem; color: #ccc;">' . $content . '</pre>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Lädt Preisdaten aus einer statischen Datei (z.B. awattardebug.23.txt),
 * um einen stabilen Tagesgraphen zu ermöglichen.
 */
function loadStaticPriceData($vat = 0) {
    $paths = getInstallPaths();
    $basePath = $paths['install_path'];
    
    // Zeitabhängige Priorisierung:
    // 18:00 - 06:00: Bevorzuge Mittags-Datei (11/12/13 Uhr), um Vorschau auf morgen zu haben.
    // 06:00 - 18:00: Bevorzuge Nacht-Datei (23/00 Uhr) für stabilen Tagesverlauf.
    $hour = (int)date('G');
    if ($hour >= 18 || $hour < 6) {
        $candidates = ['awattardebug.13.txt', 'awattardebug.14.txt', 'awattardebug.23.txt', 'awattardebug.0.txt'];
    } else {
        $candidates = ['awattardebug.23.txt', 'awattardebug.0.txt', 'awattardebug.12.txt', 'awattardebug.13.txt'];
    }
    
    $lines = false;
    $loadedFile = '';

    foreach ($candidates as $f) {
        if (file_exists($basePath . $f)) {
            $lines = @file($basePath . $f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                // Fallback: Versuch via cat (falls PHP-Leserechte klemmen)
                exec("cat " . escapeshellarg($basePath . $f), $out, $ret);
                if ($ret === 0 && !empty($out)) $lines = $out;
            }
            
            if ($lines) {
                $loadedFile = $f;
                break;
            }
        }
    }
    
    if (!$lines) return false;

    $prices = [];
    $startHour = null;
    $interval = null;
    $lastH = null;
    
    foreach ($lines as $line) {
        // BOM entfernen (UTF-8)
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = trim($line);
        
        // Header überspringen
        if (empty($line) || (!is_numeric(substr($line, 0, 1)) && substr($line, 0, 1) !== '-')) {
            if (!empty($prices)) break; // Stop bei neuem Block (z.B. "Data")
            continue;
        }

        // Trenner erkennen (Semikolon oder Whitespace)
        $parts = (strpos($line, ';') !== false) ? explode(';', $line) : preg_split('/\s+/', $line);

        if (count($parts) >= 2 && is_numeric($parts[0])) {
            $rawT = (float)str_replace(',', '.', trim($parts[0]));
            $val = (float)str_replace(',', '.', trim($parts[1]));
            
            // Timestamp (> 48) zu Stunde (0-23.99) konvertieren
            if ($rawT > 48) {
                $h = (float)gmdate('G', (int)$rawT) + ((int)gmdate('i', (int)$rawT)/60);
            } else {
                $h = $rawT;
            }

            
            // Duplikat-Check: Wenn wir wieder beim Start sind (z.B. neuer Block)
            if ($startHour !== null && abs($h - $startHour) < 0.001 && count($prices) > 0) break;

            if ($vat > 0) $val = $val * (1 + ($vat / 100));
            $prices[] = $val;
            
            if ($startHour === null) {
                $startHour = $h;
            } elseif ($interval === null) {
                $diff = $h - $lastH;
                if ($diff < 0) $diff += 24; // Tageswechsel
                if ($diff > 0.001) $interval = $diff;
            }
            $lastH = $h;
        }
    }
    
    return empty($prices) ? false : [
        'prices' => $prices, 
        'start_hour' => $startHour, 
        'interval' => ($interval ?: 1),
        'source' => $loadedFile
    ];
}

/**
 * Liest verfügbare History-Backup-Dateien aus dem Backup-Verzeichnis.
 * Liefert ein Array mit 'file' (Dateiname) und 'label' (formatiertes Datum).
 */
function getHistoryBackupFiles($backupDir = '/var/www/html/tmp/history_backups/') {
    $historyFiles = [];
    if (is_dir($backupDir)) {
        $files = glob($backupDir . 'history_*.txt');
        if ($files) {
            rsort($files); // Neueste zuerst
            foreach ($files as $file) {
                $basename = basename($file);
                if (preg_match('/history_(\d{4}-\d{2}-\d{2})\.txt/', $basename, $m)) {
                    $label = date('d.m.Y', strtotime($m[1]));
                    $historyFiles[] = ['file' => $basename, 'label' => $label];
                }
            }
        }
    }
    return $historyFiles;
}

/**
 * Hilfsfunktion zum Parsen von Kommazahlen aus Config-Dateien.
 */
function parseConfigFloat($val) {
    return (float)str_replace(',', '.', $val);
}

/**
 * Berechnet 24h Mittelwerte aus der History-Datei (Cache 1 Std).
 */
function get24hAverages($filePath) {
    $cacheFile = '/tmp/e3dc_avgs.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return json_decode(file_get_contents($cacheFile), true);
    }

    $avgs = ['home' => 800, 'grid' => 1000, 'wb' => 4000];
    if (file_exists($filePath)) {
        $lines = @file($filePath);
        if ($lines) {
            $lines = array_slice($lines, -1000); // Letzte Einträge
            $sums = ['h' => 0, 'g' => 0, 'w' => 0]; $c = 0;
            foreach ($lines as $l) {
                $d = json_decode($l, true);
                if ($d) { $sums['h'] += abs($d['home_raw']??0); $sums['g'] += abs($d['grid']??0); $sums['w'] += ($d['wb']??0); $c++; }
            }
            if ($c > 0) $avgs = ['home' => $sums['h']/$c, 'grid' => $sums['g']/$c, 'wb' => $sums['w']/$c];
        }
    }
    file_put_contents($cacheFile, json_encode($avgs));
    return $avgs;
}

/**
 * Sucht nach archivierten awattardebug-Dateien.
 */
function getArchivedDebugFiles($basePath) {
    $files = [];
    if (is_dir($basePath)) {
        foreach (glob(rtrim($basePath, '/') . '/awattardebug.*.txt') as $f) {
            if (preg_match('/awattardebug\.(\d+)\.txt$/', $f, $m)) {
                $ts = filemtime($f);
                $files[] = [
                    'file' => basename($f),
                    'ts' => $ts,
                    'label' => date('d.m. H:i', $ts) . " (Run {$m[1]})"
                ];
            }
        }
        usort($files, fn($a, $b) => $b['ts'] <=> $a['ts']);
    }
    return $files;
}

/**
 * Speichert eine Einstellung in der e3dc.config.txt (AJAX).
 */
function handleSaveSetting() {
    if (isset($_POST['action']) && $_POST['action'] === 'save_setting') {
        if (!isset($_POST['key'], $_POST['value'])) exit;
        
        $paths = getInstallPaths();
        $configFile = rtrim($paths['install_path'], '/') . '/e3dc.config.txt';
        $key = trim($_POST['key']);
        $val = trim($_POST['value']);
        
        if (!preg_match('/^[a-z0-9_]+$/i', $key)) { http_response_code(400); exit; }

        $lines = file_exists($configFile) ? file($configFile, FILE_IGNORE_NEW_LINES) : [];
        $newLines = [];
        $found = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=/i', $line)) {
                $newLines[] = "$key = $val";
                $found = true;
            } else {
                $newLines[] = $line;
            }
        }
        if (!$found) $newLines[] = "$key = $val";

        if (file_put_contents($configFile, implode("\n", $newLines) . "\n", LOCK_EX) !== false) echo "ok";
        else { http_response_code(500); echo "error"; }
        exit;
    }
}



/**
 * Führt das System-Update aus (AJAX).
 * Ersetzt run_update.php
 */
function handleRunUpdate() {
    if (isset($_GET['action']) && $_GET['action'] === 'run_update') {
        // Caching verhindern (Wichtig für Cloudflare/Browser)
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: application/json');
        $paths = getInstallPaths();
        
        // Installer suchen
        $candidates = [
            rtrim($paths['install_path'], '/') . '/../Install/installer_main.py',
            $paths['home_dir'] . '/Install/installer_main.py'
        ];
        $installer_main = false;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) { $installer_main = realpath($candidate); break; }
        }
        
        if (!$installer_main) {
            echo json_encode(['status' => 'error', 'message' => "Installer nicht gefunden."]);
            exit;
        }

        $logFile = '/var/www/html/tmp/update.log';
        $pidFile = '/var/www/html/tmp/update.pid';
        $mode = $_GET['mode'] ?? 'start';

        if ($mode === 'start') {
            if (file_exists($pidFile)) {
                $pid = (int)trim(file_get_contents($pidFile));
                if (file_exists("/proc/$pid")) { echo json_encode(['status' => 'running', 'message' => 'Update läuft bereits.']); exit; }
                @unlink($pidFile);
            }
            
            // 1. Log-Datei vorbereiten & Rechte prüfen
            if (file_put_contents($logFile, "=== UPDATE DIAGNOSE START ===\n") === false) {
                echo json_encode(['status' => 'error', 'message' => 'PHP kann Log-Datei nicht schreiben.']);
                exit;
            }
            chmod($logFile, 0666);
            
            // 2. Pfad-Check
            if (!$installer_main) {
                file_put_contents($logFile, "FEHLER: installer_main.py konnte nicht gefunden werden.\n", FILE_APPEND);
                echo json_encode(['status' => 'error', 'message' => 'Installer nicht gefunden.']);
                exit;
            }
            file_put_contents($logFile, "Installer-Pfad: $installer_main\n", FILE_APPEND);

            // 3. Shell-Test (Kann www-data überhaupt schreiben?)
            exec("echo 'Shell-Write-Test: OK' >> " . escapeshellarg($logFile));
            
            // 4. Sudo-Test (Darf www-data sudo nutzen?)
            // (Entfernt, da 'true' nicht zwingend in sudoers steht und zu falschen Fehlern führt)

            $cmd = "sudo -n /usr/bin/python3 " . escapeshellarg($installer_main) . " --update-e3dc";
            file_put_contents($logFile, "Start-Befehl: $cmd\n--------------------------------\n", FILE_APPEND);
            
            // Wir nutzen 'nohup' ohne Pfad (verlässt sich auf PATH), das ist robuster
            $fullCmd = sprintf("nohup %s >> %s 2>&1 & echo $!", $cmd, escapeshellarg($logFile));
            $pid = exec($fullCmd);
            
            if ($pid) { 
                file_put_contents($pidFile, $pid); 
                file_put_contents($logFile, "Prozess gestartet mit PID: $pid\n", FILE_APPEND);
                echo json_encode(['status' => 'started', 'pid' => $pid]); 
            }
            else { echo json_encode(['status' => 'error', 'message' => 'Konnte Prozess nicht starten.']); }
        } elseif ($mode === 'poll') {
            clearstatcache(true, $logFile);
            $log = '';
            $debugInfo = "";
            
            if (file_exists($logFile)) {
                $size = filesize($logFile);
                $content = file_get_contents($logFile);
                
                if ($content === false) {
                    $log = "FEHLER: Log-Datei existiert, kann aber nicht gelesen werden (Rechte?).";
                } elseif (empty($content)) {
                    $log = "Status: Warte auf Start... (Log-Datei ist leer, Größe: $size Bytes)";
                } else {
                    $log = $content;
                }
            } else {
                $log = "Status: Initialisiere... (Log-Datei noch nicht erstellt)";
            }
            $running = false;
            if (file_exists($pidFile)) {
                $pid = (int)trim(file_get_contents($pidFile));
                if (file_exists("/proc/$pid")) $running = true; else @unlink($pidFile);
            }
            
            // JSON Flags für Robustheit (verhindert Absturz bei Emojis/Sonderzeichen)
            $flags = 0;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
            if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) $flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;

            $json = json_encode(['running' => $running, 'log' => $log], $flags);
            
            if ($json === false) {
                echo json_encode(['running' => $running, 'log' => "JSON-Fehler: " . json_last_error_msg()]);
            } else {
                echo $json;
            }
        }
        exit;
    }
}


// ==================== AWATTAR & PROGNOSE PARSING ====================

/**
 * Konvertiert Config-Werte robust in Float (behandelt Komma/Punkt).
 */
function parseNumericConfigValue($value, $default) {
    if (is_int($value) || is_float($value)) return (float)$value;
    if (!is_string($value)) return (float)$default;
    $cleaned = trim($value, " \t\n\r\0\x0B\"'");
    if ($cleaned === '') return (float)$default;
    $normalized = str_replace(',', '.', $cleaned);
    return is_numeric($normalized) ? (float)$normalized : (float)$default;
}

/**
 * Berechnet den Endpreis basierend auf Rohdaten und Zeitstempel (Formatwechsel 19.12.2024).
 */
function calculateAwattarPrice($priceRaw, $sourceTimestamp, $awmwst, $awnebenkosten) {
    $multiplier = ($awmwst / 100.0) + 1.0;
    $switchTs = strtotime('2024-12-19 00:00:00');
    if ($sourceTimestamp > $switchTs) {
        return ($priceRaw * $multiplier) + $awnebenkosten;
    }
    return (($priceRaw / 10.0) * $multiplier) + $awnebenkosten;
}

/**
 * Klassifiziert einen Preis (günstig/teuer) relativ zu Min/Max.
 */
function classifyPriceLevel($price, $minPrice, $maxPrice) {
    if ($price === null || $minPrice === null || $maxPrice === null || $maxPrice <= $minPrice) return 'unknown';
    $range = $maxPrice - $minPrice;
    $avgBandWidth = 0.30 * $range;
    $lowerThreshold = $minPrice + (($range - $avgBandWidth) / 2.0);
    $upperThreshold = $maxPrice - (($range - $avgBandWidth) / 2.0);
    if ($price < $lowerThreshold) return 'cheap';
    if ($price > $upperThreshold) return 'expensive';
    return 'average';
}

/**
 * Hilfsfunktion: Wandelt "12.45" (Viertelstunden) in Minuten des Tages um.
 */
function parseQuarterTimeToMinute($timeToken) {
    if (!preg_match('/^(\d{1,2})\.(\d{2})$/', $timeToken, $tm)) return null;
    $hour = (int)$tm[1];
    $fractionPart = (int)$tm[2] / 100.0;
    $minute = (int)round($fractionPart * 60.0);
    if ($minute >= 60) { $hour += (int)floor($minute / 60); $minute = $minute % 60; }
    return ($hour * 60) + $minute;
}

/**
 * Hilfsfunktion: Formatiert Minuten des Tages zurück in "12.45" Format.
 */
function minuteToSlotLabel($minute) {
    if (!is_int($minute) || $minute < 0) return null;
    $hour = (int)floor($minute / 60);
    $min = $minute % 60;
    return sprintf('%d.%02d', $hour, (int)round(($min / 60) * 100));
}

/**
 * Parst die awattardebug.txt und extrahiert Preise sowie Prognosedaten.
 */
function parsePricesFromAwattarDebug($debugFile, $awmwst, $awnebenkosten, $speichergroesse) {
    if (!file_exists($debugFile)) return [null, null, null, null, null, null, null, null, null, [], null, 1.0, []];
    
    $lines = @file($debugFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [null, null, null, null, null, null, null, null, null, [], null, 1.0, []];

    $prices = [];
    $forecast = [];
    $entries = [];
    $sourceTs = filemtime($debugFile) ?: time();
    $inDataBlock = false;
    $lastMinute = -1;
    $dayOffset = 0;
    $priceStartHour = null;
    $priceInterval = 1.0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (stripos($line, 'Data') === 0) { $inDataBlock = true; continue; }
        if (stripos($line, 'Simulation') === 0) { $inDataBlock = false; continue; }
        if (!$inDataBlock) continue;

        if (preg_match('/^\d{1,2}\.\d{2}\s+(-?\d+(?:\.\d+)?)(?:\s+(-?\d+(?:\.\d+)?)){3,5}$/', $line)) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) >= 2) {
                $minuteOfDay = parseQuarterTimeToMinute($parts[0]);
                if ($minuteOfDay !== null) {
                    if ($lastMinute !== -1 && $minuteOfDay < $lastMinute) $dayOffset += 1440;
                    $lastMinute = $minuteOfDay;
                    $minuteOfDay += $dayOffset;
                }
                $candidateRaw = (float)$parts[1];
                $candidate = calculateAwattarPrice($candidateRaw, $sourceTs, $awmwst, $awnebenkosten);
                
                if ($candidate >= 0 && $candidate <= 100) {
                    if ($priceStartHour === null) $priceStartHour = (float)$parts[0];
                    elseif (count($prices) === 1) {
                        $iv = (float)$parts[0] - $priceStartHour;
                        if ($iv > 0) $priceInterval = $iv;
                    }
                    $prices[] = $candidate;
                    if ($minuteOfDay !== null) $entries[] = ['minute' => $minuteOfDay, 'price' => $candidate];
                    
                    // Prognose (Spalte 4)
                    if (count($parts) >= 5) {
                        $pvRaw = (float)$parts[4];
                        $pvVal = $pvRaw * $speichergroesse * 40;
                        $h = (float)$parts[0] + ($dayOffset / 60.0);
                        $forecast[] = ['h' => $h, 'w' => $pvVal];
                    }
                }
            }
        }
    }

    if (empty($prices)) return [null, null, null, null, null, null, null, null, null, [], null, 1.0, []];

    $current = $prices[0];
    $selectedMinute = null; $targetMinute = null;
    if (!empty($entries)) {
        $nowMinuteRaw = ((int)gmdate('G') * 60) + (int)gmdate('i');
        $targetMinute = (int)(round($nowMinuteRaw / 15) * 15);
        if ($targetMinute >= 1440) $targetMinute -= 1440;
        $bestEntry = $entries[0];
        $bestDist = abs(($bestEntry['minute'] % 1440) - $targetMinute);
        $bestDist = min($bestDist, 1440 - $bestDist);
        foreach ($entries as $entry) {
            $dist = abs(($entry['minute'] % 1440) - $targetMinute);
            $dist = min($dist, 1440 - $dist);
            if ($dist < $bestDist) { $bestDist = $dist; $bestEntry = $entry; }
        }
        $current = $bestEntry['price']; $selectedMinute = $bestEntry['minute'];
    }
    $min = min($prices); $max = max($prices);
    $minSlot = null; $maxSlot = null;
    foreach ($entries as $entry) {
        if ($entry['price'] === $min && $minSlot === null) $minSlot = minuteToSlotLabel($entry['minute']);
        if ($entry['price'] === $max && $maxSlot === null) $maxSlot = minuteToSlotLabel($entry['minute']);
    }
    return [$current, $min, $max, minuteToSlotLabel($selectedMinute), minuteToSlotLabel($targetMinute), $min, $minSlot, $max, $maxSlot, $prices, $priceStartHour, $priceInterval, $forecast];
}
?>
