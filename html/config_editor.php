<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$paths = getInstallPaths();
$install_user = $paths['install_user'];
$install_path = rtrim($paths['install_path'], '/') . '/'; 

if (!isset($_SESSION['deleted'])) {
    $_SESSION['deleted'] = [];
}

$config_file_path = $install_path . 'e3dc.config.txt';
$message = '';

/* --- DEINE DEFAULTS & TOOLTIPS --- */
$defaults = [
    "wallbox" => "true", "wbmode" => "4", "wbminlade" => "1200", "wbminSoC" => "70",
    "wbmaxladestrom" => "32", "wbminladestrom" => "6", "wbhour" => "0", "wbvon" => "20", "wbbis" => "6", "wbcostpowers" => "7.2, 11.0, 22.0",
    "speichergroesse" => "35", "speicherev" => "80", "speichereta" => "0.97", "unload" => "65",
    "ladeschwelle" => "70", "ladeende" => "85", "ladeende2" => "91", "ladeende2rampe" => "2",
    "maximumladeleistung" => "12500", "awattar" => "1", "awmwst" => "19", "awnebenkosten" => "15.915",
    "awaufschlag" => "12", "awland" => "DE", "awreserve" => "20", "awsimulation" => "1",
    "wp" => "true", "wpheizlast" => "18", "wpheizgrenze" => "13", "wpleistung" => "20",
    "wpmin" => "0.5", "wpmax" => "4.7", "wpehz" => "12 kW", "wpzwe" => "-99", "wpzwepvon" => "25",
    "luxtronik" => "0", "luxtronik_ip" => "192.168.178.88", "wrleistung" => "11700", "hoehe" => "48.60442", "laenge" => "13.41513", 
    "forecast1" => "40/-50/15.4", "forecastsoc" => "1.2", "forecastconsumption" => "1", "forecastreserve" => "5", "show_forecast" => "1", "darkmode" => "1", "pvatmosphere" => "0.7",
    "check_updates" => "1", "stop" => "0"
];

