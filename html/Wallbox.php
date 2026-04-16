<?php
/* =====================================================================
   E3DC-Control - Wallbox.php (Hauptseite: Direkt + Automatik)
   ===================================================================== */

function applyAwattarPriceLogic($priceRaw, $awmwst, $awnebenkosten)
{
    $multiplier = ($awmwst / 100.0) + 1.0;
    
    // The price in e3dc.wallbox.out is the net price in €/MWh.
    // To get the gross price in ct/kWh, we must first divide by 10.
    return (($priceRaw / 10.0) * $multiplier) + $awnebenkosten;
}

$paths = getInstallPaths();
$install_user = $paths['install_user'];
$base_path = rtrim($paths['install_path'], '/') . '/';

$wallbox_file = null;
$fallback_wallbox_file = 'e3dc.wallbox.txt';
if (file_exists($base_path . 'e3dc.wallbox.txt')) {
    $wallbox_file = $base_path . 'e3dc.wallbox.txt';
} elseif (file_exists($fallback_wallbox_file)) {
    $wallbox_file = $fallback_wallbox_file;
} else {
    $wallbox_file = $base_path . 'e3dc.wallbox.txt';
}

$config_file = $base_path . 'e3dc.config.txt';

$message = '';
$alleZeilen = [];
$zeile = '1';

function parseWallboxConfigValues($filePath) {
    $result = [
        'wbhour' => '1',
        'wbvon' => '00:00',
        'wbbis' => '23:59'
    ];

    if (!is_file($filePath) || !is_readable($filePath)) {
        return $result;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $line) {
        if (preg_match('/^\s*([a-z0-9_]+)\s*=\s*(.*?)\s*$/i', $line, $m)) {
            $key = strtolower(trim($m[1]));
            $value = trim($m[2]);
            if (array_key_exists($key, $result)) {
                $result[$key] = $value;
            }
        }
    }

    return $result;
}

function upsertWallboxConfigValues($filePath, $updates) {
    $lines = is_file($filePath) ? file($filePath, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        return false;
    }

    $found = [];
    foreach ($updates as $key => $_) {
        $found[$key] = false;
    }

    $newLines = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*([a-z0-9_]+)\s*=\s*(.*?)\s*$/i', $line, $m)) {
            $existingKey = trim($m[1]);
            $existingLower = strtolower($existingKey);
            if (array_key_exists($existingLower, $updates)) {
                $newLines[] = $existingKey . ' = ' . $updates[$existingLower];
                $found[$existingLower] = true;
                continue;
            }
        }
        $newLines[] = $line;
    }

    $canonical = [
        'wbhour' => 'Wbhour',
        'wbvon' => 'Wbvon',
        'wbbis' => 'Wbbis'
    ];

    foreach ($updates as $key => $value) {
        if (!$found[$key]) {
            $newLines[] = $canonical[$key] . ' = ' . $value;
        }
    }

    return @file_put_contents($filePath, implode("\n", $newLines), LOCK_EX) !== false;
}

function parseTimeToMinutes($value) {
    $value = trim((string)$value);
    if (!preg_match('/^(\d{1,2})(?::(\d{1,2}))?$/', $value, $m)) {
        return false;
    }

    $hour = (int)$m[1];
    $minute = isset($m[2]) ? (int)$m[2] : 0;

    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        return false;
    }

    return $hour * 60 + $minute;
}

function normalizeTime($value) {
    $minutes = parseTimeToMinutes($value);
    if ($minutes === false) {
        return false;
    }

    $hour = floor($minutes / 60);
    $minute = $minutes % 60;
    return sprintf('%02d:%02d', $hour, $minute);
}

function normalizeFullHourTime($value) {
    $normalized = normalizeTime($value);
    if ($normalized === false) {
        return false;
    }

    if (substr($normalized, -2) !== '00') {
        return false;
    }

    return $normalized;
}

function normalizeHourInput($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return false;
    }

    if (preg_match('/^\d{1,2}$/', $value)) {
        $hour = (int)$value;
        if ($hour < 0 || $hour > 23) {
            return false;
        }
        return sprintf('%02d:00', $hour);
    }

    return normalizeFullHourTime($value);
}

function wallboxFileHasAutomaticEntries($filePath) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return false;
    }

    foreach ($lines as $line) {
        if (preg_match('/automatik/i', (string)$line)) {
            return true;
        }
    }

    return false;
}

