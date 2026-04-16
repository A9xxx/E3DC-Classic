<?php
session_start();
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
require_once 'logic.php';
$seite = $_GET['seite'] ?? 'dashboard';

// Archiv-Dateien für das Dropdown laden
$archivFiles = [];
$historyFiles = [];
$diagramMode = 'manual'; // Default
$diagramInterval = 5; // Default Intervall in Minuten

if ($seite === 'dashboard') {
    $paths = getInstallPaths();
    $base_path = rtrim($paths['install_path'], '/') . '/';
    $archivFiles = getArchivedDebugFiles($base_path);
    $historyFiles = getHistoryBackupFiles();

    // Diagramm-Intervall aus Config laden
    $diagConf = $base_path . 'diagram_config.json';
    if (file_exists($diagConf)) {
        $dc = @json_decode(file_get_contents($diagConf), true);
        if (isset($dc['auto_interval_minutes'])) $diagramInterval = (int)$dc['auto_interval_minutes'];
        if (isset($dc['diagram_mode'])) $diagramMode = $dc['diagram_mode'];
    }
}
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="<?= $darkMode ? 'dark' : 'light' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E3DC Control Dashboard</title>

    <link rel="manifest" href="manifest.json">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <style>
        /* Dark Mode Defaults (Bootstrap handles most via data-bs-theme="dark") */
        [data-bs-theme="dark"] body { background-color: #121212; color: #e0e0e0; }
        [data-bs-theme="dark"] .card { background-color: #1e1e1e; border-color: #333; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        
        /* Light Mode Overrides */
        [data-bs-theme="light"] body { background-color: #f8f9fa; color: #212529; }
        [data-bs-theme="light"] .card { background-color: #ffffff; border-color: #dee2e6; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }

        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .card { border-radius: 12px; transition: transform 0.2s; }
        /* Hover-Effekt nur für Dashboard-Cards, nicht für Container in Unterseiten */
        .dashboard-view .card:hover { transform: translateY(-2px); border-color: #444; cursor: pointer; }
        .icon-box { width: 56px; height: 56px; display: flex; align-items: center; justify-content: center; border-radius: 16px; font-size: 1.75rem; }
        .val-large { font-size: 2.2rem; font-weight: 700; letter-spacing: -1px; }
        .val-unit { font-size: 1rem; color: #888; font-weight: 400; margin-left: 4px; }
        .pulsating { animation: pulse 2s infinite ease-in-out; }
        @keyframes pulse {
            0% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.92); }
            100% { opacity: 1; transform: scale(1); }
        }
        .chart-container { height: 500px; width: 100%; overflow: hidden; border-radius: 0 0 12px 12px; }
        iframe { border: none; width: 100%; height: 100%; }
        .btn-group-custom .btn { border-color: #444; color: #aaa; }
        .btn-group-custom .btn:hover, .btn-group-custom .btn.active { background-color: #333; color: #fff; border-color: #555; }
        .status-badge { font-size: 0.8rem; padding: 0.35em 0.65em; }
        
        /* Anpassungen für inkludierte Seiten */
        .container-fluid { max-width: 1920px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom border-secondary mb-3 py-2">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold" href="index.php"><i class="fas fa-solar-panel text-warning me-2"></i>E3DC Control</a>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end d-none d-md-block">
                    <div id="clock" class="fw-bold text-light" style="font-size: 1.1rem;">--:--</div>
                    <div class="small text-muted" id="date">--.--.----</div>
                </div>
                <button class="btn btn-link text-secondary p-0" onclick="toggleDarkMode()" title="Dark Mode umschalten">
                    <i class="fas fa-<?= $darkMode ? 'moon' : 'sun' ?>" id="darkmode-icon"></i>
                </button>
                <a href="index.php?seite=config" class="text-secondary position-relative" title="Konfiguration">
                    <i class="fas fa-cog fa-lg"></i>
                    <span id="update-badge-nav" class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle" style="display:<?= getUpdateStatusFromCache() > 0 ? 'inline-block' : 'none' ?>;">
                        <span class="visually-hidden">Update</span>
                    </span>
                </a>
                <span id="watchdog-badge" class="badge rounded-pill bg-secondary" style="display:none; cursor:pointer;" onclick="showWatchdogLog()" title="Watchdog Status">
                    <i class="fas fa-shield-alt"></i>
                </span>
                <?= renderConnectionBadge() ?>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 pb-5">
        <?php if ($seite === 'dashboard'): ?>
        <div class="dashboard-view">
        <!-- Status Cards -->
        <div class="row g-2 mb-3">
            <!-- PV -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100" onclick="switchChartMode('live', 'pv')">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-warning bg-opacity-10 text-warning me-3" id="icon-pv-box">
                            <i class="fas fa-sun" id="icon-pv"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <h6 class="card-subtitle text-muted text-uppercase small fw-bold m-0">PV Leistung</h6>
                                <i class="fas fa-eye<?= $showForecast ? '' : '-slash' ?> text-muted" style="cursor:pointer; font-size:0.8rem;" onclick="event.stopPropagation(); toggleForecast(this)" title="Prognose an/aus"></i>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="val-large text-warning" id="val-pv">--<span class="val-unit">W</span></div>
                                <div class="text-end text-muted" id="val-pv-details" style="display:none;">
                                    <div class="mb-1">Prog: <span id="val-pv-forecast" class="fw-bold" style="font-size: 1.1rem;">--</span></div>
                                    <div class="small">Soll: <span id="val-pv-soll" class="fw-bold">--</span></div>
                                </div>
                            </div>
                            <!-- Hidden Details -->
                            <div class="small text-muted mt-1" id="pv-strings-detail" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Battery -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100" onclick="switchChartMode('live', 'bat')">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-success bg-opacity-10 text-success me-3" id="icon-bat">
                            <i class="fas fa-battery-half"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                <h6 class="card-subtitle text-muted text-uppercase small fw-bold m-0">Batterie <span id="val-soc" class="ms-1">--%</span></h6>
                                <span id="val-bat-time" class="text-muted" style="font-size: 1.1rem;"></span>
                            </div>
                            <div class="val-large text-success" id="val-bat-container">--<span class="val-unit">W</span></div>
                            <div class="small text-muted mt-1" id="bat-details" style="display:none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Home -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-info bg-opacity-10 text-info me-3">
                            <i class="fas fa-home"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-subtitle text-muted mb-1 text-uppercase small fw-bold">Hausverbrauch</h6>
                            <div class="val-large text-info" id="val-home">--<span class="val-unit">W</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Grid -->
            <div class="col-md-6 col-xl-3">
                <div class="card h-100" onclick="switchChartMode('live', 'grid')">
                    <div class="card-body d-flex align-items-center">
                        <div class="icon-box bg-secondary bg-opacity-10 text-secondary me-3" id="icon-grid">
                            <i class="fas fa-network-wired"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="card-subtitle text-muted mb-1 text-uppercase small fw-bold">Netz</h6>
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="val-large text-body" id="val-grid-container">--<span class="val-unit">W</span></div>
                                <div class="text-end text-muted small" id="grid-details" style="display:none; line-height: 1.2;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="row g-2">
            <!-- Left Column: Chart -->
            <div class="col-xl-9 col-lg-8">
                <div class="card h-100">
                    <div class="card-header bg-transparent border-secondary d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center gap-3">
                            <h6 class="mb-0 fw-bold text-nowrap" id="chart-title"><i class="fas fa-chart-line me-2 text-secondary"></i>SoC Prognose</h6>
                        </div>
                        <div class="d-flex gap-2 align-items-center">
                            <!-- Ansicht Auswahl -->
                            <select class="form-select form-select-sm border-secondary d-none" id="chart-mode-select" style="width: auto; min-width: 120px;" onchange="switchChartMode(this.value)">
                                <option value="forecast" selected>Prognose</option>
                                <option value="live">Live-Verlauf</option>
                                <option value="archive">Archiv</option>
                            </select>

                            <!-- Live Controls -->
                            <div class="gap-2" id="live-controls" style="display:none;">
                                <div class="btn-group btn-group-sm btn-group-custom" role="group">
                                    <button type="button" class="btn btn-outline-secondary active" onclick="updateChart(6, this)">6h</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="updateChart(12, this)">12h</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="updateChart(24, this)">24h</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="updateChart(48, this)">48h</button>
                                </div>
                                <select class="form-select form-select-sm border-secondary" style="max-width: 130px;" onchange="updateChartHistory(this.value)">
                                    <option value="" selected>Live</option>
                                    <?php foreach ($historyFiles as $hf): ?>
                                        <option value="<?= htmlspecialchars($hf['file']) ?>"><?= htmlspecialchars($hf['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Archiv Controls -->
                            <select class="form-select form-select-sm border-secondary" id="archive-select" style="display:none; max-width: 200px;" onchange="loadArchive(this.value)">
                                <option value="" disabled selected>Datei wählen...</option>
                                <?php foreach ($archivFiles as $af): ?>
                                    <option value="<?= htmlspecialchars($af['file']) ?>"><?= htmlspecialchars($af['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-secondary" onclick="refreshData()" id="main-refresh-btn" title="Aktualisieren">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    <div id="diagramDetails" class="px-3 pb-2 text-info small fw-bold" style="display:none;"></div>
                    <div class="card-body p-0 chart-container">
                        <iframe id="chart-frame" src="diagramm.html?t=<?= time() ?>" scrolling="no"></iframe>
                    </div>
                </div>
            </div>

            <!-- Right Column: Details & Controls -->
            <div class="col-xl-3 col-lg-4">
                <div class="row g-2" id="right-column-cards">
                    <!-- Price Card -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="card-title text-muted text-uppercase small fw-bold"><i class="fas fa-tags me-2"></i>Strompreis</h6>
                                    <span class="badge bg-body-tertiary border border-secondary text-body-secondary" id="price-trend"><i class="fas fa-minus"></i></span>
                                </div>
                                <div class="d-flex align-items-baseline mb-3">
                                    <div class="val-large text-body" id="val-price">--<span class="val-unit">ct/kWh</span></div>
                                </div>
                                <div class="progress bg-secondary-subtle" style="height: 6px;">
                                    <div class="progress-bar bg-info" role="progressbar" style="width: 50%" id="price-bar"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2 small text-muted">
                                    <span>Min: <span id="val-price-min" class="text-success fw-bold">--</span></span>
                                    <span>Max: <span id="val-price-max" class="text-danger fw-bold">--</span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Wallbox Card -->
                    <div class="col-12">
                        <div class="card" onclick="switchChartMode('live', 'wb')">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="card-title text-muted text-uppercase small fw-bold mb-0"><i class="fas fa-charging-station me-2"></i>Wallbox</h6>
                                    <a href="index.php?seite=wallbox" class="text-secondary" title="Steuern"><i class="fas fa-cog"></i></a>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="icon-box bg-secondary bg-opacity-10 text-secondary me-3 position-relative" id="icon-wb" style="width: 48px; height: 48px; font-size: 1.25rem;">
                                        <i class="fas fa-car"></i>
                                        <i class="fas fa-lock position-absolute text-warning" id="wb-lock-overlay" style="font-size: 0.6rem; bottom: 6px; right: 6px; display: none;"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold" id="val-wb">0<span class="fs-6 text-muted ms-1">W</span></div>
                                        <div class="small text-muted" id="wb-status">Bereit</div>
                                        <div class="small text-muted mt-1" id="wb-details" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Heatpump Card -->
                    <div class="col-12">
                        <div class="card" <?php if($luxtronikEnabled): ?>onclick="switchChartMode('live', 'wp')" style="cursor:pointer;"<?php endif; ?>>
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="card-title text-muted text-uppercase small fw-bold mb-0"><i class="fas fa-water me-2"></i>Wärmepumpe</h6>
                                    <?php if($luxtronikEnabled): ?>
                                    <a href="index.php?seite=luxtronik" class="text-secondary" title="Details" onclick="event.stopPropagation();"><i class="fas fa-info-circle"></i></a>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="icon-box bg-info bg-opacity-10 text-info me-3" id="icon-wp" style="width: 48px; height: 48px; font-size: 1.25rem;">
                                        <i class="fas fa-fan"></i>
                                    </div>
                                    <div>
                                        <div class="fs-4 fw-bold" id="val-wp">0<span class="fs-6 text-muted ms-1">W</span></div>
                                        <div class="small text-muted">Leistung</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title text-muted text-uppercase small fw-bold mb-3">Schnellzugriff</h6>
                                <div class="d-grid gap-2">
                                    <button class="btn btn-outline-secondary text-start" onclick="switchChartMode('forecast')">
                                        <i class="fas fa-chart-line me-2 w-25px"></i>SoC Prognose
                                    </button>
                                    <button class="btn btn-outline-secondary text-start" onclick="switchChartMode('live')">
                                        <i class="fas fa-chart-area me-2 w-25px"></i>Leistungsverlauf
                                    </button>
                                    <button class="btn btn-outline-secondary text-start" onclick="switchChartMode('archive')">
                                        <i class="fas fa-history me-2 w-25px"></i>Archiv
                                    </button>
                                    <button class="btn btn-outline-warning text-start position-relative" onclick="startSystemUpdate()">
                                        <i class="fas fa-cloud-download-alt me-2 w-25px"></i>E3DC-Control Update
                                        <span id="update-badge-btn" class="badge bg-danger ms-2" style="display:<?= getUpdateStatusFromCache() > 0 ? 'inline-block' : 'none' ?>;">NEU</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <script>
                    // Verschiebe Preis-Karte nach der Wärmepumpe-Karte
                    document.addEventListener('DOMContentLoaded', function() {
                        const parent = document.getElementById('right-column-cards');
                        if (parent) {
                            const priceCard = parent.querySelector('.col-12:nth-child(1)');
                            const heatpumpCard = parent.querySelector('.col-12:nth-child(3)');
                            if (priceCard && heatpumpCard) {
                                // Füge die Preiskarte nach der Wärmepumpenkarte ein
                                heatpumpCard.after(priceCard);
                            }
                        }
                    });
                </script>
            </div>
        </div>
        </div>
        <?php elseif ($seite === 'wallbox'): ?>
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10 col-xl-8">
                    <div class="mb-3">
                        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Zurück zum Dashboard</a>
                    </div>
                    <?php include 'Wallbox.php'; ?>
                </div>
            </div>
        <?php elseif ($seite === 'luxtronik' && $luxtronikEnabled): ?>
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10 col-xl-8">
                    <div class="mb-3">
                        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Zurück zum Dashboard</a>
                    </div>
                    <?php include 'luxtronik.php'; ?>
                </div>
            </div>
        <?php elseif ($seite === 'archiv'): ?>
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10 col-xl-8">
                    <div class="mb-3">
                        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Zurück zum Dashboard</a>
                    </div>
                    <?php include 'archiv.php'; ?>
                </div>
            </div>
        <?php elseif ($seite === 'config'): ?>
            <div class="row justify-content-center">
                <div class="col-12 col-lg-10 col-xl-8">
                    <div class="mb-3 d-flex justify-content-between align-items-center">
                        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-2"></i>Zurück</a>
                        <div>
                            <button id="btn-update-config" class="btn btn-outline-warning btn-sm me-2" onclick="startSystemUpdate()">
                                <i class="fas fa-cloud-download-alt me-2"></i>Update <span id="update-badge-config" class="badge bg-danger ms-1" style="display:<?= getUpdateStatusFromCache() > 0 ? 'inline-block' : 'none' ?>;"><?= getUpdateStatusFromCache() ?></span>
                            </button>
                            <button class="btn btn-outline-danger btn-sm" onclick="restartService()"><i class="fas fa-power-off me-2"></i>E3DC-Control Neustart</button>
                        </div>
                    </div>
                    <?php include 'config_editor.php'; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="text-center text-muted mt-5 pt-3 border-top border-secondary">
            <small>
                E3DC Control &copy; <?= date('Y') ?> | 
                <a href="#" class="text-decoration-none text-secondary" data-bs-toggle="modal" data-bs-target="#changelogModal">Changelog</a>
                <?php
                if (file_exists('VERSION')) {
                    echo ' | v' . htmlspecialchars(trim(file_get_contents('VERSION')));
                }
                ?>
            </small>
        </footer>
    </div>

    <!-- Modals -->
    <?= renderChangelogModal('modal-lg modal-dialog-scrollable') ?>
    <?= renderUpdateModal('modal-lg modal-dialog-scrollable') ?>
    <?= renderWatchdogModal('modal-lg modal-dialog-scrollable') ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="solar.js?v=<?= file_exists('solar.js') ? filemtime('solar.js') : time() ?>"></script>
    <script>
        function updateTime() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString('de-DE', {hour: '2-digit', minute:'2-digit'});
            document.getElementById('date').innerText = now.toLocaleDateString('de-DE');
        }
        setInterval(updateTime, 1000);
        updateTime();

        // Konstanten aus logic.php für JS verfügbar machen
        let FORECAST_DATA = <?= json_encode($forecastData) ?>;
        const PV_STRINGS = <?= json_encode($pvStrings) ?>;
        const LAT = <?= json_encode($lat) ?>;
        const LON = <?= json_encode($lon) ?>;
        const BAT_CAPACITY = <?= json_encode($batteryCapacity) ?>;
        const PV_ATMOSPHERE = <?= json_encode($pvAtmosphere) ?>;
        let SHOW_FORECAST = <?= $showForecast ? 'true' : 'false' ?>;
        let DARK_MODE = <?= $darkMode ? 'true' : 'false' ?>;
        let CURRENT_VIEW = 'normal';

        function formatWatts(w) {
            w = parseFloat(w);
            if (isNaN(w)) return '--';
            if (Math.abs(w) > 4000) {
                return (w / 1000).toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<span class="val-unit">kW</span>';
            }
            return Math.round(w).toLocaleString('de-DE') + '<span class="val-unit">W</span>';
        }

        function toggleForecast(el) {
            SHOW_FORECAST = !SHOW_FORECAST;
            // Icon umschalten
            if (SHOW_FORECAST) el.classList.replace('fa-eye-slash', 'fa-eye');
            else el.classList.replace('fa-eye', 'fa-eye-slash');
            
            // Speichern
            $.post('index.php', {
                action: 'save_setting',
                key: 'show_forecast', 
                value: SHOW_FORECAST ? '1' : '0'
            });
            
            fetchData(); // Sofort aktualisieren
        }

        function toggleDarkMode() {
            DARK_MODE = !DARK_MODE;
            const html = document.documentElement;
            const icon = document.getElementById('darkmode-icon');
            
            html.setAttribute('data-bs-theme', DARK_MODE ? 'dark' : 'light');
            icon.className = DARK_MODE ? 'fas fa-moon' : 'fas fa-sun';
            
            $.post('index.php', {
                action: 'save_setting',
                key: 'darkmode', 
                value: DARK_MODE ? '1' : '0'
            });
            
            // Diagramme aktualisieren (damit Python das neue Theme lädt)
            setTimeout(() => refreshData(false), 100); // Kleiner Delay, damit Variable gesetzt ist
        }

        function fetchData() {
            $.getJSON('get_live_json.php', function(data) {
                if (!data) return;
                
                // Prognose-Daten aktualisieren, falls vorhanden
                if (data.forecast && data.forecast.length > 0) {
                    FORECAST_DATA = data.forecast;
                }

                const now = Math.floor(Date.now() / 1000);
                const dataTs = data.ts || 0;
                const age = now - dataTs;
                const statusBadge = $('#connection-status');

                if (age > 300) { // 5 Minuten
                    statusBadge.removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning text-dark').text('Veraltet (' + Math.floor(age/60) + 'm)');
                } else {
                    statusBadge.removeClass('bg-secondary bg-danger bg-warning text-dark').addClass('bg-success text-white').text('Online');
                }

                if (document.getElementById('val-pv')) {
                // Berechnungen
                let homeVal = (data.home_raw || 0);
                if (data.wp > 0) homeVal -= data.wp;
                if (homeVal < 0) homeVal = 0;

                // Basic Values
                $('#val-pv').html(formatWatts(data.pv));
                $('#val-home').html(formatWatts(homeVal));
                $('#val-soc').text(Math.round(data.soc) + '%');

                // --- Details (Strings, Phasen) ---
                if (data.dc0_w !== undefined) {
                    $('#pv-strings-detail').html(`String 1: ${data.dc0_w}W | String 2: ${data.dc1_w}W`).show();
                }
                if (data.ac0_w !== undefined || data.grid_p1 !== undefined) {
                    let details = '';
                    if (data.grid_p1 !== undefined) {
                        details += `<div style="white-space:nowrap; font-size:0.9rem; font-weight:bold; margin-bottom:3px;">${Math.round(data.grid_p1)} | ${Math.round(data.grid_p2)} | ${Math.round(data.grid_p3)} W</div>`;
                    }
                    if (data.ac0_w !== undefined) {
                        details += `<div style="white-space:nowrap; font-size:0.8rem; opacity:0.8;">WR: ${data.ac0_w} | ${data.ac1_w} | ${data.ac2_w} W</div>`;
                    }
                    $('#grid-details').html(details).show();
                } else {
                    $('#grid-details').hide();
                }
                if (data.wb_p1 !== undefined && data.wb > 0) {
                    $('#wb-details').html(`L1: ${data.wb_p1}W | L2: ${data.wb_p2}W | L3: ${data.wb_p3}W`).show();
                } else { $('#wb-details').hide(); }

                // --- Tag/Nacht Icon Logik ---
                const isDay = (typeof isDaytime === 'function') ? isDaytime() : true;
                const iconPvBox = $('#icon-pv-box');
                const iconPv = $('#icon-pv');
                
                if (isDay) {
                    if (iconPv.hasClass('fa-moon')) {
                        iconPv.removeClass('fa-moon').addClass('fa-sun');
                        iconPvBox.removeClass('bg-secondary text-secondary').addClass('bg-warning text-warning');
                    }
                } else {
                    if (iconPv.hasClass('fa-sun')) {
                        iconPv.removeClass('fa-sun').addClass('fa-moon');
                        iconPvBox.removeClass('bg-warning text-warning').addClass('bg-secondary text-secondary');
                    }
                }

                // --- PV Prognose & Soll Logik ---
                const now = new Date();
                const curGmt = now.getUTCHours() + (now.getUTCMinutes() / 60);
                let bestForecast = null; let minDiff = 100;
                if (Array.isArray(FORECAST_DATA)) {
                    for (let d of FORECAST_DATA) {
                        let diff = Math.abs(d.h - curGmt);
                        if (diff < minDiff) { minDiff = diff; bestForecast = d; }
                    }
                }

                const sollVal = (typeof getTheoreticalPower === 'function') ? getTheoreticalPower() : 0;
                const pvDetails = $('#val-pv-details');
                
                // Nur anzeigen wenn Tag (Soll > 0) oder Prognose relevant
                if (SHOW_FORECAST && (sollVal > 10 || (bestForecast && bestForecast.w > 10))) {
                    let fVal = (bestForecast && minDiff < 0.5) ? bestForecast.w : 0;
                    
                    // Formatierung für kleine Anzeige
                    const fmtSmall = (v) => (v >= 1000) ? (v/1000).toFixed(2) + 'k' : Math.round(v);
                    
                    let ratio = (fVal > 0) ? (data.pv / fVal) : 0;
                    let pctStr = (fVal > 0) ? Math.round(ratio * 100) + '%' : '';
                    
                    let fText = fmtSmall(fVal);
                    if (pctStr) fText += ` <span style="font-size:0.7em; opacity:0.7;">(${pctStr})</span>`;

                    $('#val-pv-forecast').html(fText);
                    $('#val-pv-soll').text(fmtSmall(sollVal));
                    
                    // Farb-Logik für Prognose (wie Mobile)
                    if (fVal > 0 && ratio < 0.75) $('#val-pv-forecast').css('color', '#ef4444');
                    else if (fVal > 0 && ratio > 1.25) $('#val-pv-forecast').css('color', '#10b981');
                    else $('#val-pv-forecast').css('color', 'inherit');

                    pvDetails.show();
                } else {
                    pvDetails.hide();
                }
                
                // Wallbox Logic (NEU)
                const wbVal = parseFloat(data.wb) || 0;
                const wbLocked = data.wb_locked === true;
                const wbMode = data.wb_mode !== undefined ? data.wb_mode : '-';

                $('#val-wb').html(formatWatts(wbVal));

                const wbIcon = $('#icon-wb');
                const wbLockOverlay = $('#wb-lock-overlay');

                if (wbVal > 0) {
                    // Wenn Leistung > 0: Blau leuchten (wie Wärmepumpe)
                    wbIcon.removeClass('bg-secondary text-secondary bg-warning text-warning').addClass('bg-info text-info pulsating');
                    // Schloss anzeigen wenn geladen wird? Meistens ja.
                    if (wbLocked) wbLockOverlay.show(); else wbLockOverlay.hide();
                } else if (wbLocked) {
                    // Angesteckt & Verriegelt, aber lädt nicht: Gelb
                    wbIcon.removeClass('bg-secondary text-secondary bg-info text-info pulsating').addClass('bg-warning text-warning');
                    wbLockOverlay.show();
                } else {
                    // Wenn 0: Grau/Standard
                    wbIcon.removeClass('bg-info text-info bg-warning text-warning pulsating').addClass('bg-secondary text-secondary');
                    wbLockOverlay.hide();
                }

                // Phasen ermitteln (Annahme: >10W auf einer Phase bedeutet aktiv)
                let activePhases = 0;
                if (data.wb_p1 > 10) activePhases++;
                if (data.wb_p2 > 10) activePhases++;
                if (data.wb_p3 > 10) activePhases++;
                // Fallback: Wenn Gesamtleistung da ist, aber Phasen 0 (Messfehler?), nimm 1 an
                if (wbVal > 0 && activePhases === 0) activePhases = 1;

                // Status-Text Update
                if (wbVal > 0) {
                    $('#wb-status').text(`Lädt (${activePhases}-ph) | Mode ${wbMode}`);
                } else if (wbLocked) {
                    $('#wb-status').text(`Verbunden | Mode ${wbMode}`);
                } else {
                    $('#wb-status').text('Bereit');
                }
                // Heatpump Logic
                const wpVal = parseFloat(data.wp) || 0;
                $('#val-wp').html(formatWatts(wpVal));
                if (wpVal < 100) {
                    $('#icon-wp').removeClass('bg-info text-info').addClass('bg-secondary text-muted');
                } else {
                    $('#icon-wp').removeClass('bg-secondary text-muted').addClass('bg-info text-info');
                }

                // Battery Logic
                const batVal = Math.round(data.bat);
                const batAbs = Math.abs(batVal);
                $('#val-bat-container').html(formatWatts(batAbs));

                // --- Batterie Zeit-Logik ---
                let batTimeText = '';
                if (BAT_CAPACITY > 0 && batAbs > 50) {
                    let hours = 0;
                    let soc = parseFloat(data.soc) || 0;
                    if (batVal > 0 && soc < 100) { // Laden
                        hours = ((100 - soc) / 100 * BAT_CAPACITY * 1000) / batVal;
                        if (hours > 0 && hours < 48) {
                            let h = Math.floor(hours); let m = Math.round((hours - h) * 60);
                            batTimeText = `(voll: ${h}:${m.toString().padStart(2, '0')}h)`;
                        }
                    } else if (batVal < 0 && soc > 0) { // Entladen
                        hours = (soc / 100 * BAT_CAPACITY * 1000) / batAbs;
                        if (hours > 0 && hours < 48) {
                            let h = Math.floor(hours); let m = Math.round((hours - h) * 60);
                            batTimeText = `(leer: ${h}:${m.toString().padStart(2, '0')}h)`;
                        }
                    }
                }
                $('#val-bat-time').text(batTimeText);
                
                const batIcon = $('#icon-bat');
                const batContainer = $('#val-bat-container');
                
                if (batVal > 0) { // Charging
                    batIcon.removeClass('text-success text-danger text-muted').addClass('text-success pulsating');
                    batContainer.removeClass('text-success text-danger text-muted').addClass('text-success');
                } else if (batVal < 0) { // Discharging
                    batIcon.removeClass('text-success text-danger text-muted').addClass('text-danger pulsating');
                    batContainer.removeClass('text-success text-danger text-muted').addClass('text-danger');
                } else {
                    batIcon.removeClass('text-success text-danger pulsating').addClass('text-muted');
                    batContainer.removeClass('text-success text-danger').addClass('text-muted');
                }

                // Grid Logic
                const gridVal = Math.round(data.grid);
                const gridAbs = Math.abs(gridVal);
                $('#val-grid-container').html(formatWatts(gridAbs));
                
                const gridIcon = $('#icon-grid');
                const gridContainer = $('#val-grid-container');

                if (gridVal > 0) { // Import (Bezug) -> Rot
                    gridIcon.removeClass('text-secondary text-success text-danger').addClass('text-danger');
                    gridContainer.removeClass('text-body text-success text-danger').addClass('text-danger');
                } else if (gridVal < 0) { // Export (Einspeisung) -> Grün
                    gridIcon.removeClass('text-secondary text-success text-danger').addClass('text-success');
                    gridContainer.removeClass('text-body text-success text-danger').addClass('text-success');
                } else {
                    gridIcon.removeClass('text-success text-danger').addClass('text-secondary');
                    gridContainer.removeClass('text-success text-danger').addClass('text-body');
                }

                // Price Logic
                if (data.price_ct !== undefined && data.price_ct !== null) {
                    $('#val-price').html(data.price_ct.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '<span class="val-unit">ct/kWh</span>');
                    
                    if (data.price_min_ct !== undefined && data.price_min_ct !== null) {
                        $('#val-price-min').text(data.price_min_ct.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    }
                    if (data.price_max_ct !== undefined && data.price_max_ct !== null) {
                        $('#val-price-max').text(data.price_max_ct.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                    }

                    // Dynamic bar visualization (Min-Max range)
                    let min = (data.price_min_ct !== undefined && data.price_min_ct !== null) ? data.price_min_ct : 0;
                    let max = (data.price_max_ct !== undefined && data.price_max_ct !== null) ? data.price_max_ct : 50;
                    let current = data.price_ct;
                    let pct = 50; // Default Mitte
                    
                    if (max > min) pct = ((current - min) / (max - min)) * 100;
                    
                    if (pct < 0) pct = 0; if (pct > 100) pct = 100;
                    
                    const bar = $('#price-bar');
                    bar.css('width', pct + '%');
                    
                    bar.removeClass('bg-info bg-success bg-warning bg-danger');
                    if (data.price_level === 'cheap') {
                        bar.addClass('bg-success');
                    } else if (data.price_level === 'expensive') {
                        bar.addClass('bg-danger');
                    } else {
                        bar.addClass('bg-warning');
                    }

                    // Price Trend Logic
                    const prices = data.prices || [];
                    const priceStartHour = data.price_start_hour;
                    const priceInterval = data.price_interval || 1.0;
                    let trendIcon = '<i class="fas fa-minus"></i>';

                    if (prices.length > 1 && priceStartHour !== null) {
                        const now = new Date();
                        const curGmtDec = now.getUTCHours() + (now.getUTCMinutes() / 60);
                        let hourDiff = curGmtDec - priceStartHour;
                        if (hourDiff < 0) hourDiff += 24; // Handle day change
                        
                        let idx = Math.floor(hourDiff / priceInterval);
                        
                        if (prices[idx] !== undefined && prices[idx+1] !== undefined) {
                            const diff = prices[idx+1] - prices[idx];
                            if (diff > 0.1) {
                                trendIcon = '<i class="fas fa-arrow-trend-up text-danger" title="Preis steigend"></i>';
                            } else if (diff < -0.1) {
                                trendIcon = '<i class="fas fa-arrow-trend-down text-success" title="Preis fallend"></i>';
                            } else {
                                trendIcon = '<i class="fas fa-arrow-right text-info" title="Preis stabil"></i>';
                            }
                        }
                    }
                    $('#price-trend').html(trendIcon);
                }

                // Diagramm-Details aktualisieren (analog zu mobile.php)
                const detailsEl = document.getElementById('diagramDetails');
                if (detailsEl) {
                    let content = '';
                    if (CURRENT_VIEW === 'pv') {
                        if (data.dc0_w !== undefined) content = `Gesamt: ${data.pv}W | String 1: ${data.dc0_w}W (${data.dc0_v}V) | String 2: ${data.dc1_w}W (${data.dc1_v}V)`;
                    } else if (CURRENT_VIEW === 'grid') {
                        if (data.grid_p1 !== undefined) content = `Netz: ${data.grid}W (L1: ${data.grid_p1} | L2: ${data.grid_p2} | L3: ${data.grid_p3})`;
                        if (data.ac0_w !== undefined) {
                            const wrTotal = (data.ac0_w || 0) + (data.ac1_w || 0) + (data.ac2_w || 0);
                            content += ` | WR: ${wrTotal}W (L1: ${data.ac0_w} | L2: ${data.ac1_w} | L3: ${data.ac2_w})`;
                        }
                    } else if (CURRENT_VIEW === 'wb') {
                        if (data.wb_p1 !== undefined) content = `Wallbox L1: ${data.wb_p1}W | L2: ${data.wb_p2}W | L3: ${data.wb_p3}W`;
                    } else if (CURRENT_VIEW === 'wp') {
                        if (data.wp !== undefined) content = `Wärmepumpe: ${data.wp}W`;
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
                }

            }).fail(function() {
                $('#connection-status').removeClass('bg-secondary bg-success').addClass('bg-danger').text('Offline');
            });
        }

        function updateChart(hours, btn) {
            if(btn) {
                $('.btn-group-custom .btn').removeClass('active');
                $(btn).addClass('active');
                // Reset Dropdown
                $('#live-controls select').val('');
            }
            
            const iframe = document.getElementById('chart-frame');
            if(iframe) iframe.style.opacity = '0.5';

            // Call PHP to generate new chart (mit aktuellem View)
            $.get('index.php?action=run_live_history&hours=' + hours + '&view=' + CURRENT_VIEW + '&dark=' + (DARK_MODE ? '1' : '0'), function() {
                // Poll for completion
                const checkInterval = setInterval(function() {
                    $.getJSON('index.php?action=run_live_history&mode=status', function(status) {
                        if (!status.running) {
                            clearInterval(checkInterval);
                            if(iframe) {
                                iframe.src = 'live_diagramm.html?t=' + new Date().getTime();
                                iframe.style.opacity = '1';
                            }
                        }
                    });
                }, 1000);
            });
        }

        function updateChartHistory(file) {
            if (!file) {
                // Zurück zu Live (Standard 6h)
                updateChart(6, document.querySelector('.btn-group-custom .btn:first-child'));
                return;
            }
            
            // Buttons deaktivieren
            $('.btn-group-custom .btn').removeClass('active');
            
            const iframe = document.getElementById('chart-frame');
            if(iframe) iframe.style.opacity = '0.5';

            $.get('index.php?action=run_live_history&file=' + encodeURIComponent(file) + '&dark=' + (DARK_MODE ? '1' : '0'), function() {
                const checkInterval = setInterval(function() {
                    $.getJSON('index.php?action=run_live_history&mode=status', function(status) {
                        if (!status.running) {
                            clearInterval(checkInterval);
                            if(iframe) {
                                iframe.src = 'live_diagramm.html?t=' + new Date().getTime();
                                iframe.style.opacity = '1';
                            }
                        }
                    });
                }, 1000);
            });
        }

        function switchChartMode(mode, view = 'normal') {
            // Dropdown synchronisieren (falls Aufruf über Button kam)
            document.getElementById('chart-mode-select').value = mode;
            CURRENT_VIEW = view;
            
            const iframe = document.getElementById('chart-frame');
            const title = document.getElementById('chart-title');
            const liveControls = document.getElementById('live-controls');
            const archiveSelect = document.getElementById('archive-select');

            if (mode === 'forecast') {
                title.innerHTML = '<i class="fas fa-chart-line me-2 text-secondary"></i>SoC Prognose';
                if (liveControls) liveControls.style.display = 'none';
                archiveSelect.style.display = 'none';
                iframe.src = 'diagramm.html?t=' + new Date().getTime();
                // Trigger update check with correct theme
                $.get('index.php?action=run_now&dark=' + (DARK_MODE ? '1' : '0'));
            } else if (mode === 'live') {
                let titleText = 'Leistungsverlauf';
                if (view === 'pv') titleText = 'PV Strings';
                if (view === 'grid') titleText = 'Netz & WR Phasen';
                if (view === 'bat') titleText = 'Batterie Details';
                if (view === 'wb') titleText = 'Wallbox Phasen';
                if (view === 'wp') titleText = 'Wärmepumpe Details';
                
                title.innerHTML = '<i class="fas fa-chart-area me-2 text-secondary"></i>' + titleText;
                if (liveControls) liveControls.style.display = 'flex';
                archiveSelect.style.display = 'none';
                iframe.src = 'live_diagramm.html?t=' + new Date().getTime();
                
                // Sofortiges Update anstoßen, damit die neue Ansicht generiert wird
                let hours = 6;
                const activeBtn = document.querySelector('#live-controls .btn.active');
                if (activeBtn) hours = parseInt(activeBtn.innerText);
                updateChart(hours, null);
                fetchData(); // Details sofort aktualisieren

            } else if (mode === 'archive') {
                title.innerHTML = '<i class="fas fa-history me-2 text-secondary"></i>Archiv';
                if (liveControls) liveControls.style.display = 'none';
                archiveSelect.style.display = 'inline-block';
                // Wenn noch keine Datei gewählt, erste wählen oder leer lassen
                if (archiveSelect.options.length > 1 && archiveSelect.selectedIndex <= 0) {
                   archiveSelect.selectedIndex = 1; // Erste echte Datei wählen
                   loadArchive(archiveSelect.value);
                } else if (archiveSelect.value) {
                   loadArchive(archiveSelect.value);
                }
            }
        }

        function loadArchive(file) {
            const iframe = document.getElementById('chart-frame');
            iframe.src = 'index.php?action=archiv_diagram&file=' + encodeURIComponent(file) + '&ts=' + new Date().getTime() + '&dark=' + (DARK_MODE ? '1' : '0');
        }

        /* Veraltet, ersetzt durch switchChartMode
        function toggleForecast(btn) {
            const iframe = document.getElementById('chart-frame');
            const title = document.getElementById('chart-title');
            const controls = document.getElementById('chart-controls');
            const btnText = document.getElementById('btn-forecast-text');
            
            if (iframe.src.includes('live_diagramm.html')) {
                // Switch to Forecast
                iframe.src = 'diagramm.html?t=' + new Date().getTime();
                title.innerHTML = '<i class="fas fa-chart-line me-2 text-secondary"></i>SoC Prognose';
                controls.style.display = 'none';
                btnText.innerText = 'Leistungsverlauf';
                // Trigger update for forecast if needed
                $.get('run_now.php'); 
            } else {
                // Switch back to History
                iframe.src = 'live_diagramm.html?t=' + new Date().getTime();
                title.innerHTML = '<i class="fas fa-chart-area me-2 text-secondary"></i>Leistungsverlauf';
                controls.style.display = 'inline-flex';
                btnText.innerText = 'SoC Prognose';
            }
        }
        */

        function refreshData(isAuto = false) {
            fetchData();
            const iframe = document.getElementById('chart-frame');
            if (!iframe) return;

            const btn = document.getElementById('main-refresh-btn');
            // Speichere Originaltext, falls noch nicht geschehen
            if (!btn.dataset.originalHtml) {
                btn.dataset.originalHtml = btn.innerHTML;
            }
            const originalHtml = btn.dataset.originalHtml;

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            const startTime = Date.now();
            const timeoutMs = 30000; // 30 Sekunden Timeout

            const handleTimeout = (interval) => {
                if (Date.now() - startTime > timeoutMs) {
                    clearInterval(interval);
                    btn.innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i>';
                    if (iframe) iframe.style.opacity = '1'; // Restore opacity on timeout
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }, 3000);
                    return true;
                }
                return false;
            };

            // FIX: Reihenfolge geändert! Zuerst auf LIVE prüfen.
            // Grund: "live_diagramm.html" enthält den String "diagramm.html", wodurch die alte Prüfung
            // fälschlicherweise immer im Prognose-Block landete.
            
            const modeValue = document.getElementById('chart-mode-select').value;
            const isLive = iframe.src.includes('live_diagramm.html') || modeValue === 'live';
            const isForecast = !isLive && (iframe.src.includes('diagramm.html') || modeValue === 'forecast');

            if (isLive) {
                // --- LIVE VERLAUF AKTUALISIEREN ---
                iframe.style.opacity = '0.5';
                
                let hours = 6;
                const activeBtn = document.querySelector('#live-controls .btn.active');
                if (activeBtn) hours = parseInt(activeBtn.innerText);

                $.get('index.php?action=run_live_history&hours=' + hours + '&view=' + CURRENT_VIEW + '&dark=' + (DARK_MODE ? '1' : '0'), function() {
                    const checkInterval = setInterval(function() {
                        if (handleTimeout(checkInterval)) return; 

                        $.getJSON('index.php?action=run_live_history&mode=status', function(status) {
                            if (!status.running) {
                                clearInterval(checkInterval);
                                iframe.src = 'live_diagramm.html?t=' + new Date().getTime();
                                iframe.style.opacity = '1';
                                btn.disabled = false;
                                btn.innerHTML = originalHtml;
                            }
                        });
                    }, 1000);
                });

            } else if (isForecast) {
                // --- SOC PROGNOSE AKTUALISIEREN ---
                let url = 'index.php?action=run_now&dark=' + (DARK_MODE ? '1' : '0');
                if (isAuto) url += '&auto=1';

                $.get(url, function(response) {
                    if (response.trim() === 'skipped') {
                        // Update nicht nötig -> Spinner weg, fertig.
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                        return;
                    }

                    const checkInterval = setInterval(function() {
                        if (handleTimeout(checkInterval)) return;

                        $.getJSON('index.php?action=status', function(status) {
                            if (!status.running) {
                                clearInterval(checkInterval);
                                // Nur neu laden, wenn kein Fehler vorliegt (verhindert Flackern bei Fehlern)
                                if (!status.error) {
                                    iframe.src = iframe.src.split('?')[0] + '?t=' + new Date().getTime();
                                }
                                btn.disabled = false;
                                btn.innerHTML = originalHtml;
                            }
                        });
                    }, 1000);
                });
            } else {
                // Archiv Modus - hier macht "Aktualisieren" weniger Sinn, aber wir können das Iframe neu laden
                iframe.src = iframe.src;
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        }

        function startSystemUpdate() {
            const btn = document.getElementById('btn-update-config'); // Button auf Config-Seite
            const log = document.getElementById('update-log');
            const spinner = document.getElementById('update-spinner');
            const closeBtn = document.getElementById('update-close-btn');
            const finishBtn = document.getElementById('update-finish-btn');

            let originalContent = '';
            if (btn) {
                originalContent = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i>';
                btn.disabled = true;
            }
            
            fetch('index.php?action=check_update&force_check=1')
            .then(r => r.json())
            .then(data => {
                if (btn) {
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                }

                let missing = data.missing || 0;

                // UI sofort aktualisieren, wenn Updates gefunden wurden
                if (data.success && missing > 0) {
                    const bNav = document.getElementById('update-badge-nav');
                    const bBtn = document.getElementById('update-badge-btn');
                    const bConf = document.getElementById('update-badge-config');
                    const btnConf = document.getElementById('btn-update-config');

                    if(bNav) bNav.style.display = 'inline-block';
                    if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = missing + ' Update(s)'; }
                    if(bConf) { bConf.style.display = 'inline-block'; bConf.innerText = missing; }
                    if(btnConf) {
                        btnConf.classList.remove('btn-outline-warning');
                        btnConf.classList.add('btn-warning');
                    }
                }

                let force = false;
                let discard = false;
                let proceed = false;

                if (data.success && missing > 0) {
                    if (confirm(`Es sind ${missing} neue Updates verfügbar.\nUpdate jetzt durchführen?`)) {
                        proceed = true;
                        // Standard: Änderungen behalten (stash), außer User will explizit Reset (hier vereinfacht)
                    }
                } else {
                    alert("Das System ist auf dem neuesten Stand.");
                    return;
                }

                if (!proceed) return;

                return fetch('index.php?action=prepare_update&force=' + force + '&discard=' + discard);
            })
            .then(response => {
                if (!response) return;

                const modal = new bootstrap.Modal(document.getElementById('updateModal'));
                modal.show();
                
                // Reset UI
                log.innerText = "Starte Anfrage...\n";
                spinner.className = "fas fa-sync fa-spin me-2";
                closeBtn.style.display = 'none';
                finishBtn.disabled = true;
                finishBtn.innerText = "Schließen";
                
                // Start Request
                fetch('index.php?action=run_update&mode=start&t=' + Date.now())
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
            })
            .catch(err => {
                if (btn) {
                    btn.innerHTML = originalContent;
                    btn.disabled = false;
                }
                alert("Fehler: " + err);
            });

            function pollUpdate() {
                let tick = 0;
                const interval = setInterval(() => {
                    tick++;
                    // Zeitstempel anhängen um Cloudflare-Cache zu umgehen
                    fetch('index.php?action=run_update&mode=poll&t=' + Date.now())
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
                                // Kleiner Delay, damit der letzte Log-Eintrag sicher da ist
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

        // Init
        fetchData();
        setInterval(fetchData, 5000);
        
        // Diagramm beim Laden aktualisieren, um veraltete Daten/falschen Modus zu vermeiden
        $(document).ready(function() { refreshData(true); });

        // NEU: Automatisches Diagramm-Update (Intervall aus Config)
        const DIAGRAM_MODE = '<?= $diagramMode ?>';
        if (DIAGRAM_MODE !== 'manual') {
            const DIAGRAM_INTERVAL = <?= max(60000, $diagramInterval * 60 * 1000) ?>;
            setInterval(function() { refreshData(true); }, DIAGRAM_INTERVAL);
        }

        // Update Check (Initial + Periodisch)
        setTimeout(() => {
            fetch('index.php?action=check_update').then(r=>r.json()).then(d => {
                if(d.success && d.missing > 0) {
                    const bNav = document.getElementById('update-badge-nav');
                    const bBtn = document.getElementById('update-badge-btn');
                    const bConf = document.getElementById('update-badge-config');
                    const btnConf = document.getElementById('btn-update-config');

                    if(bNav) bNav.style.display = 'inline-block';
                    if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = d.missing + ' Update(s)'; }
                    if(bConf) { bConf.style.display = 'inline-block'; bConf.innerText = d.missing; }
                    if(btnConf) {
                        btnConf.classList.remove('btn-outline-warning');
                        btnConf.classList.add('btn-warning');
                    }
                }
            });
        }, 2000);
        
        // Periodische Prüfung alle 60 Minuten (Backend cached für 4h)
        setInterval(() => {
            fetch('index.php?action=check_update').then(r=>r.json()).then(d => {
                const bNav = document.getElementById('update-badge-nav');
                const bBtn = document.getElementById('update-badge-btn');
                const bConf = document.getElementById('update-badge-config');
                
                if(d.success && d.missing > 0) {
                    if(bNav) bNav.style.display = 'inline-block';
                    if(bBtn) { bBtn.style.display = 'inline-block'; bBtn.innerText = d.missing + ' Update(s)'; }
                    if(bConf) { bConf.style.display = 'inline-block'; bConf.innerText = d.missing; }
                } else {
                    if(bNav) bNav.style.display = 'none';
                    if(bBtn) bBtn.style.display = 'none';
                    if(bConf) bConf.style.display = 'none';
                }
            });
        }, 3600000); // 1 Stunde

        function restartService() {
            if (!confirm('Möchtest du den E3DC-Control Service wirklich neu starten?')) return;
            
            fetch('index.php?action=restart_service')
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert("✓ Service wird neu gestartet.\nDas Web-Interface ist kurzzeitig eventuell nicht erreichbar.");
                    } else {
                        alert("✗ Fehler: " + data.message);
                    }
                })
                .catch(err => alert("Netzwerkfehler: " + err));
        }

        // Watchdog Status prüfen
        function checkWatchdog() {
            $.getJSON('index.php?action=watchdog_status', function(data) {
                const badge = $('#watchdog-badge');
                if (data.installed) {
                    badge.show();
                    badge.attr('title', data.message);
                    if (data.warning) {
                        badge.removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning text-dark');
                    } else if (data.active) {
                        badge.removeClass('bg-secondary bg-danger bg-warning text-dark').addClass('bg-success text-white');
                    } else {
                        badge.removeClass('bg-secondary bg-success bg-warning text-dark').addClass('bg-danger text-white');
                    }
                } else {
                    badge.hide();
                }
            });
        }
        setInterval(checkWatchdog, 10000); // Alle 10 Sek prüfen
        checkWatchdog();

        function showWatchdogLog() {
            const modal = new bootstrap.Modal(document.getElementById('watchdogModal'));
            modal.show();
            $('#watchdog-log-content').text('Lade Protokoll...');
            
            $.get('index.php?action=watchdog_log', function(data) {
                $('#watchdog-log-content').text(data);
            }).fail(function() { $('#watchdog-log-content').text('Fehler beim Laden.'); });
        }

        // Interaktive Online-Anzeige
        window.handleConnectionClick = function() {
            const badge = document.getElementById('connection-status');
            // Check auf Klassen (Bootstrap Farben)
            const isOffline = badge.classList.contains('bg-danger') || badge.classList.contains('bg-warning');
            
            if (isOffline) {
                if (confirm("Verbindungsprobleme erkannt.\nMöchtest du den E3DC-Service neu starten?")) {
                    restartService();
                } else {
                    badge.innerText = "Lade...";
                    fetchData();
                }
            } else {
                badge.innerText = "Aktualisiere...";
                fetchData();
            }
        };
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