$tooltips = [
    "wallbox" => "Aktiviert oder deaktiviert die Wallbox-Steuerung.",
    "wbmode" => "0 keine Steuerung\n1 Laden nur bei Abregelung\n2 Überschussladen\n3 Laden, Priorität Hausspeicher\n4 Hausspeicher bevorzugt laden\n5..8 Leitwert ist wbminlade\n9 Die Wallbox hat Prorität.",
    "wbminlade" => "Minimale Ladeleistung der Wallbox in Watt.",
    "wbminsoc" => "Unterer SoC-Grenzwert für Entladen über die Wallbox.",
    "wbmaxladestrom" => "Maximaler Ladestrom der Wallbox in Ampere.",
    "wbminladestrom" => "Minimaler Ladestrom der Wallbox in Ampere.",
    "wbhour" => "Stunden, die im günstigsten Preisfenster geladen werden sollen.",
    "wbvon" => "Startzeit des Ladefensters.",
    "wbbis" => "Endzeit des Ladefensters.",
    "wbcostpowers" => "Komma-getrennte Ladeleistungen (in kW) für die Kostenschätzung in der Wallbox-Ansicht.",
    "speichergroesse" => "Nutzbare Kapazität des Speichers in kWh.",
    "speicherev" => "Eigenverbrauch des Wechselrichters in Watt.",
    "speichereta" => "Wirkungsgrad des Speichers.",
    "unload" => "Bis zu diesem SoC wird morgens entladen.",
    "ladeschwelle" => "Bis zu diesem SoC wird morgens maximal geladen.",
    "ladeende" => "Erster Ladeendpunkt (SoC).",
    "ladeende2" => "Zweiter Ladeendpunkt (SoC).",
    "ladeende2rampe" => "Beschleunigung der Ladung zwischen Ladeende1 und Ladeende2.",
    "maximumladeleistung" => "Maximale Ladeleistung des Speichers in Watt.",
    "awattar" => "0 = aus, 1 = Netzladen aktiv, 2 = ohne Netzladen.",
    "awmwst" => "Mehrwertsteuer in Prozent.",
    "awnebenkosten" => "Nebenkosten pro kWh (brutto).",
    "awaufschlag" => "Prozentualer Aufschlag auf den Börsenpreis.",
    "awland" => "Land für Preisberechnung.",
    "awreserve" => "Reserve-SoC für Verbrauchsprognose.",
    "awsimulation" => "Zusätzliche PV-Spalte in Simulation aktivieren.",
    "wp" => "Wärmepumpe vorhanden = true.",
    "wpheizlast" => "Heizlast der Wärmepumpe.",
    "wpheizgrenze" => "Temperaturgrenze für Heizbetrieb.",
    "wpleistung" => "Elektrische Leistung der WP.",
    "wpmin" => "Minimale elektrische Leistung.",
    "wpmax" => "Maximale elektrische Leistung.",
    "wpehz" => "Leistung der elektrischen Zusatzheizung.",
    "wpzwe" => "ZWE-Modus.",
    "wpzwepvon" => "Temperaturgrenze für ZWE.",
    "luxtronik" => "Aktiviert das Luxtronik-Modul (1=an, 0=aus).",
    "luxtronik_ip" => "IP-Adresse der Luxtronik Wärmepumpe.",
    "hoehe" => "Geografische Höhe für Sonnenstandsberechnung.",
    "laenge" => "Geografische Länge für Sonnenstandsberechnung.",
    "forecast1" => "Dachneigung/Azimuth/kWp.",
    "forecast2" => "Dachneigung/Azimuth/kWp für den 2. PV-String.",
    "forecast3" => "Dachneigung/Azimuth/kWp für den 3. PV-String.",
    "forecast4" => "Dachneigung/Azimuth/kWp für den 4. PV-String.",
    "forecast5" => "Dachneigung/Azimuth/kWp für den 5. PV-String.",
    "forecastsoc" => "Faktor für beschleunigtes Laden.",
    "forecastconsumption" => "Faktor für Verbrauchsprognose.",
    "forecastreserve" => "Reserve in Prozent.",
    "pvatmosphere" => "Atmosphärische Transmission (0.0 - 1.0). Standard 0.7. Höher = klarer Himmel/mehr Leistung.",
    "show_forecast" => "Zeigt Prognose- und Sollwerte im Dashboard an (1=an, 0=aus).",
    "darkmode" => "Darstellungsmodus (1=Dunkel, 0=Hell, auto=System).",
    "check_updates" => "Prüft im Hintergrund regelmäßig auf verfügbare Updates (1=an, 0=aus).",
    "wbtest" => "Testmodus für die Wallbox-Kommunikation.",
    "wrleistung" => "Maximale Wechselrichterleistung in Watt.",
    "einspeiselimit" => "Maximal zulässige Netzeinspeisung in Watt.",
    "powerfaktor" => "Korrekturfaktor für die Leistungswerte.",
    "rb" => "Untere SoC-Schwelle für die Entladesperre.",
    "re" => "Obere SoC-Schwelle für die Entladesperre.",
    "le" => "Lade-Ende Schwelle (SoC).",
    "shellyem_ip" => "IP-Adresse des Shelly EM zur Leistungsmessung.",
    "openmeteo" => "Wetterprognose über Open-Meteo beziehen (true/false).",
    "server_ip" => "IP-Adresse des E3DC S10 Hauskraftwerks.",
    "server_port" => "Netzwerk-Port für RSCP (Standard: 5033).",
    "e3dc_user" => "E3DC-Portal Benutzername.",
    "e3dc_password" => "E3DC-Portal Passwort.",
    "aes_password" => "RSCP-Passwort (am Gerät vergeben).",
    "debug" => "Debug-Modus aktivieren (0=aus, true=ein).",
    "logfile" => "Pfad zur Logdatei.",
    "ext1" => "Externer Zähler 1.",
    "ext2" => "Externer Zähler 2.",
    "stop" => "Beendet die Bildschirmausgabe (0=aus, 1=ein)."
];

/* --- LOGIK (DATEI LESEN) --- */
function readConfig($file_path) {
    if (!file_exists($file_path)) return [];
    $lines = file($file_path, FILE_IGNORE_NEW_LINES);
    $config = [];
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $commented = false;
        if (strpos(ltrim($line), '#') === 0) { $commented = true; $line = ltrim($line, '#'); }
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $k_lower = strtolower(trim($key));
        if (!isset($config[$k_lower])) {
            $config[$k_lower] = ['value' => trim($value), 'commented' => $commented];
        }
    }
    return $config;
}

