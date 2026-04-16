<?php
// get_live_json.php
require_once 'helpers.php';
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$liveFile = '/var/www/html/ramdisk/live.txt';
$liveHistoryFile = '/var/www/html/ramdisk/live_history.txt';
$liveHistoryHours = 48;
$data = [
    'pv' => 0,
    'bat' => 0,
    'home_raw' => 0,
    'grid' => 0,
    'soc' => 0,
    'wb' => 0,
    'wp' => 0,
    'dc0_w' => 0, 'dc0_v' => 0, 'dc0_a' => 0,
    'dc1_w' => 0, 'dc1_v' => 0, 'dc1_a' => 0,
    'ac0_w' => 0, 'ac0_v' => 0, 'ac0_a' => 0,
    'ac1_w' => 0, 'ac1_v' => 0, 'ac1_a' => 0,
    'ac2_w' => 0, 'ac2_v' => 0, 'ac2_a' => 0,
    'wb_p1' => 0, 'wb_p2' => 0, 'wb_p3' => 0,
    'grid_p1' => 0, 'grid_p2' => 0, 'grid_p3' => 0,
    'bat_v' => 0, 'bat_a' => 0,
    'wb_status' => '',
    'wb_locked' => false,
    'wb_mode' => 0,
    'price_ct' => null,
    'price_level' => 'unknown',
    'price_slot_gmt' => null,
    'price_target_slot_gmt' => null,
    'price_source' => null,
    'price_min_ct' => null,
    'price_min_slot' => null,
    'price_max_ct' => null,
    'price_max_slot' => null,
    'prices' => [],
    'price_start_hour' => null,
    'price_interval' => 1.0,
    'forecast' => [],
    'time' => '--:--',
    'ts' => 0
];

$paths = getInstallPaths();
$configFile = rtrim($paths['install_path'], '/') . '/e3dc.config.txt';

// Config laden via helpers.php Funktion
$confData = loadE3dcConfig();
$awmwst = isset($confData['config']['awmwst']) ? parseNumericConfigValue($confData['config']['awmwst'], 19.0) : 19.0;
$awnebenkosten = isset($confData['config']['awnebenkosten']) ? parseNumericConfigValue($confData['config']['awnebenkosten'], 0.0) : 0.0;
$speichergroesse = isset($confData['config']['speichergroesse']) ? parseNumericConfigValue($confData['config']['speichergroesse'], 0.0) : 0.0;
$wurzelzaehler = isset($confData['config']['wurzelzaehler']) ? (int)$confData['config']['wurzelzaehler'] : 0;

$wbOut = rtrim($paths['install_path'], '/') . '/e3dc.wallbox.out';
$data['wb_plan_hash'] = (file_exists($wbOut)) ? md5_file($wbOut) : '';

$currentPrice = null;

$currentPrice = null;
$minPrice = null;
$maxPrice = null;

// Min/Max Preis und Zeiten aus awattardebug extrahieren
$debugFile = rtrim($paths['install_path'], '/') . '/awattardebug.txt';
[$_unused, $minPrice, $maxPrice, $selectedSlot, $targetSlot, $minCt, $minSlot, $maxCt, $maxSlot, $prices, $priceStartHour, $priceInterval, $forecast] = parsePricesFromAwattarDebug($debugFile, $awmwst, $awnebenkosten, $speichergroesse);
if ($minPrice !== null) {
    $data['price_min_ct'] = round($minCt, 2);
    $data['price_min_slot'] = $minSlot;
}
if ($maxPrice !== null) {
    $data['price_max_ct'] = round($maxCt, 2);
    $data['price_max_slot'] = $maxSlot;
}
$data['prices'] = $prices;
$data['price_start_hour'] = $priceStartHour;
$data['price_interval'] = $priceInterval;
$data['forecast'] = $forecast;

