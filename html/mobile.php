<?php
if (!defined('VIEW_MODE')) {
    define('VIEW_MODE', 'mobile');
}
require_once 'helpers.php';
handleVersionCheck(__FILE__);
handleUpdatePreparation();
handleUpdateCheck();
handleServiceRestart();
handleWatchdogStatus();
handleWatchdogLog();
handleSaveSetting();
handleStatus();
handleRunNow();
handleRunLiveHistory();
handleRunUpdate();
handleArchivDiagram();

$stampFile = '/var/www/html/tmp/plot_soc_done_mobile';
$lastUpdateTs = file_exists($stampFile) ? filemtime($stampFile) : 0;

// Logik einbinden (Config, Forecast, Preise)
require_once 'logic.php';

// FIX: Wandernder Preis-Graph
// Wir versuchen, die statische awattardebug.23.txt zu laden, damit der Graph nicht immer bei "jetzt" beginnt.
// Wir übernehmen die MwSt ($awmwst oder $mwst) aus der logic.php, falls vorhanden.
$vatToUse = isset($awmwst) ? $awmwst : (isset($mwst) ? $mwst : 0);
$staticData = loadStaticPriceData($vatToUse);
$useStaticData = false;
if ($staticData) {
    $priceHistory = $staticData['prices'];
    $priceStartHour = $staticData['start_hour'];
    $priceInterval = $staticData['interval'];
    $useStaticData = true;
    echo "<!-- Static Data Loaded from: " . htmlspecialchars($staticData['source']) . " -->";
}