$config = readConfig($config_file_path);

/* --- POST LOGIK --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['stop_set'])) {
        $val = $_POST['stop_set'] === '1' ? '1' : '0';
        $config['stop'] = ['value' => $val, 'commented' => false];
    }
    if (isset($_POST['save_all']) && isset($_POST['values'])) {
        $comments = $_POST['comments'] ?? [];
        foreach ($_POST['values'] as $k => $v) {
            $k_lower = strtolower($k);
            if (isset($config[$k_lower])) {
                $config[$k_lower]['value'] = trim($v);
                $config[$k_lower]['commented'] = in_array($k, $comments);
            }
        }
    }
    if (!empty($_POST['new_key'])) {
        $nk = strtolower(trim($_POST['new_key'])); 
        if (isset($config[$nk])) {
            $message = "<div class='alert alert-warning py-2 border-0 mb-3 mx-2'>⚠ Variable '" . htmlspecialchars($nk) . "' existiert bereits und wurde nicht neu angelegt.</div>";
        } else {
            $nv = trim($_POST['new_value'] ?? '');
            $config[$nk] = ['value' => $nv, 'commented' => false];
        }
    }
    if (isset($_POST['delete_key'])) {
        $dk = strtolower($_POST['delete_key']); 
        $_SESSION['deleted'][$dk] = $config[$dk]; 
        unset($config[$dk]);
    }
    if (isset($_POST['restore_key'])) {
        $rk = strtolower($_POST['restore_key']); 
        $config[$rk] = $_SESSION['deleted'][$rk]; 
        unset($_SESSION['deleted'][$rk]);
    }

    $content = "";
    foreach ($config as $key => $data) {
        $content .= ($data['commented'] ? "# " : "") . $key . " = " . $data['value'] . "\n";
    }
    file_put_contents($config_file_path, $content, LOCK_EX);
    $message = "<div class='alert alert-success py-2 border-0 mb-3 mx-2'>✓ Konfiguration gespeichert.</div>";
    $config = readConfig($config_file_path);
}

/* --- DEINE INDIVIDUELLE GRUPPIERUNG --- */
$groups = [
    "Wallbox" => ["wallbox","wbmode","wbminlade","wbminsoc","wbmaxladestrom","wbminladestrom","wbhour","wbvon","wbbis","wbtest"],
    "Speicher / Ladesteuerung" => ["speichergroesse","speicherev","speichereta","unload","wrleistung","einspeiselimit","ladeschwelle","ladeende","ladeende2","ladeende2rampe","maximumladeleistung","powerfaktor","rb","re","le"],
    "Awattar / Preise" => ["awattar","awmwst","awnebenkosten","awaufschlag","awland","awreserve","awsimulation"],
    "Wärmepumpe" => ["wp","wpheizlast","wpheizgrenze","wpleistung","wpmin","wpmax","wpehz","wpzwe","wpzwepvon","shellyem_ip"],
    "Standort / Wetter" => ["openmeteo","hoehe","laenge","forecast1","forecast2","forecast3","forecast4","forecast5","forecastsoc","forecastconsumption","forecastreserve"],
    "Webansicht / Dashboard" => ["show_forecast","wbcostpowers","darkmode","pvatmosphere","luxtronik","luxtronik_ip","check_updates"],
    "System" => ["server_ip","server_port","e3dc_user","e3dc_password","aes_password","debug","logfile","wurzelzaehler","ext1","ext2","wrsteuerung"],
    "Sonstiges" => [] 
];
?>
<?php
// Tooltip-Map für case-insensitive Suche vorbereiten
$tooltipMap = array_change_key_case($tooltips, CASE_LOWER);
?>