$validData = false; // Flag für gültige Daten
// Aktueller Preis aus liveFile (RB-Zeile) extrahieren
if (file_exists($liveFile)) {
    $content = file_get_contents($liveFile);
    $mtime = filemtime($liveFile);
    $data['time'] = date("H:i:s", $mtime);
    $data['ts'] = $mtime;
    if (preg_match('/PV\s+\d+\+\d+=(\d+)\s+BAT\s+(-?\d+)\s+home\s+(\d+)\s+grid\s+(-?\d+)/', $content, $m)) {
        $data['pv'] = (int)$m[1];
        $data['bat'] = (int)$m[2];
        $data['home_raw'] = (int)$m[3];
        $data['grid'] = (int)$m[4];
        $validData = true; // Validierung erfolgreich: Wir haben echte Werte
    }
    // SOC Zeile mit Spannung und Strom: SOC 93.00%  56.7V  22.20A
    if (preg_match('/SOC\s+(\d+\.?\d*)%\s+([-\d\.]+)V\s+([-\d\.]+)A/', $content, $m)) {
        $data['soc'] = (float)$m[1];
        $data['bat_v'] = (float)$m[2];
        $data['bat_a'] = (float)$m[3];
    } elseif (preg_match('/SOC\s+(\d+\.?\d*)%/', $content, $m)) {
        $data['soc'] = (float)$m[1];
    }

    if (preg_match('/Total\s+([\d\.]+)\s+W/', $content, $m)) $data['wb'] = (float)$m[1] * 1;

    // Aktueller Preis aus RB-Zeile extrahieren (Wert nach letztem Prozentzeichen)
    if (preg_match('/RB.*?%.*?%.*?%([^%\n]*)/', $content, $pm)) {
        $priceStr = trim($pm[1]);
        // Der Preis kann mehrere Werte enthalten, wir nehmen den ersten Float
        if (preg_match('/(-?\d+(?:\.\d+)?)/', $priceStr, $val)) {
            $currentPrice = (float)$val[1];
            $data['price_source'] = 'live_rb';
        }
    }

    // DC Strings (PV Details)
    // DC0 1412 W  567 V 2.53 A DC1 2878 W  465 V 6.17 A
    if (preg_match('/DC0\s+(\d+)\s*W\s+(\d+)\s*V\s+([\d\.]+)\s*A\s+DC1\s+(\d+)\s*W\s+(\d+)\s*V\s+([\d\.]+)\s*A/', $content, $m)) {
        $data['dc0_w'] = (int)$m[1]; $data['dc0_v'] = (int)$m[2]; $data['dc0_a'] = (float)$m[3];
        $data['dc1_w'] = (int)$m[4]; $data['dc1_v'] = (int)$m[5]; $data['dc1_a'] = (float)$m[6];
    }

    // AC Phases (Grid/Inverter Details)
    // AC0 376W 238V 1.65A AC1 364W 242V 1.60A AC2 413W 241V 1.77A
    if (preg_match('/AC0\s+([-\d\.]+)W\s+([-\d\.]+)V\s+([-\d\.]+)A\s+AC1\s+([-\d\.]+)W\s+([-\d\.]+)V\s+([-\d\.]+)A\s+AC2\s+([-\d\.]+)W\s+([-\d\.]+)V\s+([-\d\.]+)A/', $content, $m)) {
        $data['ac0_w'] = (int)$m[1]; $data['ac0_v'] = (int)$m[2]; $data['ac0_a'] = (float)$m[3];
        $data['ac1_w'] = (int)$m[4]; $data['ac1_v'] = (int)$m[5]; $data['ac1_a'] = (float)$m[6];
        $data['ac2_w'] = (int)$m[7]; $data['ac2_v'] = (int)$m[8]; $data['ac2_a'] = (float)$m[9];
    }

    // Wallbox Phases
    // WB is 0.0 W 0.0 W 0.0 W Total 0.0 W
    if (preg_match('/WB is\s+([\d\.]+)\s*W\s+([\d\.]+)\s*W\s+([\d\.]+)\s*W/', $content, $m)) {
        $data['wb_p1'] = (float)$m[1]; $data['wb_p2'] = (float)$m[2]; $data['wb_p3'] = (float)$m[3];
    }

    // Wallbox Status (Lock)
    // WB: Mode B8 00 SUN lock start charge Auto 7/7A
    if (preg_match('/WB:.*?\slock\s/', $content)) {
        $data['wb_locked'] = true;
    }

    // WBMode
    // AVal 362/281/5517 Power 4210 WBMode 4 iWBStatus rq 1 0 7 7
    if (preg_match('/WBMode\s+(\d+)/', $content, $m)) {
        $data['wb_mode'] = (int)$m[1];
    }

    // Grid Phases
    // #0 344.0W -205.0W -136.0W #3.0W (abhängig von wurzelzaehler)
    if (preg_match('/#' . $wurzelzaehler . '\s+([-\d\.]+)W\s+([-\d\.]+)W\s+([-\d\.]+)W/', $content, $m)) {
        $data['grid_p1'] = (float)$m[1]; $data['grid_p2'] = (float)$m[2]; $data['grid_p3'] = (float)$m[3];
    }

    // Fallback, falls der Preis nicht aus der RB-Zeile extrahiert werden konnte
    if ($currentPrice === null && preg_match('/RB\s+\d{1,2}:\d{2}\s+\d+\.?\d*%\s+RE\s+\d{1,2}:\d{2}\s+\d+\.?\d*%\s+LE\s+\d{1,2}:\d{2}\s+\d+\.?\d*%\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)/', $content, $pm)) {
        $currentPrice = (float)$pm[1];
        $minPrice = min((float)$pm[2], (float)$pm[3]);
        $maxPrice = max((float)$pm[2], (float)$pm[3], $currentPrice);
        $data['price_source'] = 'live_fallback';
    }
}

// --- Logik für Wärmepumpen-Verbrauch ---
// Ziel: Den präzisesten verfügbaren Wert verwenden.

// 1. Fallback: Lese den ungenauen S0-Wert aus live.txt.
// Dieser Wert enthält oft auch andere Verbraucher (Pumpen, Steuerung etc.) und wird als vorläufiger Wert genutzt.
if (isset($content) && preg_match('/WP.*?([\d\.]+)\s*W/', $content, $m)) {
    $data['wp'] = (float)$m[1] * 1000;
}