$seite = $_GET['seite'] ?? 'live';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E3DC Mobile Pro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="manifest" href="manifest_mobile.json">
    <style>
        :root {
            --bg-body: #0b0e14; --text-body: #f8fafc;
            --bg-card: #1a1f29; --border-card: #2d3748;
            --bg-nav: #1a1f29; --text-muted: #94a3b8;
            --chart-line: rgba(255,255,255,0.5);
            --chart-overlay: rgba(0,0,0,0.2);
        }
        [data-theme="light"] {
            --bg-body: #f1f5f9; --text-body: #0f172a;
            --bg-card: #ffffff; --border-card: #e2e8f0;
            --bg-nav: #ffffff; --text-muted: #64748b;
            --chart-line: rgba(0,0,0,0.3);
            --chart-overlay: rgba(0,0,0,0.05);
        }
        body { background-color: var(--bg-body); color: var(--text-body); font-family: -apple-system, sans-serif; transition: background-color 0.3s, color 0.3s; }
        /* Globaler Hover-Effekt für Desktop-Nutzer (Finger-Cursor) */
        button, .nav-item, .btn, [onclick], .fill-bar { cursor: pointer; }
        .dashboard-card { background: var(--bg-card); border: 1px solid var(--border-card); border-radius: 20px; padding: 18px; position: relative; overflow: hidden; height: 100%; transition: background 0.3s, border-color 0.3s; }
        .fill-bar { position: absolute; top: 0; left: 0; height: 100%; transition: width 1.5s ease-in-out, background 0.5s; z-index: 1; opacity: 0.18; }
        .card-content { position: relative; z-index: 2; text-align: center; }
        .label { font-size: 0.7rem; color: var(--text-muted); font-weight: 600; letter-spacing: 0.05em; margin-bottom: 4px; }
        .value { font-size: 1.8rem; font-weight: 900; line-height: 1.2; }
        .unit { font-size: 0.8rem; color: var(--text-muted); font-weight: bold; }
        .mobile-nav { display: flex; justify-content: space-around; background: var(--bg-nav); border: 1px solid var(--border-card); border-radius: 20px; padding: 10px; margin-bottom: 20px; transition: background 0.3s; }
        .nav-item { color: var(--text-muted); text-decoration: none; font-size: 0.75rem; text-align: center; flex: 1; }
        .nav-item i { display: block; font-size: 1.1rem; margin-bottom: 2px; }
        .nav-item.active { color: #3b82f6; font-weight: bold; }
        .pv-val { color: #fbbf24; } .home-val { color: #3b82f6; } .wb-val { color: #a855f7; } .wp-val { color: #22d3ee; }
        @keyframes pulse-dynamic { 0% { opacity: 0.18; } 50% { opacity: var(--pulse-intensity, 0.4); } 100% { opacity: 0.18; } }
        .pulse-active { animation: pulse-dynamic var(--pulse-speed, 2s) infinite ease-in-out; }
        .price-ultra-cheap { text-shadow: 0 0 10px rgba(16, 185, 129, 0.8); }
        #price-chart { position: absolute; bottom: 0; left: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; color: var(--text-muted); }
        #price-line { position: absolute; top: 0; bottom: 0; width: 1px; border-left: 1px dashed var(--chart-line); z-index: 2; pointer-events: none; display: none; }
        #price-line-day { position: absolute; top: 0; bottom: 0; width: 1px; border-left: 1px dotted rgba(255,255,255,0.3); z-index: 1; pointer-events: none; display: none; }
        #price-line-yesterday { position: absolute; top: 0; bottom: 0; width: 1px; border-left: 1px dotted rgba(255,255,255,0.3); z-index: 1; pointer-events: none; display: none; }
        #price-overlay-tomorrow { position: absolute; top: 0; bottom: 0; right: 0; background: var(--chart-overlay); z-index: 0; pointer-events: none; display: none; }
        #price-label-tomorrow { position: absolute; top: 5px; right: 5px; color: rgba(255,255,255,0.3); font-size: 0.7rem; font-weight: bold; display: none; pointer-events: none; }
        #price-label-yesterday { position: absolute; top: 5px; left: 5px; color: rgba(255,255,255,0.3); font-size: 0.7rem; font-weight: bold; display: none; pointer-events: none; }
        #price-time-label { position: absolute; bottom: 4px; transform: translateX(-50%); color: white; font-size: 10px; font-weight: bold; z-index: 3; pointer-events: none; opacity: 0.9; white-space: nowrap; display: none; text-shadow: 1px 1px 2px black; }
        #price-val-min { position: absolute; top: 12px; left: 15px; z-index: 5; font-size: 1.1rem; font-weight: bold; text-align: left; line-height: 1.1; pointer-events: none; }
        #price-val-max { position: absolute; top: 12px; right: 15px; z-index: 5; font-size: 1.1rem; font-weight: bold; text-align: right; line-height: 1.1; pointer-events: none; }
        @keyframes blinker { 50% { opacity: 0.2; } }
        .blink-extreme { animation: blinker 0.8s linear infinite; }
        #val-pv-forecast .unit { color: inherit; }

        /* Desktop-spezifische Layout-Korrekturen */
        .mode-desktop .mobile-nav { display: none; }
        .mode-desktop .dashboard-card { margin-bottom: 20px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3); }
        .mode-desktop #diagramContainer { display: block !important; }
        .mode-desktop #toggleDiagramBtn { display: none; }
        /* Korrektur für den Status-Kreis im Stop-Feld auf Desktop */
        .mode-desktop .status-kreis { float: left; margin-right: 15px; position: static; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body class="mode-<?php echo VIEW_MODE; ?>" data-theme="<?= $darkMode ? 'dark' : 'light' ?>" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<div class="container py-3">
    <div class="mobile-nav">
        <a href="mobile.php" class="nav-item <?= $seite=='live'?'active':'' ?>"><i class="fas fa-bolt"></i>Live</a>
        <a href="mobile.php?seite=forecast" class="nav-item <?= $seite=='forecast'?'active':'' ?>"><i class="fas fa-chart-area"></i>Prognose</a>
        <a href="mobile.php?seite=wallbox" class="nav-item <?= $seite=='wallbox'?'active':'' ?>"><i class="fas fa-charging-station"></i>Wallbox</a>
        <a href="mobile.php?seite=config" class="nav-item <?= $seite=='config'?'active':'' ?>">
            <div class="d-inline-block position-relative">
                <i class="fas fa-cog"></i>
                <span id="update-badge-nav" class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="display:<?= getUpdateStatusFromCache() > 0 ? 'inline-block' : 'none' ?>;"></span>
            </div>
            <div>Config</div>
        </a>
        <a href="mobile.php?seite=history" class="nav-item <?= $seite=='history'?'active':'' ?>"><i class="fas fa-chart-line"></i>Historie</a>
        <a href="mobile.php?seite=archiv" class="nav-item <?= $seite=='archiv'?'active':'' ?>"><i class="fas fa-history"></i>Archiv</a>
    </div>

    <?php if ($seite == 'live'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h5 class="m-0 fw-bold">E3DC Status</h5>
            <div class="d-flex align-items-center gap-3">
                <i id="watchdog-icon" class="fas fa-shield-alt text-secondary" style="display:none; font-size: 1.1rem; cursor:pointer;" title="Watchdog" onclick="showWatchdogLog()"></i>
                <?= renderConnectionBadge() ?>
                <i class="fas fa-<?= $darkMode ? 'moon' : 'sun' ?> text-secondary" style="cursor:pointer;" onclick="toggleDarkMode(this)"></i>
                <span class="badge border border-secondary text-info" id="live-time" style="background: var(--bg-card);">--:--:--</span>
            </div>
        </div>

        <div class="dashboard-card mb-2" style="padding-top: 12px; padding-bottom: 12px;" onclick="toggleDiagram('pv')">
            <div id="fill-pv" class="fill-bar" style="width: 0%; background: #fbbf24;"></div>
            <div class="card-content">
                <div class="label d-flex justify-content-center align-items-center gap-2">
                    Photovoltaik (<?= round($pvMax/1000,1) ?> kWp)
                    <i class="fas fa-eye<?= $showForecast ? '' : '-slash' ?> text-secondary" style="cursor:pointer; font-size:0.8em;" onclick="event.stopPropagation(); toggleForecast(this)" id="btn-toggle-forecast"></i>
                </div>
                <div class="value pv-val" id="val-pv">0</div>
                <div id="val-pv-forecast" style="font-size: 0.9rem; font-weight: bold; margin-top: 6px; margin-bottom: 6px; display:none; color: #fbbf24;"></div>
            </div>
        </div>

        <div class="dashboard-card mb-2" onclick="toggleDiagram('bat')">
            <div id="fill-bat" class="fill-bar" style="width: 0%; background: #10b981;"></div>
            <div class="card-content">
                <div class="label" id="label-soc">Batterie</div>
                <div class="value" id="val-bat">0</div>
            </div>
        </div>

        <div class="row g-2 mb-2">
            <div class="col-6">
                <div class="dashboard-card">
                    <div id="fill-home" class="fill-bar" style="width: 0%; background: #3b82f6;"></div>
                    <div class="card-content">
                        <div class="label">Haus</div>
                        <div class="value home-val" id="val-home">0</div>
                    </div>
                </div>
            </div>
            <div class="col-6" onclick="toggleDiagram('grid')">
                <div class="dashboard-card">
                    <div id="fill-grid" class="fill-bar" style="width: 0%; background: #f43f5e;"></div>
                    <div class="card-content">
                        <div class="label">Netz</div>
                        <div class="value" id="val-grid">0</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-2 mb-2" id="wb-wp-row">
            <div id="card-wb" class="col-12" style="display:none;" onclick="toggleDiagram('wb')">
                <div class="dashboard-card">
                    <div id="fill-wb" class="fill-bar" style="width: 0%; background: #a855f7;"></div>
                    <div class="card-content"><div class="label">Wallbox</div><div class="value wb-val" id="val-wb">0</div></div>
                </div>
            </div>
            <div id="card-wp" class="col-12" style="display:none;" onclick="<?= $luxtronikEnabled ? "window.location.href='mobile.php?seite=luxtronik'" : "toggleDiagram('normal')" ?>">
                <div class="dashboard-card">
                    <div id="fill-wp" class="fill-bar" style="width: 0%; background: #22d3ee;"></div>
                    <div class="card-content"><div class="label">Wärmepumpe</div><div class="value wp-val" id="val-wp">0</div></div>
                </div>
            </div>
        </div>

        <div class="dashboard-card mb-2" id="card-price">
            <svg id="price-chart" preserveAspectRatio="none" viewBox="0 0 240 100"></svg>
            <div id="price-line"></div>
            <div id="price-line-day"></div>
            <div id="price-line-yesterday"></div>
            <div id="price-overlay-tomorrow"></div>
            <div id="price-label-tomorrow">Morgen</div>
            <div id="price-label-yesterday">Gestern</div>
            <div id="price-time-label"></div>
            <div id="price-val-min"></div>
            <div id="price-val-max"></div>
            <div class="card-content">
                <div class="label">aktueller Strompreis</div>
                <div class="value" id="val-price">--<span class="unit"> ct/kWh</span></div>
            </div>
        </div>

        <div id="diagramContainer" style="display:none;" class="mb-3">
            <div id="diagramControls" class="d-flex justify-content-between align-items-center p-2 flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group" id="liveTimeFilter">
                    <button type="button" class="btn btn-outline-info active" onclick="setLiveHours(6, this)">6h</button>
                    <button type="button" class="btn btn-outline-info" onclick="setLiveHours(12, this)">12h</button>
                    <button type="button" class="btn btn-outline-info" onclick="setLiveHours(24, this)">24h</button>
                    <button type="button" class="btn btn-outline-info" onclick="setLiveHours(48, this)">48h</button>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span id="diagramStatus" class="small text-info"><?= $lastUpdateTs ? date('H:i', $lastUpdateTs) : '' ?></span>
                    <button id="diagramUpdateBtn" class="btn btn-sm btn-outline-light" onclick="updateDiagram()">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            </div>
            <div id="diagramDetails" class="text-center mb-2 small text-info fw-bold" style="display:none;"></div>
            <div style="height: 50vh; min-height: 400px; border-radius: 20px; overflow: hidden; border: 1px solid var(--border-card);">
                <iframe id="diagramFrame" src="" style="width: 100%; height: 100%; border: none;" scrolling="no"></iframe>
            </div>
        </div>

    <?php elseif ($seite == 'forecast'): ?>
        <div class="d-flex justify-content-between align-items-center mb-3 px-2">
            <h5 class="m-0 fw-bold">SoC Prognose</h5>
            <button id="forecastUpdateBtn" class="btn btn-sm btn-outline-secondary" onclick="updateForecast()">
                <i class="fas fa-sync-alt"></i> Update
            </button>
        </div>
        <div class="dashboard-card" style="height: calc(100vh - 180px); display: flex; flex-direction: column;">
            <div style="flex: 1; border-radius: 20px; overflow: hidden;">
                <iframe id="forecastFrame" src="diagramm_mobile.html?t=<?= time() ?>" style="width: 100%; height: 100%; border: none;" scrolling="no"></iframe>
            </div>
            <div class="text-center mt-2 small text-muted" id="forecastStatus"></div>
        </div>

    <?php elseif ($seite == 'wallbox'): include 'Wallbox.php';
          elseif ($seite == 'config'): ?>
        <div class="mb-3">
            <button id="btn-system-update" class="btn btn-outline-warning w-100 py-3 rounded-4 border-secondary fw-bold shadow-sm position-relative" onclick="startSystemUpdate()">
                <i class="fas fa-cloud-download-alt me-2"></i>E3DC-Control Update
                <span id="update-badge-btn" class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill" style="display:<?= getUpdateStatusFromCache() > 0 ? 'inline-block' : 'none' ?>;">!</span>
            </button>
            <button class="btn btn-outline-danger w-100 py-3 rounded-4 border-secondary fw-bold shadow-sm mt-3" onclick="restartService()">

                <i class="fas fa-power-off me-2"></i>E3DC-Control Neustart
            </button>
        </div>
        <?php include 'config_editor.php'; ?>
    <?php elseif ($seite == 'luxtronik' && $luxtronikEnabled): ?>
        <div class="mb-3">
            <a href="mobile.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Zurück</a>
        </div>
        <?php include 'luxtronik.php'; ?>
    <?php elseif ($seite == 'history'): include 'history.php';
          elseif ($seite == 'archiv'): include 'archiv.php';
    endif; ?>
</div>

<?= renderUpdateModal('modal-dialog-scrollable modal-fullscreen-sm-down') ?>
<?= renderWatchdogModal('modal-dialog-scrollable modal-fullscreen-sm-down') ?>
<?= renderChangelogModal('modal-dialog-scrollable modal-fullscreen-sm-down') ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="solar.js?v=<?= file_exists('solar.js') ? filemtime('solar.js') : time() ?>"></script>
<script>
const PV_MAX = <?= $pvMax ?>; const WP_MAX = <?= $wpMax ?>; const BAT_MAX = <?= $maxBatPower ?>; const BAT_CAPACITY = <?= $batteryCapacity ?>; const AVGS = <?= json_encode($avgs) ?>;
const PRICE_HISTORY = <?= json_encode($priceHistory) ?>;
let FORECAST_DATA = <?= json_encode($forecastData) ?>;
const LAT = <?= json_encode($lat) ?>;
const LON = <?= json_encode($lon) ?>;
const PV_STRINGS = <?= json_encode($pvStrings) ?>;
const PV_ATMOSPHERE = <?= json_encode($pvAtmosphere) ?>;
let DARK_MODE = <?= $darkMode ? 'true' : 'false' ?>;
let SHOW_FORECAST = <?= $showForecast ? 'true' : 'false' ?>;
const PRICE_START_HOUR = <?= $priceStartHour ?>;
const PRICE_INTERVAL = <?= $priceInterval ?>;
const USE_STATIC_CHART = <?= $useStaticData ? 'true' : 'false' ?>;
let lastUpdateTs = <?= $lastUpdateTs ?>;
let priceTendencyHtml = '';
let CURRENT_VIEW = 'normal';
let statusCheckInterval = null;
let currentLiveHours = 6;

function formatWatts(w) {
    if (Math.abs(w) >= 4000) return (w/1000).toLocaleString('de-DE',{minimumFractionDigits:2, maximumFractionDigits:2})+'<span class="unit"> kW</span>';
    return Math.round(w).toLocaleString('de-DE')+'<span class="unit"> W</span>';
}

function toggleForecast(el) {
    SHOW_FORECAST = !SHOW_FORECAST;
    // Icon umschalten
    if (SHOW_FORECAST) el.classList.replace('fa-eye-slash', 'fa-eye');
    else el.classList.replace('fa-eye', 'fa-eye-slash');
    
    // Speichern
    fetch('mobile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=save_setting&key=show_forecast&value=' + (SHOW_FORECAST ? '1' : '0')
    });
    updateDashboard();
}

function toggleDarkMode(el) {
    DARK_MODE = !DARK_MODE;
    document.body.setAttribute('data-theme', DARK_MODE ? 'dark' : 'light');
    document.body.setAttribute('data-bs-theme', DARK_MODE ? 'dark' : 'light');
    el.className = DARK_MODE ? 'fas fa-moon text-secondary' : 'fas fa-sun text-secondary';
    
    // Speichern
    fetch('mobile.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=save_setting&key=darkmode&value=' + (DARK_MODE ? '1' : '0')
    });

    // Refresh currently visible diagram to apply the new theme
    const currentPage = '<?= $seite ?>';
    // WICHTIG: Auch wenn der Container gerade ausgeblendet ist, wollen wir beim nächsten Öffnen
    // das richtige Theme. Aber um Serverlast zu sparen, aktualisieren wir nur, wenn sichtbar
    // ODER wir setzen ein Flag, dass ein Update nötig ist.
    if (currentPage === 'live') {
        updateDiagram();
    } else if (currentPage === 'forecast') {
        updateForecast();
    } else if (currentPage === 'history' && typeof window.triggerHistoryUpdate === 'function') {
        // history.php is included, its function should be available
        window.triggerHistoryUpdate();
    } else if (currentPage === 'archiv') {
        const frame = document.getElementById('archivFrame');
        if (frame && frame.src) {
            // Rebuild URL to include new theme and bust cache
            let url = new URL(frame.src, window.location.origin);
            url.searchParams.set('dark', DARK_MODE ? '1' : '0');
            url.searchParams.set('ts', Date.now());
            frame.src = url.href;
        }
    }
}

function updateDashboard() {
    if (document.getElementById('val-pv') == null) return;
    fetch('get_live_json.php').then(r => r.json()).then(data => {
        const timeElem = document.getElementById('live-time');
        timeElem.innerText = data.time;
        
        const now = Math.floor(Date.now() / 1000);
        const dataTs = data.ts || 0;
        const age = now - dataTs;

        // PV Logik & Farben
        
        // Connection Badge Update
        const statusBadge = document.getElementById('connection-status');
        if (statusBadge) {
            if (age > 300) {
                statusBadge.className = 'badge rounded-pill bg-warning text-dark';
                statusBadge.innerText = 'Veraltet';
            } else {
                statusBadge.className = 'badge rounded-pill bg-success text-white';
                statusBadge.innerText = 'Online';
            }
        }

        let pv = data.pv || 0;
        let pvFill = document.getElementById('fill-pv');
        document.getElementById('val-pv').innerHTML = formatWatts(pv);
        pvFill.style.width = Math.min(100, (pv / PV_MAX) * 100) + '%';
        if (pv > 14000) pvFill.style.background = '#991b1b'; // Dunkelrot
        else if (pv > 12000) pvFill.style.background = '#ef4444'; // Rot
        else if (pv > 10000) pvFill.style.background = '#f97316'; // Orange
        else pvFill.style.background = '#fbbf24'; // Gelb

        // Prognose-Daten aktualisieren
        if (data.forecast && data.forecast.length > 0) {
            FORECAST_DATA = data.forecast;
        }

        // Prognose Anzeige
        if (typeof FORECAST_DATA !== 'undefined' && FORECAST_DATA.length > 0) {
            const now = new Date();
            const curGmt = now.getUTCHours() + (now.getUTCMinutes() / 60);
            let best = null; let minDiff = 100;
            for (let d of FORECAST_DATA) {
                let diff = Math.abs(d.h - curGmt);
                if (diff < minDiff) { minDiff = diff; best = d; }
            }
            const fElem = document.getElementById('val-pv-forecast');
            if (best && minDiff < 0.5 && fElem && SHOW_FORECAST) {
                let fVal = best.w;
                let sollVal = (typeof getTheoreticalPower === 'function') ? getTheoreticalPower() : 0;

                if (fVal < 10 && sollVal < 10) {
                    fElem.style.display = 'none';
                } else {
                    let ratio = (fVal > 0) ? (pv / fVal) : 0;
                    let pctStr = (fVal > 0) ? Math.round(ratio * 100) + '%' : '-';
                    let sollStr = (sollVal > 0) ? ` | <span style="color:var(--text-body)">Soll: ${formatWatts(sollVal)}</span>` : '';
                    fElem.innerHTML = `Prog: ${formatWatts(fVal)} (${pctStr})${sollStr}`;
                    
                    if (fVal > 0 && ratio < 0.75) fElem.style.color = '#d56666'; // Rot (< 75%)
                    else if (fVal > 0 && ratio > 1.25) fElem.style.color = '#10b981'; // Grün (> 125%)
                    else fElem.style.color = '#fbbf24'; // Gelb (Standard)
                    
                    fElem.style.display = 'block';
                }
            } else if (fElem) { fElem.style.display = 'none'; }
        }

        // Batterie
        let bat = data.bat || 0;
        let batFill = document.getElementById('fill-bat');
        let batIcon = bat >= 0 ? '<i class="fas fa-arrow-up small me-1"></i>' : '<i class="fas fa-arrow-down small me-1"></i>';
        document.getElementById('val-bat').innerHTML = batIcon + formatWatts(bat);
        
        let batClass = 'value ';
        if (bat > 0) batClass += 'text-success';
        else if (bat < 0) batClass += 'text-danger';
        else batClass += 'text-body';
        document.getElementById('val-bat').className = batClass;

        batFill.style.width = (data.soc || 0) + '%';
        batFill.style.background = (bat >= 0) ? '#10b981' : '#ef4444'; // + Grün (Laden), - Rot (Entladen)
        
        let absBat = Math.abs(bat);
        if (absBat > 20) {
            let ratio = Math.min(1, absBat / BAT_MAX);
            let speed = 10.0 - (ratio * 8.4); // Von 10s (langsam) bis 1.6s (schnell) - Faktor 4 verlangsamt
            let intensity = 0.25 + (ratio * 0.55); // Von 0.25 bis 0.8 Opacity
            batFill.style.setProperty('--pulse-speed', speed + 's');
            batFill.style.setProperty('--pulse-intensity', intensity);
        }
        batFill.classList.toggle('pulse-active', absBat > 20);
        let capInfo = (BAT_CAPACITY > 0) ? ` von ${BAT_CAPACITY} kWh` : '';
        let timeInfo = '';
        if (BAT_CAPACITY > 0 && absBat > 50) {
            let hours = 0;
            let soc = parseFloat(data.soc) || 0;
            let timePrefix = '';
            if (bat > 0 && soc < 100) {
                hours = ((100 - soc) / 100 * BAT_CAPACITY * 1000) / bat;
                timePrefix = 'voll in';
            } else if (bat < 0 && soc > 0) {
                hours = (soc / 100 * BAT_CAPACITY * 1000) / absBat;
                timePrefix = 'leer in';
            }
            
            if (hours > 0 && hours < 48) {
                let h = Math.floor(hours);
                let m = Math.round((hours - h) * 60);
                timeInfo = ` | ${timePrefix} ${h}:${m.toString().padStart(2, '0')}h`;
            }
        }
        document.getElementById('label-soc').innerHTML = `Batterie (${data.soc}%)${capInfo}<span>${timeInfo}</span>`;

        // Haus & Netz
        let h = (data.wp > 10) ? Math.max(0, (data.home_raw || 0)-data.wp) : (data.home_raw || 0);
        document.getElementById('val-home').innerHTML = formatWatts(h);
        document.getElementById('fill-home').style.width = Math.min(100, (h / AVGS.home) * 100) + '%';
        
        let grid = data.grid || 0;
        let gridFill = document.getElementById('fill-grid');
        let gridIcon = grid <= 0 ? '<i class="fas fa-arrow-up small me-1"></i>' : '<i class="fas fa-arrow-down small me-1"></i>';
        document.getElementById('val-grid').innerHTML = gridIcon + formatWatts(grid);
        document.getElementById('val-grid').className = 'value ' + (grid <= 0 ? 'text-success' : 'text-danger');
        gridFill.style.width = Math.min(100, (Math.abs(grid) / AVGS.grid) * 100) + '%';
        gridFill.style.background = (grid <= 0) ? '#10b981' : '#f43f5e'; // - Grün (Einspeisung), + Rot (Bezug)

        // WP & WB Layout
        const wpC = document.getElementById('card-wp'); const wbC = document.getElementById('card-wb');
        wpC.style.display = data.wp > 10 ? 'block' : 'none';
        wbC.style.display = data.wb > 0 ? 'block' : 'none';
        if (data.wp > 10 && data.wb > 0) { wpC.className = 'col-6'; wbC.className = 'col-6'; }
        else { wpC.className = 'col-12'; wbC.className = 'col-12'; }
        if (data.wp > 10) { document.getElementById('val-wp').innerHTML = formatWatts(data.wp); document.getElementById('fill-wp').style.width = Math.min(100, (data.wp / WP_MAX) * 100) + '%'; }
        if (data.wb > 0) { 
            document.getElementById('val-wb').innerHTML = formatWatts(data.wb); 
            let wbFill = document.getElementById('fill-wb');
            wbFill.style.width = Math.min(100, (data.wb / AVGS.wb) * 100) + '%';
            
            let ratio = Math.min(1, data.wb / 11000); // 11kW Referenz für Animation
            let speed = 10.0 - (ratio * 8.4);
            let intensity = 0.25 + (ratio * 0.55);
            wbFill.style.setProperty('--pulse-speed', speed + 's');
            wbFill.style.setProperty('--pulse-intensity', intensity);
            wbFill.classList.add('pulse-active');
        } else {
            document.getElementById('fill-wb').classList.remove('pulse-active');
        }
        
        // Strompreis
        const priceVal = document.getElementById('val-price');
        const priceCard = document.getElementById('card-price');
        const priceNum = Number(data.price_ct);
            if (priceVal) {
                function gmtToLocal(slot) {
                    const val = parseFloat(slot);
                    if (isNaN(val)) return '--:--';
                    
                    const gmtHour = Math.floor(val);
                    const gmtMin = Math.round((val - gmtHour) * 60);
                    
                    const now = new Date();
                    const date = new Date();
                    date.setUTCHours(gmtHour, gmtMin, 0, 0);
                    
                    let dayLabel = (date.getDate() !== now.getDate()) ? 'morgen' : 'heute';
                    let localHour = date.getHours();
                    let localMin = date.getMinutes();
                    return dayLabel + ' ' + String(localHour).padStart(2, '0') + ':' + String(localMin).padStart(2, '0');
                }
                let minPrice = '--';
                let minTime = '--:--';
                let maxPrice = '--';
                let maxTime = '--:--';
                if (typeof data.price_min_ct === 'number') {
                    minPrice = data.price_min_ct.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                if (typeof data.price_max_ct === 'number') {
                    maxPrice = data.price_max_ct.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                minTime = gmtToLocal(data.price_min_slot);
                maxTime = gmtToLocal(data.price_max_slot);

                // Tendenz vorab berechnen für die Hauptanzeige
                let prices = (data.prices && data.prices.length > 0) ? data.prices : PRICE_HISTORY;
                let startHour = PRICE_START_HOUR;
                let interval = PRICE_INTERVAL;

                // Wenn statische Daten geladen wurden (z.B. awattardebug.23.txt), nutzen wir diese für den Chart,
                // da die Live-Daten (data.prices) oft abgeschnitten sind (Strich ganz links).
                if (USE_STATIC_CHART) {
                    prices = PRICE_HISTORY;
                } else if (data.prices && data.prices.length > 0) {
                     if (data.price_start_hour !== undefined && data.price_start_hour !== null) startHour = data.price_start_hour;
                     if (data.price_interval !== undefined && data.price_interval !== null) interval = data.price_interval;
                }

                priceTendencyHtml = '';
                let hourDiff = 0;
                if (prices && prices.length > 0) {
                    const now = new Date();
                    const curGmtDec = now.getUTCHours() + (now.getUTCMinutes() / 60);
                    

                    hourDiff = curGmtDec - startHour;
                    if (hourDiff < 0) hourDiff += 24;
                    let idx = Math.floor(hourDiff / interval);
                    if (prices[idx] !== undefined && prices[idx+1] !== undefined) {
                        const diff = prices[idx+1] - prices[idx];
                        const isExtreme = Math.abs(diff) > 5;
                        const blinkClass = isExtreme ? ' blink-extreme' : '';
                        
                        if (diff > 0.1) priceTendencyHtml = '<i class="fas fa-arrow-trend-up text-danger ms-2' + blinkClass + '" style="font-size: 0.7em; vertical-align: middle;" title="Preis steigend"></i>';
                        else if (diff < -0.1) priceTendencyHtml = '<i class="fas fa-arrow-trend-down text-success ms-2' + blinkClass + '" style="font-size: 0.7em; vertical-align: middle;" title="Preis fallend"></i>';
                        else priceTendencyHtml = '<i class="fas fa-arrow-right text-info ms-2" style="font-size: 0.7em; vertical-align: middle;" title="Preis stabil"></i>';
                    }
                }

                let priceText = (Number.isFinite(priceNum) ? priceNum.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '--') + '<span class="unit"> ct/kWh</span>' + priceTendencyHtml;
                priceVal.innerHTML = priceText;

                const minEl = document.getElementById('price-val-min');
                const maxEl = document.getElementById('price-val-max');
                if (minEl) minEl.innerHTML = '<span style="color:#10b981">' + minPrice + '<span class="unit" style="font-size:0.65em;margin-left:2px"> ct</span></span><br><span style="font-size:0.65em;color:#aaa;font-weight:normal">' + minTime + '</span>';
                if (maxEl) maxEl.innerHTML = '<span style="color:#f43f5e">' + maxPrice + '<span class="unit" style="font-size:0.65em;margin-left:2px"> ct</span></span><br><span style="font-size:0.65em;color:#aaa;font-weight:normal">' + maxTime + '</span>';

                // Hintergrund-Chart zeichnen (Stufenlinie als Area-Chart)
                const chart = document.getElementById('price-chart');
                if (chart && prices && prices.length > 0) {
                    const min = Math.min(...prices);
                    const max = Math.max(...prices);
                    const range = max - min || 1;
                    let pathData = "M 0 100"; // Start unten links
                    
                    let minIndices = [];
                    let maxIndices = [];

                    for (let i = 0; i < prices.length; i++) {
                        if (Math.abs(prices[i] - min) < 0.001) minIndices.push(i);
                        if (Math.abs(prices[i] - max) < 0.001) maxIndices.push(i);

                        const xStart = (i / prices.length) * 240;
                        const xEnd = ((i + 1) / prices.length) * 240;
                        const y = 100 - ((prices[i] - min) / range * 60 + 5); // Skaliert auf max 60% Höhe
                        pathData += ` L ${xStart} ${y} H ${xEnd}`;
                        if (i < prices.length - 1) {
                            const nextY = 100 - ((prices[i+1] - min) / range * 60 + 5);
                            pathData += ` V ${nextY}`;
                        }
                    }
                    pathData += " L 240 100 Z"; // Schließen nach unten rechts
                    
                    // Farbige Balken für Min/Max generieren
                    let bars = "";
                    const slotW = 240 / prices.length;
                    const getY = (p) => 100 - ((p - min) / range * 60 + 5);
                    
                    maxIndices.forEach(i => {
                        bars += `<rect x="${i*slotW}" y="${getY(prices[i])}" width="${slotW}" height="${100-getY(prices[i])}" fill="#f43f5e" fill-opacity="0.4" />`;
                    });
                    minIndices.forEach(i => {
                        bars += `<rect x="${i*slotW}" y="${getY(prices[i])}" width="${slotW}" height="${100-getY(prices[i])}" fill="#10b981" fill-opacity="0.7" />`;
                    });

                    // Aktuelle Zeit Markierung berechnen (GMT Abgleich)
                    const now = new Date();
                    const xPosPercent = (hourDiff / (prices.length * PRICE_INTERVAL)) * 100;
                    
                    chart.innerHTML = `<path d="${pathData}" fill="currentColor" fill-opacity="0.25" stroke="none" />` + bars;
                    
                    // Marker-Elemente (HTML statt SVG um Verzerrung zu vermeiden)
                    const line = document.getElementById('price-line');
                    const label = document.getElementById('price-time-label');
                    if (xPosPercent >= 0 && xPosPercent <= 100) {
                        line.style.left = xPosPercent + '%'; line.style.display = 'block';
                        label.style.left = xPosPercent + '%'; label.style.display = 'block';
                        label.innerText = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
                        // Label-Anker korrigieren
                        if (xPosPercent > 85) label.style.transform = 'translateX(-100%)';
                        else if (xPosPercent < 15) label.style.transform = 'translateX(0%)';
                        else label.style.transform = 'translateX(-50%)';
                    }

                    // Tagestrenner (Morgen 00:00 Uhr Local)
                    const dayLine = document.getElementById('price-line-day');
                    const yesterdayLine = document.getElementById('price-line-yesterday');
                    const dayOverlay = document.getElementById('price-overlay-tomorrow');
                    const dayLabel = document.getElementById('price-label-tomorrow');
                    const yesterdayLabel = document.getElementById('price-label-yesterday');

                    if (dayLine) {
                        const totalHours = prices.length * PRICE_INTERVAL;
                        const nowLocal = new Date();
                        const localHours = nowLocal.getHours() + (nowLocal.getMinutes() / 60);
                        
                        // hourDiff ist die Zeit von Chart-Start bis JETZT (berechnet oben)
                        // Wir berechnen die Positionen relativ zu JETZT
                        
                        // Start von Heute (00:00 Uhr) = Jetzt - localHours
                        let posToday = hourDiff - localHours;
                        
                        // Start von Morgen (24:00 Uhr) = Jetzt + (24 - localHours)
                        let posTomorrow = hourDiff + (24 - localHours);
                        
                        const pctToday = (posToday / totalHours) * 100;
                        const pctTomorrow = (posTomorrow / totalHours) * 100;
                        
                        // Linie für Morgen
                        if (pctTomorrow > 0 && pctTomorrow < 100) { 
                            dayLine.style.left = pctTomorrow + '%'; dayLine.style.display = 'block'; 
                            if (dayOverlay) { dayOverlay.style.left = pctTomorrow + '%'; dayOverlay.style.display = 'block'; }
                        } else { 
                            dayLine.style.display = 'none'; 
                            if (dayOverlay) {
                                if (pctTomorrow <= 0) { // Alles ist Morgen
                                    dayOverlay.style.left = '0%'; dayOverlay.style.display = 'block';
                                } else {
                                    dayOverlay.style.display = 'none';
                                }
                            }
                        }
                        if (dayLabel) {
                            dayLabel.style.display = (pctTomorrow < 100) ? 'block' : 'none';
                        }

                        // Linie für Heute (Start of Today)
                        if (pctToday > 0 && pctToday < 100) { 
                            yesterdayLine.style.left = pctToday + '%'; yesterdayLine.style.display = 'block'; 
                        } else { 
                            yesterdayLine.style.display = 'none'; 
                        }
                        
                        if (yesterdayLabel) {
                            yesterdayLabel.style.display = (pctToday > 0) ? 'block' : 'none';
                        }
                    }
                }
                updateLastUpdateDisplay();

                if (priceNum < 10) {
                    priceVal.className = 'value text-success price-ultra-cheap';
                    if (priceCard) {
                        priceCard.style.background = 'rgba(16, 185, 129, 0.25)';
                        priceCard.style.borderColor = '#10b981';
                    }
                } else if (data.price_level === 'cheap') {
                    priceVal.className = 'value text-success';
                    if (priceCard) {
                        priceCard.style.background = 'rgba(16, 185, 129, 0.13)';
                        priceCard.style.borderColor = '#2d3748';
                    }
                } else if (data.price_level === 'expensive') {
                    priceVal.className = 'value text-danger';
                    if (priceCard) {
                        priceCard.style.background = 'rgba(244, 63, 94, 0.13)';
                        priceCard.style.borderColor = '#2d3748';
                    }
                } else {
                    priceVal.className = 'value';
                    priceVal.style.color = '#fbbf24';
                    if (priceCard) {
                        priceCard.style.background = 'rgba(251, 191, 36, 0.13)';
                        priceCard.style.borderColor = '#2d3748';
                    }
                }
            }

        // Details über dem Diagramm aktualisieren
        const detailsEl = document.getElementById('diagramDetails');
        const diagContainer = document.getElementById('diagramContainer');
        if (detailsEl && diagContainer.style.display !== 'none') {
            let content = '';
            if (CURRENT_VIEW === 'pv') {
                if (data.dc0_w !== undefined) content = `Gesamt: ${data.pv}W | String 1: ${data.dc0_w}W (${data.dc0_v}V) | String 2: ${data.dc1_w}W (${data.dc1_v}V)`;
            } else if (CURRENT_VIEW === 'grid') {
                if (data.grid_p1 !== undefined) content = `Netz: ${data.grid}W (L1: ${data.grid_p1} | L2: ${data.grid_p2} | L3: ${data.grid_p3})`;
                if (data.ac0_w !== undefined) {
                    const wrTotal = (data.ac0_w || 0) + (data.ac1_w || 0) + (data.ac2_w || 0);
                    content += `<br>WR: ${wrTotal}W (L1: ${data.ac0_w} | L2: ${data.ac1_w} | L3: ${data.ac2_w})`;
                }
            } else if (CURRENT_VIEW === 'wb') {
                if (data.wb_p1 !== undefined) content = `Wallbox L1: ${data.wb_p1}W | L2: ${data.wb_p2}W | L3: ${data.wb_p3}W`;
            } else if (CURRENT_VIEW === 'bat') {
                if (data.bat_v !== undefined) content = `Spannung: ${data.bat_v}V | Strom: ${data.bat_a}A`;
            }
            
            if (content) {
                detailsEl.innerHTML = content;
                detailsEl.style.display = 'block';
            } else {
                detailsEl.style.display = 'none';
            }
        }
    }).catch(() => {
        const statusBadge = document.getElementById('connection-status');
        if (statusBadge) {
            statusBadge.className = 'badge rounded-pill bg-danger text-white';
            statusBadge.innerText = 'Offline';
        }
    });
}

function updateLastUpdateDisplay() {
    if (!lastUpdateTs) return;
    const d = new Date(lastUpdateTs * 1000);
    const timeStr = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
    document.getElementById('diagramStatus').innerHTML = timeStr;
}

function toggleDiagram(view = 'normal') {
    let c = document.getElementById('diagramContainer'); let f = document.getElementById('diagramFrame');
    
    if (c.style.display === 'none' || CURRENT_VIEW !== view) {
        c.style.display = 'block';
        const now = Math.floor(Date.now() / 1000);
        // Automatisches Update nur wenn älter als 15 Min (900 Sek)
        if (now - lastUpdateTs > 900) {
            updateDiagram();
        } else if (!f.src || f.src === window.location.href) {
            f.src = 'live_diagramm.html?t=' + Date.now();
        }
        CURRENT_VIEW = view;
        updateDashboard(); // Sofort Details aktualisieren
        updateDiagram(); // Trigger update with new view
    } else if (c.style.display === 'block' && CURRENT_VIEW === view) { 
        c.style.display = 'none'; 
    }
}

function setLiveHours(hours, btn) {
    currentLiveHours = hours;
    const group = document.getElementById('liveTimeFilter');
    if (group) {
        group.querySelectorAll('.btn').forEach(b => b.classList.remove('active'));
    }
    if (btn) btn.classList.add('active');
    updateDiagram();
}

function updateDiagram() {
    const btn = document.getElementById('diagramUpdateBtn');
    const status = document.getElementById('diagramStatus');
    const frame = document.getElementById('diagramFrame');
    
    btn.disabled = true;
    status.textContent = 'Lade Daten...';
    
    const timestamp = Date.now();
    fetch('mobile.php?action=run_live_history&hours=' + currentLiveHours + '&view=' + CURRENT_VIEW + '&dark=' + (DARK_MODE ? '1' : '0'))
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            const checkInterval = setInterval(() => {
                fetch('mobile.php?action=status&mode=mobile')
                .then(r => r.json())
                .then(stat => {
                    if (!stat.running) {
                        clearInterval(checkInterval);
                        // Nur neu laden, wenn kein Fehler vorliegt
                        if (!stat.error) {
                            const newUrl = 'live_diagramm.html?t=' + Date.now();
                            frame.contentWindow.location.replace(newUrl);
                            lastUpdateTs = Math.floor(Date.now() / 1000);
                            status.textContent = 'Aktualisiert';
                            setTimeout(updateLastUpdateDisplay, 2000);
                        } else {
                            status.textContent = 'Fehler!';
                        }
                        btn.disabled = false;
                    }
                });
            }, 1000);
        } else {
            status.textContent = 'Fehler: ' + (data.error || 'Unbekannt');
            btn.disabled = false;
        }
    }).catch(() => {
        status.textContent = 'Fehler';
        btn.disabled = false;
    });
}

