<?php
/**
 * logic.php - Zentrale Datenaufbereitung für Mobile & Desktop Dashboard
 * Liest Konfiguration, History-Mittelwerte und aWATTar-Prognosen.
 * Setzt voraus, dass helpers.php bereits eingebunden wurde.
 */

$paths = getInstallPaths();
$configFile = rtrim($paths['install_path'], '/') . '/e3dc.config.txt';
$historyFile = rtrim($paths['install_path'], '/') . '/live_history.txt';

// 1. Auslesen der Config (WP_MAX und PV_MAX aus forecast1)
$wpMax = 5000; 
$pvMax = 10000; // Fallback
$maxBatPower = 3000; // Fallback
$batteryCapacity = 0; // Fallback
$lat = 51.16; $lon = 10.45; // Default (Mitte DE)
$pvStrings = [];
$showForecast = true; // Default an
$darkMode = true; // Default an
$pvAtmosphere = 0.7; // Default atmosphärische Transmission
$luxtronikEnabled = false; // Default aus
$luxtronikIp = '192.168.178.88'; // Default IP aus deinem Skript

if (file_exists($configFile)) {
    $conf = file_get_contents($configFile);
    if (preg_match('/wpmax\s*=\s*([\d\.,]+)/i', $conf, $m)) { $wpMax = parseConfigFloat($m[1]) * 1000; }
    if (preg_match('/maximumLadeleistung\s*=\s*([\d\.,]+)/i', $conf, $m)) { $maxBatPower = parseConfigFloat($m[1]); }
    if (preg_match('/speichergroesse\s*=\s*([\d\.,]+)/i', $conf, $m)) { $batteryCapacity = parseConfigFloat($m[1]); }
    if (preg_match('/hoehe\s*=\s*([\d\.,]+)/i', $conf, $m)) { $lat = parseConfigFloat($m[1]); }
    if (preg_match('/laenge\s*=\s*([\d\.,]+)/i', $conf, $m)) { $lon = parseConfigFloat($m[1]); }
    if (preg_match('/show_forecast\s*=\s*([01]|true|false)/i', $conf, $m)) {
        $v = strtolower($m[1]);
        $showForecast = ($v === '1' || $v === 'true');
    }
    if (preg_match('/darkmode\s*=\s*([01]|true|false)/i', $conf, $m)) {
        $v = strtolower($m[1]);
        $darkMode = ($v === '1' || $v === 'true');
    }
    if (preg_match('/pvatmosphere\s*=\s*([\d\.,]+)/i', $conf, $m)) { $pvAtmosphere = parseConfigFloat($m[1]); }
    if (preg_match('/luxtronik\s*=\s*([01]|true|false)/i', $conf, $m)) {
        $v = strtolower($m[1]);
        $luxtronikEnabled = ($v === '1' || $v === 'true');
    }
    if (preg_match('/luxtronik_ip\s*=\s*([0-9\.]+)/i', $conf, $m)) { $luxtronikIp = $m[1]; }

    // DEBUG: Status prüfen (Zeile einkommentieren zum Testen)
    // file_put_contents('/var/www/html/tmp/luxtronik_status.log', date('H:i:s') . " Luxtronik: " . ($luxtronikEnabled ? 'AN' : 'AUS') . "\n", FILE_APPEND);

    // Alle Forecast-Strings einlesen (z.B. forecast1 = 40/-50/15.4)
    if (preg_match_all('/forecast(\d+)\s*=\s*([\d\.,\-]+)\/([\d\.,\-]+)\/([\d\.,]+)/i', $conf, $matches, PREG_SET_ORDER)) {
        $totalPv = 0;
        foreach ($matches as $m) {
            $p = parseConfigFloat($m[4]) * 1000;
            $pvStrings[] = ['tilt' => parseConfigFloat($m[2]), 'azimuth' => parseConfigFloat($m[3]), 'power' => $p];
            $totalPv += $p;
        }
        if ($totalPv > 0) $pvMax = $totalPv;
    } elseif (preg_match('/forecast1\s*=\s*[^=]+\/([\d\.]+)/i', $conf, $m)) { 
        $pvMax = $m[1] * 1000; // Fallback alte Logik
    }
}

// 2. 24h Mittelwerte berechnen (Cache 1 Std)
$avgs = get24hAverages($historyFile);

// 3. Strompreise & Prognose aus awattardebug.txt laden
$priceHistory = [];
$forecastData = [];
$priceStartHour = 0;
$priceInterval = 1.0;
$currentHour = (int)date('H');

// Intelligente Dateiauswahl & Merge (0.txt/12.txt + live debug.txt)
$baseFile = 'awattardebug.0.txt';
if ($currentHour >= 22 || $currentHour < 10) {
    $baseFile = 'awattardebug.12.txt';
}

$filesToRead = [];
$fBase = rtrim($paths['install_path'], '/') . '/' . $baseFile;
if (file_exists($fBase)) $filesToRead[] = $fBase;
elseif ($baseFile === 'awattardebug.12.txt') {
    $f0 = rtrim($paths['install_path'], '/') . '/awattardebug.0.txt';
    if (file_exists($f0)) $filesToRead[] = $f0;
}
$fLive = rtrim($paths['install_path'], '/') . '/awattardebug.txt';
if (file_exists($fLive)) $filesToRead[] = $fLive;

$chartDataMap = [];
$forecastDataMap = [];

foreach ($filesToRead as $file) {
    $readingData = false; $lastTime = -1; $dayOffset = 0;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $trimmed = trim($line);
        if ($trimmed === 'Data') { $readingData = true; $lastTime = -1; $dayOffset = 0; continue; }
        
        $cols = preg_split('/\s+/', $trimmed);
        if (count($cols) < 1 || !is_numeric($cols[0])) continue;

        $rawTime = (float)$cols[0];
        if ($lastTime !== -1 && $rawTime < $lastTime) { $dayOffset += 24; }
        $lastTime = $rawTime;
        $h = sprintf('%.2f', $rawTime + $dayOffset);

        if (!$readingData) {
            if (count($cols) >= 2 && is_numeric($cols[1])) $chartDataMap[$h] = (float)$cols[1];
        } else {
            if (count($cols) >= 5 && is_numeric($cols[4])) $forecastDataMap[$h] = (float)$cols[4] * $batteryCapacity * 40;
        }
    }
}

uksort($chartDataMap, function($a, $b) { return (float)$a <=> (float)$b; });
uksort($forecastDataMap, function($a, $b) { return (float)$a <=> (float)$b; });

foreach ($chartDataMap as $h => $val) { if (empty($priceHistory)) $priceStartHour = (float)$h; elseif (count($priceHistory) === 1) $priceInterval = max(0.25, (float)$h - $priceStartHour); $priceHistory[] = $val; }
foreach ($forecastDataMap as $h => $val) { $forecastData[] = ['h' => (float)$h, 'w' => $val]; }

?>