// 2. Priorität: Überschreibe mit dem präzisen Wert aus der luxtronik.json, wenn diese aktuell ist.
// Diese Datei wird vom Python-Skript (luxtronik.py) ca. jede Minute aktualisiert und enthält den exakten Verbrauch von Verdichter + Solepumpe.
$luxFile = '/var/www/html/ramdisk/luxtronik.json';
if (file_exists($luxFile) && (time() - filemtime($luxFile) < 120)) { // jünger als 2 Minuten
    $luxJson = json_decode(file_get_contents($luxFile), true);
    if (isset($luxJson['data']['Leistung_Verdichter_W']) || isset($luxJson['data']['Leistung_Solepumpe_W'])) {
        $comp = $luxJson['data']['Leistung_Verdichter_W'] ?? 0;
        $pump = $luxJson['data']['Leistung_Solepumpe_W'] ?? 0;
        // Fix: Wenn Verdichter aus, dann Verbrauch 0 (verhindert Geisterwerte)
        if (empty($luxJson['data']['Verdichter_Ein'])) {
            $comp = 0; $pump = 0;
        }
        $data['wp'] = $comp + $pump;
    }
}
if ($currentPrice !== null) {
    $data['price_ct'] = round($currentPrice, 2);
    $data['price_level'] = classifyPriceLevel($currentPrice, $minPrice, $maxPrice);
}
else {
    $data['price_ct'] = null;
    $data['price_level'] = 'unknown';
}

// Live-History: letzte 48 Stunden in Ramdisk schreiben (ohne price min/max/slots, mit Haus ohne WP)
// PERFORMANCE-FIX: Nur alle 60 Sekunden schreiben, um Pi Zero zu entlasten
$lastWrite = file_exists($liveHistoryFile) ? filemtime($liveHistoryFile) : 0;
// NEU: Nur schreiben, wenn die Zeit abgelaufen ist UND wir gültige Daten gelesen haben ($validData)
if ($validData && (time() - $lastWrite) >= 60) {
    $historyLine = [
        'ts' => date('c'),
        'pv' => $data['pv'],
        'bat' => $data['bat'],
        'home_raw' => $data['home_raw'],
        'home' => (float)$data['home_raw'] - (float)$data['wp'],  // Hausverbrauch ohne Wärmepumpe
        'grid' => $data['grid'],
        'soc' => $data['soc'],
        'wb' => $data['wb'],   // Wallbox
        'wp' => $data['wp'],   // Wärmepumpe
        'price_ct' => $data['price_ct'],
        // Details speichern für Diagramme
        'dc0_w' => $data['dc0_w'],
        'dc0_v' => $data['dc0_v'],
        'dc1_w' => $data['dc1_w'],
        'dc1_v' => $data['dc1_v'],
        'ac0_w' => $data['ac0_w'],
        'ac1_w' => $data['ac1_w'],
        'ac2_w' => $data['ac2_w'],
        'wb_p1' => $data['wb_p1'],
        'wb_p2' => $data['wb_p2'],
        'wb_p3' => $data['wb_p3'],
        'grid_p1' => $data['grid_p1'],
        'grid_p2' => $data['grid_p2'],
        'grid_p3' => $data['grid_p3'],
        'bat_v' => $data['bat_v'],
        'bat_a' => $data['bat_a'],
    ];
    $line = json_encode($historyLine) . "\n";
    // Immer versuchen zu schreiben (is_writable kann auf manchen Servern falsch sein)
    $appendOk = @file_put_contents($liveHistoryFile, $line, LOCK_EX | FILE_APPEND);
    if ($appendOk !== false) {
        $cutoff = time() - ($liveHistoryHours * 3600);
        $content = @file_get_contents($liveHistoryFile);
        if ($content !== false && $content !== '') {
            $lines = explode("\n", trim($content));
            $kept = [];
            foreach ($lines as $ln) {
                if (trim($ln) === '') continue;
                $dec = @json_decode($ln, true);
                
                // Strikte Prüfung: Behalte nur Zeilen, die gültiges JSON sind UND einen Zeitstempel haben.
                if (is_array($dec) && !empty($dec['ts'])) {
                    $t = strtotime($dec['ts']);
                    // Behalte die Zeile, wenn das Datum gültig ist UND nicht zu alt
                    if ($t !== false && $t >= $cutoff) {
                        $kept[] = $ln;
                    }
                }
                // Alle anderen Zeilen (ungültiges JSON, ohne 'ts', zu alt) werden automatisch verworfen.
            }
            // Nur kürzen wenn wir Zeilen entfernen und nicht alles löschen würden
            if (count($kept) < count($lines) && count($kept) > 0) {
                @file_put_contents($liveHistoryFile, implode("\n", $kept) . "\n", LOCK_EX);
            }
        }
    }
}

echo json_encode($data);