function updateForecast() {
    const btn = document.getElementById('forecastUpdateBtn');
    const status = document.getElementById('forecastStatus');
    const frame = document.getElementById('forecastFrame');
    
    if(btn) btn.disabled = true;
    if(status) status.textContent = 'Berechne Prognose...';
    
    fetch('mobile.php?action=run_now&mode=mobile&dark=' + (DARK_MODE ? '1' : '0'))
    .then(r => r.text())
    .then(txt => {
        if (txt === 'started' || txt === 'running') {
            const checkInterval = setInterval(() => {
                fetch('mobile.php?action=status&mode=mobile')
                .then(r => r.json())
                .then(stat => {
                    if (!stat.running) {
                        clearInterval(checkInterval);
                        if (!stat.error) {
                            frame.src = 'diagramm_mobile.html?t=' + Date.now();
                            if(status) status.textContent = 'Aktualisiert: ' + new Date().toLocaleTimeString();
                        } else {
                            if(status) status.textContent = 'Fehler bei der Berechnung';
                        }
                        if(btn) btn.disabled = false;
                    }
                });
            }, 1000);
        } else if (txt === 'skipped') {
             if(status) status.textContent = 'Daten sind aktuell.';
             if(btn) btn.disabled = false;
        } else {
             if(status) status.textContent = 'Fehler: ' + txt;
             if(btn) btn.disabled = false;
        }
    })
    .catch(() => {
        if(status) status.textContent = 'Netzwerkfehler';
        if(btn) btn.disabled = false;
    });
}