<style>
    .config-card { border-radius: 16px; margin-bottom: 12px; overflow: hidden; }
    .config-header { color: #22d3ee; font-weight: bold; padding: 12px 15px; border-bottom: 1px solid var(--bs-border-color); cursor: pointer; display: flex; justify-content: space-between; align-items: center; text-decoration: none; }
    .config-item { padding: 10px 15px; border-bottom: 1px solid var(--bs-border-color); }
    .config-item:last-child { border-bottom: none; }
    
    /* Verbesserte Tooltips für Mobile */
    .config-label { font-family: monospace; color: var(--bs-secondary-color); font-size: 0.8rem; margin-bottom: 4px; display: inline-block; border-bottom: 1px dotted var(--bs-secondary-color); cursor: help; position: relative; }
    .config-label[data-tooltip]:active::after, 
    .config-label[data-tooltip]:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        bottom: 130%;
        left: 0;
        background: var(--bs-body-color);
        color: var(--bs-body-bg);
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 0.75rem;
        width: 280px;
        max-width: 85vw;
        z-index: 1000;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
        white-space: pre-wrap;
        line-height: 1.4;
    }
    
    .config-input { border-radius: 8px; font-size: 0.9rem; padding: 6px 10px; }
    
    #configSearch { border-radius: 8px; padding: 8px 12px; width: 100%; margin-bottom: 10px; }
    .ctrl-btns { display: flex; gap: 5px; margin-bottom: 15px; }
</style>

<div class="px-2 pb-5">
    <h5 class="fw-bold mb-3 text-body px-1">Konfiguration Editor</h5>
    <?= $message ?>

    <div class="px-1">
        <input type="text" id="configSearch" class="form-control" placeholder="🔍 Variable suchen..." onkeyup="filterConfig()">
        <div class="ctrl-btns">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill flex-grow-1 border-2 fw-bold" onclick="toggleAllGroups(false)">Alle schließen</button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill flex-grow-1 border-2 fw-bold" onclick="toggleAllGroups(true)">Alle öffnen</button>
        </div>
    </div>

    <form method="POST">
        <div class="d-flex justify-content-between align-items-center mb-3 px-1">
            <span class="text-muted small">Tippe Namen für Hilfe</span>
            <button type="submit" name="save_all" class="btn btn-sm btn-info rounded-pill px-3 fw-bold text-dark shadow">💾 Speichern</button>
        </div>

        <?php 
        $assigned = [];
        $allGroupKeys = array_merge(...array_values($groups));

        foreach ($groups as $title => $keys): 
            $items = [];
            foreach ($keys as $k) {
                if (isset($config[$k])) {
                    $items[$k] = $config[$k];
                    $assigned[] = $k;
                }
            }
            if (empty($items)) continue;
        ?>
        <details class="card config-card config-group-el">
            <summary class="config-header"><?= $title ?> <i class="fas fa-chevron-down small"></i></summary>
            <div class="p-1">
                <?php foreach ($items as $key => $data): ?>
                    <div class="config-item" data-search-key="<?= $key ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="config-label" data-tooltip="<?= htmlspecialchars($tooltipMap[$key] ?? 'Keine Beschreibung.') ?>"><?= $key ?></label>
                            <div class="d-flex align-items-center gap-2">
                                <span class="small text-muted">#</span>
                                <input type="checkbox" name="comments[]" value="<?= $key ?>" class="form-check-input" style="accent-color: #22d3ee;" <?= $data['commented'] ? 'checked':'' ?>>
                            </div>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" name="values[<?= $key ?>]" class="form-control config-input" 
                                   value="<?= htmlspecialchars($data['value']) ?>" 
                                   placeholder="Standard: <?= $defaults[$key] ?? '' ?>">
                            <button type="submit" name="delete_key" value="<?= $key ?>" class="btn btn-outline-danger border-secondary border-2"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endforeach; ?>

        <?php $rest = array_diff_key($config, array_flip($allGroupKeys), ['stop' => '']);
        if (!empty($rest)): ?>
        <details class="card config-card config-group-el">
            <summary class="config-header">Weitere Parameter <i class="fas fa-chevron-down small"></i></summary>
            <div class="p-1">
                <?php foreach ($rest as $key => $data): ?>
                    <div class="config-item" data-search-key="<?= strtolower($key) ?>">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="config-label" data-tooltip="<?= htmlspecialchars($tooltipMap[strtolower($key)] ?? 'Keine Beschreibung.') ?>"><?= $key ?></label>
                            <input type="checkbox" name="comments[]" value="<?= $key ?>" class="form-check-input" <?= $data['commented'] ? 'checked':'' ?>>
                        </div>
                        <div class="input-group input-group-sm">
                            <input type="text" name="values[<?= $key ?>]" class="form-control config-input" value="<?= htmlspecialchars($data['value']) ?>">
                            <button type="submit" name="delete_key" value="<?= $key ?>" class="btn btn-outline-danger border-secondary border-2"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>

        <details class="card config-card config-group-el">
            <summary class="config-header">Neue Variable hinzufügen <i class="fas fa-chevron-down small"></i></summary>
            <div class="p-3">
                <div class="mb-2">
                    <label for="new_key_input" class="config-label" data-tooltip="Name der neuen Variable. Wird in Kleinbuchstaben umgewandelt.">Variablenname</label>
                    <input type="text" id="new_key_input" name="new_key" class="form-control config-input" placeholder="z.B. meine_neue_variable">
                </div>
                <div class="mb-2">
                    <label for="new_value_input" class="config-label" data-tooltip="Wert der neuen Variable.">Wert</label>
                    <input type="text" id="new_value_input" name="new_value" class="form-control config-input" placeholder="z.B. true oder 123">
                </div>
                <div class="form-text text-muted small">
                    Die neue Variable wird beim Speichern hinzugefügt und erscheint danach in der Gruppe "Weitere Parameter". Um sie einer anderen Gruppe zuzuordnen, muss die Datei <code>config_editor.php</code> manuell bearbeitet werden.
                </div>
            </div>
        </details>

        <?php $isOn = (($config['stop']['value'] ?? '0') === '1'); $stopColor = $isOn ? "#f43f5e" : "#10b981"; ?>
        <div class="card config-card p-4 text-center">
            <h6 class="text-muted small mb-3 fw-bold">Screen stop</h6>
            <div class="d-flex flex-column flex-md-row justify-content-center align-items-center gap-4">
                <div style="width: 65px; height: 65px; border-radius: 50%; background: <?= $stopColor ?>; display: flex; flex-direction: column; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 0 15px <?= $stopColor ?>40;">
                    <span style="font-size: 0.6rem; text-transform: uppercase;">Status</span>
                    <span><?= $isOn ? 'EIN':'AUS' ?></span>
                </div>
                <button type="submit" name="stop_set" value="1" class="btn btn-outline-danger rounded-pill px-5 fw-bold border-2 shadow-sm">STOP AKTIVIEREN (EIN)</button>
                <button type="submit" name="stop_set" value="0" class="btn btn-outline-success rounded-pill px-5 fw-bold border-2 shadow-sm">STOP DEAKTIVIEREN (AUS)</button>
            </div>
        </div>

        <button type="submit" name="save_all" value="1" class="btn btn-info w-100 rounded-pill py-3 fw-bold text-dark mt-3 shadow">💾 ALLE ÄNDERUNGEN SPEICHERN</button>
    </form>