function wallboxFileHasAutomaticEntriesFromPreviousDay($filePath) {
    if (!is_file($filePath) || !is_readable($filePath)) {
        return false;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return false;
    }

    $inAutomaticBlock = false;
    $todayLabel = date('j.n.');

    foreach ($lines as $lineRaw) {
        $line = trim((string)$lineRaw);
        if ($line === '') {
            continue;
        }

        if (preg_match('/automatik/i', $line)) {
            $inAutomaticBlock = true;
            continue;
        }

        if ($inAutomaticBlock && preg_match('/^am\s+(\d{1,2})\.(\d{1,2})\.?/iu', $line, $m)) {
            $label = ((int)$m[1]) . '.' . ((int)$m[2]) . '.';
            if ($label !== $todayLabel) {
                return true;
            }
        }
    }

    return false;
}

$wallboxConfig = parseWallboxConfigValues($config_file);

$confData = loadE3dcConfig();
$awmwst = isset($confData['config']['awmwst']) ? parseConfigFloat($confData['config']['awmwst']) : 19.0;
$awnebenkosten = isset($confData['config']['awnebenkosten']) ? parseConfigFloat($confData['config']['awnebenkosten']) : 0.0;

// Ladeplanung auslesen (e3dc.wallbox.out)
$plannedEntries = [];
$wallbox_out_file = $base_path . 'e3dc.wallbox.out';
$currentPlanHash = (file_exists($wallbox_out_file)) ? md5_file($wallbox_out_file) : '';