function startSystemUpdate() {
    const log = document.getElementById('update-log');
    const spinner = document.getElementById('update-spinner');
    const closeBtn = document.getElementById('update-close-btn');
    const finishBtn = document.getElementById('update-finish-btn');

    // Prüfung vorab
    fetch('mobile.php?action=check_update&force_check=1').then(r=>r.json()).then(d => {
        // UI sofort aktualisieren, wenn Updates gefunden wurden
        if (d.success && d.missing > 0) {
            const bNav = document.getElementById('update-badge-nav');
            const bBtn = document.getElementById('update-badge-btn');
            const btnUpdate = document.getElementById('btn-system-update');
            
            if(bNav) bNav.style.display = 'inline-block';
            if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = d.missing + ' NEU'; }
            if(btnUpdate) {
                btnUpdate.classList.remove('btn-outline-warning');
                btnUpdate.classList.add('btn-warning');
            }
        }

        if (d.success && d.missing === 0) {
            alert("Das System ist auf dem neuesten Stand.");
            return;
        }
        if (!confirm(`Es sind ${d.missing || 0} Updates verfügbar.\nUpdate jetzt starten?`)) return;

        // Optionen an Server senden
        fetch('mobile.php?action=prepare_update&force=false&discard=false')
    .then(() => {
        const modal = new bootstrap.Modal(document.getElementById('updateModal'));
        modal.show();
        
        // Reset UI
        log.innerText = "Starte Anfrage...\n";
        spinner.className = "fas fa-sync fa-spin me-2";
        closeBtn.style.display = 'none';
        finishBtn.disabled = true;
        finishBtn.innerText = "Schließen";
        
        // Start Request
        fetch('mobile.php?action=run_update&mode=start&t=' + Date.now())
            .then(r => r.json())
            .then(data => {
                if (data.status === 'started' || data.status === 'running') {
                    log.innerText = "Update gestartet. Warte auf Ausgabe...\n";
                    pollUpdate();
                } else {
                    log.innerText = "Fehler: " + (data.message || "Unbekannter Fehler");
                    finishBtn.disabled = false;
                }
            })
            .catch(err => {
                log.innerText = "Netzwerkfehler beim Starten: " + err;
                finishBtn.disabled = false;
            });
    });
    });

    function pollUpdate() {
        let tick = 0;
        const interval = setInterval(() => {
            tick++;
            // Zeitstempel anhängen um Cloudflare-Cache zu umgehen
            fetch('mobile.php?action=run_update&mode=poll&t=' + Date.now())
                .then(r => r.json())
                .then(data => {
                    if (typeof data.log === 'string') {
                        log.innerText = data.log;
                    }
                    const modalBody = log.parentElement;
                    modalBody.scrollTop = modalBody.scrollHeight;

                    // Prüfen ob Erfolgsmeldung im Log steht (Fallback, falls Prozess-Status hängt)
                    const logText = data.log || "";
                    const successFound = logText.includes("Update erfolgreich abgeschlossen") || 
                                         logText.includes("Du bist auf dem neuesten Stand") ||
                                         logText.includes("Vorgang abgebrochen");

                    if (!data.running || successFound) {
                        clearInterval(interval);
                        setTimeout(() => finalize(data.log), 500);
                    }
                })
                .catch(err => {
                    console.error("Poll error:", err);
                    log.innerText += "\n[Verbindungsfehler: " + err.message + " - versuche erneut...]";
                });
        }, 1000);
    }

    function finalize(logText) {
        spinner.classList.remove('fa-spin', 'fa-sync');
        
        if (logText.includes("Update erfolgreich abgeschlossen") || logText.includes("Du bist auf dem neuesten Stand")) {
            spinner.classList.add('fa-check-circle', 'text-success');
            log.innerText += "\n\n✓ Vorgang erfolgreich beendet.";
        } else {
            spinner.classList.add('fa-times-circle', 'text-danger');
            log.innerText += "\n\n✗ Update fehlgeschlagen oder unvollständig.";
        }

        closeBtn.style.display = 'block';
        finishBtn.disabled = false;
    }
}