</div>

<script>
function filterConfig() {
    var input = document.getElementById('configSearch');
    var filter = input.value.toLowerCase();
    var groups = document.getElementsByClassName('config-group-el');

    for (var j = 0; j < groups.length; j++) {
        var groupTitle = groups[j].querySelector('.config-header').textContent.toLowerCase();
        var items = groups[j].querySelectorAll('.config-item');
        var groupMatches = groupTitle.includes(filter);
        
        for (var i = 0; i < items.length; i++) {
            var key = items[i].getAttribute('data-search-key');
            // Zeige Item, wenn Key ODER Gruppenname passt
            items[i].style.display = (key.includes(filter) || groupMatches) ? "" : "none";
        }

        var groupItems = groups[j].querySelectorAll('.config-item');
        var hasVisibleItems = false;
        for (var k = 0; k < groupItems.length; k++) {
            if (groupItems[k].style.display !== 'none') {
                hasVisibleItems = true;
                break;
            }
        }

        if (filter.length > 0) {
            groups[j].open = hasVisibleItems;
            groups[j].style.display = hasVisibleItems ? "" : "none";
        } else {
            groups[j].open = false;
            groups[j].style.display = "";
        }
    }
}

function toggleAllGroups(open) {
    var groups = document.getElementsByClassName('config-group-el');
    for (var i = 0; i < groups.length; i++) {
        groups[i].open = open;
    }
}
</script>