// Ladeleistungen für die Kostenvorschau aus der Konfiguration laden
$powerOptionsStr = '7.2, 11.0, 22.0';
if (file_exists($config_file)) {
    $cLines = file($config_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($cLines as $cLine) {
        if (preg_match('/^\s*wbcostpowers\s*=\s*(.*)/i', $cLine, $m)) {
            $powerOptionsStr = trim($m[1]);
            break;
        }
    }
}
$powerOptionsArr = array_map('trim', explode(',', $powerOptionsStr));

$powerOptions = [];
foreach ($powerOptionsArr as $p_str) {
    $p_float = (float)str_replace(',', '.', $p_str); // Komma-tolerant
    if ($p_float > 0) {
        $key = number_format($p_float, 1, '.', ''); // Schlüssel normalisieren (z.B. '11' -> '11.0')
        $powerOptions[$key] = $p_float;
    }
}

if (empty($powerOptions)) {
    $powerOptions = [
        '7.2' => 7.2,
        '11.0' => 11.0,
        '22.0' => 22.0,
    ];
}
$totalCosts = [];
$totalKwhs = [];
foreach ($powerOptions as $key => $_) {
    $totalCosts[$key] = 0;
    $totalKwhs[$key] = 0;
}
$chargingSlots = 0;

if (file_exists($wallbox_out_file) && is_readable($wallbox_out_file)) {
    $sourceTimestamp = filemtime($wallbox_out_file);
    $wbLines = file($wallbox_out_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($wbLines !== false) {
        foreach ($wbLines as $wbLine) {
            // Format: 11.25 1772104500 0 19.78
            $parts = preg_split('/\s+/', trim($wbLine));
            if (count($parts) >= 4) {
                $ts = (int)$parts[1];
                $mode = (int)$parts[2]; // 0 = manual, 1 = auto
                $pricePerMwh = (float)$parts[3];
                
                // Kostenberechnung
                $finalPriceCt = applyAwattarPriceLogic($pricePerMwh, $awmwst, $awnebenkosten);
                $finalPriceEuro = $finalPriceCt / 100.0; // von ct/kWh zu €/kWh

                $plannedEntries[] = [
                    'date' => date('j.n.', $ts),
                    'time' => date('H:i', $ts),
                    'source' => ($mode === 1) ? 'auto' : 'manual',
                    'price' => $finalPriceCt
                ];

                foreach ($powerOptions as $key => $pwr) {
                    $kwh_per_slot = $pwr * 0.25;
                    $totalKwhs[$key] += $kwh_per_slot;
                    $totalCosts[$key] += $kwh_per_slot * $finalPriceEuro;
                }
                
                $chargingSlots++;
            } elseif (count($parts) >= 3) {
                $ts = (int)$parts[1];
                $mode = (int)$parts[2];
                $plannedEntries[] = ['date' => date('j.n.', $ts), 'time' => date('H:i', $ts), 'source' => ($mode === 1) ? 'auto' : 'manual', 'price' => null];
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quickAction = null;
    if (isset($_POST['quick_action'])) {
        $quickAction = $_POST['quick_action'];
        if ($quickAction === 'start_now') {
            $_POST['zwei'] = '99';
        } elseif ($quickAction === 'clear_times') {
            $_POST['zwei'] = '0';
        }
    }

    if (isset($_POST['zwei'])) {
        $neueDauer = trim((string)$_POST['zwei']);
        $neueDauerInt = (int)$neueDauer;

        if (!is_numeric($neueDauer) || $neueDauerInt < 0 || ($neueDauerInt > 24 && $neueDauerInt < 99) || $neueDauerInt > 99) {
            $message = errorMessage('Ungültige Eingabe', 'Die Ladedauer muss zwischen 0 und 24 Stunden oder 99 = unbegrenzt liegen.');
        } else {
            $oldUmask = umask(0002);
            $writeResult = @file_put_contents($wallbox_file, $neueDauerInt . PHP_EOL, LOCK_EX);
            umask($oldUmask);

            if ($writeResult !== false) {
                $message = successMessage('✓ Wallbox-Ladedauer gespeichert.');
                usleep(500000);

                if ($quickAction === 'clear_times') {
                    $maxPolls = 6;
                    for ($poll = 0; $poll < $maxPolls; $poll++) {
                        if (is_file($wallbox_file) && is_readable($wallbox_file)) {
                            $tmpLines = file($wallbox_file, FILE_IGNORE_NEW_LINES);
                            if (is_array($tmpLines) && count($tmpLines) > 1) {
                                break;
                            }
                        }
                        usleep(350000);
                    }
                }
            } else {
                $message = errorMessage(
                    'Datei-Zugriff verweigert',
                    'Datei: <code>' . htmlspecialchars($wallbox_file) . '</code><br>' .
                    'Bitte Berechtigungen prüfen, z. B.:<br>' .
                    '<code>sudo chown ' . htmlspecialchars($install_user) . ':www-data ' . htmlspecialchars($wallbox_file) . '</code><br>' .
                    '<code>sudo chmod 664 ' . htmlspecialchars($wallbox_file) . '</code>'
                );
            }
        }
    }

    if (isset($_POST['save_auto_settings'])) {
        $previousConfig = parseWallboxConfigValues($config_file);
        $postedWbhour = trim((string)($_POST['Wbhour'] ?? ''));
        $postedWbvon = trim((string)($_POST['Wbvon'] ?? ''));
        $postedWbbis = trim((string)($_POST['Wbbis'] ?? ''));
        $adjustWbvon = isset($_POST['adjust_wbvon']) && $_POST['adjust_wbvon'] === '1';

        $wallboxConfig['wbhour'] = $postedWbhour;
        $wallboxConfig['wbvon'] = $postedWbvon;
        $wallboxConfig['wbbis'] = $postedWbbis;

        if (!is_numeric($postedWbhour) || (int)$postedWbhour < 0) {
            $message = errorMessage('Ungültige Eingabe', 'Wbhour muss ein numerischer Wert >= 0 sein.');
        } else {
            $wbvonNorm = normalizeHourInput($postedWbvon);
            $wbbisNorm = normalizeHourInput($postedWbbis);

            if ($wbvonNorm === false || $wbbisNorm === false) {
                $message = errorMessage('Ungültige Zeit', 'Wbvon und Wbbis müssen als ganze Stunden 0-23 eingegeben werden (z. B. 6 oder 22).');
            } else {
                $nowMinutes = (int)date('G') * 60 + (int)date('i');
                $wbvonMinutes = parseTimeToMinutes($wbvonNorm);

                // !!!!!!! HINWEIS: HIER IST DIE SPERRE ENTFERNT !!!!!!!
                // WIR LASSEN DIE EINGABE EINFACH DURCH, AUCH WENN Wbvon IN DER VERGANGENHEIT LIEGT
                
                if ($adjustWbvon && $nowMinutes > $wbvonMinutes) {
                    $nextHourTs = strtotime(date('Y-m-d H:00:00')) + 3600;
                    $wbvonNorm = date('H:00', $nextHourTs);
                }

                $updates = [
                    'wbhour' => (string)(int)$postedWbhour,
                    'wbvon' => $wbvonNorm,
                    'wbbis' => $wbbisNorm
                ];

                $previousWbvon = normalizeHourInput($previousConfig['wbvon']);
                $previousWbbis = normalizeHourInput($previousConfig['wbbis']);
                $isTimeChange = ($previousWbvon !== false && $previousWbbis !== false)
                    ? ($previousWbvon !== $wbvonNorm || $previousWbbis !== $wbbisNorm)
                    : true;

                $hasAutoEntries = wallboxFileHasAutomaticEntries($wallbox_file);
                $hasPreviousDayAutoEntries = wallboxFileHasAutomaticEntriesFromPreviousDay($wallbox_file);
                $newWbhourValue = (int)$postedWbhour;
                $needsSafetyReset = $newWbhourValue > 0 && ($hasPreviousDayAutoEntries || ($hasAutoEntries && $isTimeChange));
                $canWriteAutoSettings = true;

                if ($needsSafetyReset) {
                    $resetResult = upsertWallboxConfigValues($config_file, ['wbhour' => '0']);
                    if (!$resetResult) {
                        $message = errorMessage(
                            'Sicherheits-Reset fehlgeschlagen',
                            'Wbhour konnte nicht vorab auf 0 gesetzt werden. Bitte Dateiberechtigungen prüfen.'
                        );
                        $wallboxConfig = parseWallboxConfigValues($config_file);
                        $canWriteAutoSettings = false;
                    } else {
                        sleep(5);
                    }
                }

                if ($canWriteAutoSettings) {
                    $writeResult = upsertWallboxConfigValues($config_file, $updates);
                    if ($writeResult) {
                        if ($needsSafetyReset) {
                            $message = successMessage('✓ Automatik-Einstellungen gespeichert (Sicherheits-Reset 5s mit Wbhour=0 durchgeführt).');
                        } else {
                            $message = successMessage('✓ Automatik-Einstellungen gespeichert.');
                        }
                        $wallboxConfig = parseWallboxConfigValues($config_file);
                    } else {
                        $message = errorMessage(
                            'Schreibberechtigung fehlt',
                            'Datei: <code>' . htmlspecialchars($config_file) . '</code><br>' .
                            'Bitte Berechtigungen prüfen, z. B.:<br>' .
                            '<code>sudo chown ' . htmlspecialchars($install_user) . ':www-data ' . htmlspecialchars($config_file) . '</code><br>' .
                            '<code>sudo chmod 664 ' . htmlspecialchars($config_file) . '</code>'
                        );
                    }
                }
            }
        }
    }
}

if (file_exists($wallbox_file)) {
    $readCheck = checkFileAccess($wallbox_file, 'read');
    if ($readCheck === true) {
        $alleZeilen = file($wallbox_file, FILE_IGNORE_NEW_LINES);
    }
}

if (count($alleZeilen) > 0) {
    $zeile = $alleZeilen[0];
}

$formAction = getContextPageUrl('wallbox');
$nowMinutes = (int)date('G') * 60 + (int)date('i');
$currentWbvonMinutes = parseTimeToMinutes($wallboxConfig['wbvon']);
$showWbvonHint = $currentWbvonMinutes !== false && $nowMinutes > $currentWbvonMinutes;

$wbvonDisplayHour = ($currentWbvonMinutes !== false) ? (string)floor($currentWbvonMinutes / 60) : preg_replace('/[^0-9]/', '', (string)$wallboxConfig['wbvon']);
$wbbisMinutes = parseTimeToMinutes($wallboxConfig['wbbis']);
$wbbisDisplayHour = ($wbbisMinutes !== false) ? (string)floor($wbbisMinutes / 60) : preg_replace('/[^0-9]/', '', (string)$wallboxConfig['wbbis']);
?>

<div class="px-2 pb-5">
    <h5 class="fw-bold mb-3 text-body">Wallbox Steuerung</h5>
    
    <div id="wb-live-card" class="card shadow-sm mb-4" style="border-radius: 16px; display: none;">
        <div class="card-body p-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div id="wb-pulse" class="rounded-circle me-3" style="width: 12px; height: 12px; background: #a855f7;"></div>
                <div>
                    <h6 class="m-0 fw-bold">Wallbox aktiv</h6>
                    <small class="text-muted">Fahrzeug lädt aktuell</small>
                </div>
            </div>
            <div class="text-end">
                <span id="wb-live-power" class="h4 m-0 fw-bold" style="color: #a855f7;">0 W</span>
            </div>
        </div>
    </div>

    <style>
    /* Animation für den Ladepuls */
    @keyframes pulse-purple {
        0% { box-shadow: 0 0 0 0 rgba(168, 85, 247, 0.7); }
        70% { box-shadow: 0 0 0 10px rgba(168, 85, 247, 0); }
        100% { box-shadow: 0 0 0 0 rgba(168, 85, 247, 0); }
    }
    .active-pulse { animation: pulse-purple 2s infinite; }
    </style>

    <?php if (!empty($message)): ?>
        <div class="mb-3"><?= $message ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4" style="border-radius: 16px;">
        <div class="card-body p-3">
            <h6 class="card-title text-info fw-bold mb-3"><i class="fas fa-calendar-alt me-2"></i>Ladeplanung</h6>
            
            <style>
                .slot-active-auto { background-color: #0dcaf0 !important; opacity: 0.8; }
                .slot-active-manual { background-color: #ffc107 !important; opacity: 0.8; }
            </style>
            <div class="wb-timeline-container" style="background: var(--bs-tertiary-bg); border-radius: 12px; padding: 15px; border: 1px solid var(--bs-border-color);">
                <?php
                // Zeit-Berechnung für rollierende Ansicht (Now zentriert)
                $tsNow = time();
                // Auf 15 Min runden für das Raster
                $tsNowAligned = floor($tsNow / 900) * 900; 
                // Startzeit: 12h vor "Jetzt"
                $tsStart = $tsNowAligned - (12 * 3600);
                
                // Labels generieren (-12h, -6h, Now, +6h, +12h)
                $tlLabels = [];
                for ($k=0; $k<=4; $k++) {
                    $tlLabels[] = date('H:i', $tsStart + ($k * 6 * 3600));
                }
                ?>
                <div class="timeline-labels" style="display: flex; justify-content: space-between; margin-bottom: 5px; color: var(--bs-secondary-color); font-size: 0.7rem; font-weight: bold;">
                    <span style="width: 30px; text-align: left;"><?= $tlLabels[0] ?></span>
                    <span><?= $tlLabels[1] ?></span>
                    <span style="color: #ff4757;"><?= $tlLabels[2] ?></span>
                    <span><?= $tlLabels[3] ?></span>
                    <span style="width: 30px; text-align: right;"><?= $tlLabels[4] ?></span>
                </div>
                
                <div class="wb-timeline-track" style="height: 35px; background: var(--bs-body-bg); border-radius: 8px; position: relative; overflow: hidden; display: flex; border: 1px solid var(--bs-border-color);">
                    <?php 
                    // 96 Slots à 15 Minuten (24h Fenster)
                    for ($i = 0; $i < 96; $i++) {
                        $slotTs = $tsStart + ($i * 900);
                        $slotDate = date('j.n.', $slotTs);
                        $slotTime = date('H:i', $slotTs);
                        
                        $sourceClass = '';
                        $slotPrice = null;
                        if (isset($plannedEntries) && is_array($plannedEntries)) {
                            foreach ($plannedEntries as $entry) {
                                if ($entry['date'] === $slotDate && $entry['time'] === $slotTime) {
                                    $sourceClass = 'slot-active-' . $entry['source'];
                                    $slotPrice = $entry['price'] ?? null;
                                    break;
                                }
                            }
                        }
                        
                        // Tooltip erstellen
                        $tooltip = $slotTime . ' Uhr';
                        if ($slotPrice !== null) {
                            $tooltip .= ' | ' . number_format($slotPrice, 2, ',', '.') . ' ct/kWh';
                        }

                        // Tageswechsel markieren (wenn 00:00 Uhr)
                        $borderStyle = "border-right: 1px solid rgba(45, 55, 72, 0.3);";
                        if ($slotTime === '00:00') {
                            $borderStyle = "border-left: 1px dashed #666; border-right: 1px solid rgba(45, 55, 72, 0.3);";
                        }
                        
                        echo '<div class="timeline-slot '.$sourceClass.'" style="flex: 1; height: 100%; '.$borderStyle.'" data-bs-toggle="tooltip" data-bs-placement="top" title="'.$tooltip.'" tabindex="0"></div>';
                    }
                    ?>
                    <!-- Marker fix in der Mitte (50%) -->
                    <div class="timeline-now-marker" style="position: absolute; top: 0; bottom: 0; width: 2px; background: #ff4757; z-index: 10; left: 50%; box-shadow: 0 0 8px rgba(255, 71, 87, 0.6);"></div>
                </div>

                <div class="d-flex justify-content-center gap-3 mt-3" style="font-size: 0.75rem;">
                    <span><i class="fas fa-square text-info"></i> Automatik</span>
                    <span><i class="fas fa-square text-warning"></i> Direkt</span>
                    <span><i class="fas fa-minus" style="color: #ff4757;"></i> Jetzt</span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($chargingSlots > 0): ?>
    <div class="card shadow-sm mb-4" style="border-radius: 16px;">
        <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="card-title text-success fw-bold m-0"><i class="fas fa-euro-sign me-2"></i>Geschätzte Ladekosten</h6>
                <div class="btn-group btn-group-sm" role="group" id="cost-power-selector">
                    <?php foreach ($powerOptions as $key => $pwr): ?>
                        <button type="button" class="btn btn-outline-info" data-power-key="<?= $key ?>"><?= number_format($pwr, 1, ',', '.') ?> kW</button>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="small text-muted mb-3">Basierend auf <?= $chargingSlots ?> geplanten Ladefenstern (je 15 Min.).</p>

            <div id="cost-details-container">
                <?php foreach ($powerOptions as $key => $pwr): ?>
                    <div class="list-group-item bg-transparent justify-content-between align-items-center px-0 cost-detail" id="cost-detail-<?= str_replace('.', '_', $key) ?>" style="display: none;">
                        <div>
                            <strong class="text-body">Bei <?= number_format($pwr, 1, ',', '.') ?> kW Ladeleistung</strong>
                            <small class="d-block text-muted"><?= number_format($totalKwhs[$key], 2, ',', '.') ?> kWh geladen</small>
                        </div>
                        <span class="badge bg-success rounded-pill fs-6"><?= number_format($totalCosts[$key], 2, ',', '.') ?> €</span>
                    </div>
                    <?php $isFirst = false; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4" style="border-radius: 16px;">
        <div class="card-body p-3">
            <h6 class="card-title text-warning fw-bold mb-3"><i class="fas fa-bolt me-2"></i>Direktsteuerung (Sofort)</h6>
            
            <form action="<?= htmlspecialchars($formAction) ?>" method="post">
                <div class="mb-3">
                    <label class="form-label text-muted small fw-bold mb-1">Ladedauer (Stunden)</label>
                    <input type="number" name="zwei" class="form-control rounded-pill" 
                        value="<?= htmlspecialchars($zeile) ?>" min="0" max="99">
                    <div class="form-text text-muted small">99 = Dauerhaft, 0 = Stop/Löschen</div>
                </div>
                
                <div class="row g-2 mb-3">
                    <div class="col-4">
                        <button type="submit" name="quick_action" value="clear_times" class="btn btn-outline-danger w-100 btn-sm py-2 border-2 fw-bold rounded-pill">
                            <i class="fas fa-stop"></i> Stop (0)
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="submit" name="zwei" value="3" class="btn btn-outline-primary w-100 btn-sm py-2 border-2 fw-bold rounded-pill">
                            + 3 Std.
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="submit" name="quick_action" value="start_now" class="btn btn-outline-warning w-100 btn-sm py-2 border-2 fw-bold rounded-pill">
                            <i class="fas fa-bolt"></i> Max (99)
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-outline-warning w-100 rounded-pill fw-bold border-2">
                    ✓ Ladedauer speichern
                </button>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4" style="border-radius: 16px;">
        <div class="card-body p-3">
            <h6 class="card-title text-info fw-bold mb-3"><i class="fas fa-clock me-2"></i>Automatik Steuerung</h6>

            <form action="<?= htmlspecialchars($formAction) ?>" method="post">
                <div class="mb-3">
                    <label for="Wbhour" class="form-label text-muted small fw-bold mb-1">Ladedauer (Wbhour)</label>
                    <input type="number" id="Wbhour" name="Wbhour" class="form-control rounded-pill"
                           value="<?= htmlspecialchars($wallboxConfig['wbhour']) ?>" min="0" step="1">
                </div>

                <div class="mb-3">
                    <label for="Wbvon" class="form-label text-muted small fw-bold mb-1">Startzeit (Wbvon)</label>
                    <input type="number" id="Wbvon" name="Wbvon" class="form-control rounded-pill <?= $showWbvonHint ? 'border-warning' : '' ?>"
                           value="<?= htmlspecialchars($wbvonDisplayHour) ?>" min="0" max="23" step="1">
                    <?php if ($showWbvonHint): ?>
                        <div class="text-warning small mt-1"><i class="fas fa-exclamation-triangle"></i> Wbvon (<?= htmlspecialchars($wallboxConfig['wbvon']) ?>) liegt in der Vergangenheit.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="Wbbis" class="form-label text-muted small fw-bold mb-1">Endzeit (Wbbis)</label>
                    <input type="number" id="Wbbis" name="Wbbis" class="form-control rounded-pill"
                           value="<?= htmlspecialchars($wbbisDisplayHour) ?>" min="0" max="23" step="1">
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" name="adjust_wbvon" value="1" id="adjustCheck">
                    <label class="form-check-label text-muted small fw-bold" for="adjustCheck">
                        Wbvon bei Speicherung auf nächste volle Stunde setzen, falls Zeit überschritten
                    </label>
                </div>

                <button type="submit" name="save_auto_settings" value="1" class="btn btn-outline-success w-100 rounded-pill fw-bold border-2">
                    ✓ Automatik speichern
                </button>
            </form>
        </div>
    </div>

</div>
<script>
var initialPlanHash = "<?= $currentPlanHash ?>";
function updateWallboxLiveStatus() {
    fetch('get_live_json.php')
        .then(response => response.json())
        .then(data => {
            // Prüfen ob sich der Ladeplan geändert hat
            if (data.wb_plan_hash && initialPlanHash && data.wb_plan_hash !== initialPlanHash) {
                location.reload();
                return;
            }

            const statusCard = document.getElementById('wb-live-status');
            const valDisplay = document.getElementById('live-wb-val');
            const pulse = document.getElementById('status-pulse');
            
            const wbPower = parseFloat(data.wb) || 0;

            if (wbPower > 10) { // Schwelle von 10W um Rauschen zu vermeiden
                statusCard.style.display = 'block';
                pulse.classList.add('pulse-active');
                
                // Formatierung der Watt-Zahl
                if (wbPower >= 1000) {
                    valDisplay.innerText = (wbPower / 1000).toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' kW';
                } else {
                    valDisplay.innerText = Math.round(wbPower) + ' W';
                }
            } else {
                statusCard.style.display = 'none';
                pulse.classList.remove('pulse-active');
            }
        })
        .catch(err => console.error('Fehler beim Abruf des Wallbox-Status:', err));
}

// Alle 4 Sekunden aktualisieren (analog zum Mobile-Dashboard)
setInterval(updateWallboxLiveStatus, 4000);
updateWallboxLiveStatus(); // Erstaufruf

// NEU: Skript für die Auswahl der Ladekosten-Anzeige
(function() {
    const selector = document.getElementById('cost-power-selector');
    if (!selector) return;

    const buttons = selector.querySelectorAll('button');
    const details = document.querySelectorAll('.cost-detail');

    function showCost(powerKey) {
            // Speichere die Auswahl im Local Storage
            try {
                localStorage.setItem('lastSelectedWallboxPower', powerKey);
            } catch (e) {
                console.warn("Could not save to localStorage", e);
            }
        buttons.forEach(btn => {
            if (btn.dataset.powerKey === powerKey) {
                btn.classList.remove('btn-outline-info');
                btn.classList.add('btn-info');
            } else {
                btn.classList.remove('btn-info');
                btn.classList.add('btn-outline-info');
            }
        });

        details.forEach(detail => {
            const detailId = 'cost-detail-' + powerKey.replace('.', '_');
                // Wichtig: 'flex' beibehalten, da es vom vorherigen Fix kommt
                detail.style.display = (detail.id === detailId) ? 'flex' : 'none';
        });
    }

    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            showCost(this.dataset.powerKey);
        });
    });

    // Tooltips initialisieren
    window.addEventListener('DOMContentLoaded', function() {
        if (typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })

            // Lade die letzte Auswahl oder setze den Standard auf 11.0 kW
            let lastPower = '11.0';
            try {
                lastPower = localStorage.getItem('lastSelectedWallboxPower') || '11.0';
            } catch (e) {
                // localStorage might be disabled
            }
            showCost(lastPower);
        }
    });
})();
</script>