updateDashboard(); setInterval(updateDashboard, 4000);

// Initialisierung für Desktop-Modus
window.addEventListener('DOMContentLoaded', () => {
    if (document.body.classList.contains('mode-desktop')) {
        document.getElementById('diagramFrame').src = 'diagramm_mobile.html?t=' + Date.now();
    }

    // Update Check (Initial)
    setTimeout(() => {
        fetch('mobile.php?action=check_update').then(r=>r.json()).then(d => {
            const btnUpdate = document.getElementById('btn-system-update');
            if(d.success && d.missing > 0) {
                const bNav = document.getElementById('update-badge-nav');
                const bBtn = document.getElementById('update-badge-btn');
                if(bNav) bNav.style.display = 'inline-block';
                if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = d.missing + ' NEU'; }
                
                if(btnUpdate) {
                    btnUpdate.classList.remove('btn-outline-warning');
                    btnUpdate.classList.add('btn-warning');
                }
            }
        });
    }, 2000);
    
    // Periodische Prüfung alle 60 Minuten
    setInterval(() => {
        fetch('mobile.php?action=check_update').then(r=>r.json()).then(d => {
            const bNav = document.getElementById('update-badge-nav');
            const bBtn = document.getElementById('update-badge-btn');
            if(d.success && d.missing > 0) {
                if(bNav) bNav.style.display = 'inline-block';
                if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = d.missing + ' NEU'; }
            } else {
                if(bNav) bNav.style.display = 'none';
                if(bBtn) bBtn.style.display = 'none';
            }
        });
    }, 3600000);

    window.restartService = function() {
        if (!confirm('Möchtest du den E3DC-Control Service wirklich neu starten?')) return;
        
        fetch('mobile.php?action=restart_service')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert("✓ Service wird neu gestartet.");
                } else {
                    alert("✗ Fehler: " + data.message);
                }
            })
            .catch(err => alert("Netzwerkfehler: " + err));
    };

    // Watchdog Status
    function checkWatchdog() {
        fetch('mobile.php?action=watchdog_status').then(r=>r.json()).then(data => {
            const icon = document.getElementById('watchdog-icon');
            if (data.installed) {
                icon.style.display = 'inline-block';
                icon.title = data.message;
                if (data.warning) {
                    icon.className = 'fas fa-shield-alt text-warning';
                } else if (data.active) {
                    icon.className = 'fas fa-shield-alt text-success';
                } else {
                    icon.className = 'fas fa-shield-alt text-danger';
                }
            } else {
                icon.style.display = 'none';
            }
        }).catch(e=>{});
    }
    setInterval(checkWatchdog, 10000);
    checkWatchdog();

    window.showWatchdogLog = function() {
        const modal = new bootstrap.Modal(document.getElementById('watchdogModal'));
        modal.show();
        document.getElementById('watchdog-log-content').innerText = 'Lade Protokoll...';
        
        fetch('mobile.php?action=watchdog_log')
            .then(r => r.text())
            .then(text => {
                document.getElementById('watchdog-log-content').innerText = text;
            })
            .catch(e => {
                document.getElementById('watchdog-log-content').innerText = 'Fehler beim Laden.';
            });
    };
    
    // Interaktive Online-Anzeige
    window.handleConnectionClick = function() {
        const badge = document.getElementById('connection-status');
        const isOffline = badge.classList.contains('bg-danger') || badge.classList.contains('bg-warning');
        
        if (isOffline) {
            if (confirm("Verbindungsprobleme erkannt.\nMöchtest du den E3DC-Service neu starten?")) {
                restartService();
            } else {
                badge.innerText = "Lade...";
                updateDashboard();
            }
        } else {
            badge.innerText = "Aktualisiere...";
            updateDashboard();
        }
    };
});
</script>
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register('./sw.js')
        .then(reg => console.log('Service Worker erfolgreich registriert!', reg))
        .catch(err => console.error('Service Worker Registrierung fehlgeschlagen:', err));
    });
  } else {
    console.log('Service Worker wird von diesem Browser nicht unterstützt.');
  }
</script>
</body>
</html>
