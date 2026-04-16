<?php
/* =====================================================================
   mobile_history.php - Mobile Historie mit Zeitauswahl
   ===================================================================== */
$paths = getInstallPaths();
$live_diagramm = '/var/www/html/live_diagramm.html';
$live_history_script = rtrim($paths['install_path'], '/') . '/plot_live_history.py';
$live_diagramm_stamp = '/var/www/html/tmp/plot_live_history_last_run';
$backups = getHistoryBackupFiles();

// Automatisches Update beim Laden der Seite wurde entfernt. Update nur noch manuell per Button.
?>

<div id="historyHeader" class="d-flex justify-content-between align-items-center mb-3 px-1">
    <h5 class="m-0 fw-bold text-info">Live-Verlauf</h5>
    
    <div class="btn-group btn-group-sm mx-2" role="group" id="timeFilterGroup">
        <button type="button" class="btn btn-outline-info active" data-hours="6">6h</button>
        <button type="button" class="btn btn-outline-info" data-hours="12">12h</button>
        <button type="button" class="btn btn-outline-info" data-hours="24">24h</button>
        <button type="button" class="btn btn-outline-info" data-hours="48">48h</button>
    </div>

    <div>
        <span id="historyUpdateStatus" class="me-2 text-white-50 small"></span>
        <button id="historyUpdateBtn" class="btn btn-sm btn-outline-light">
            <i class="bi bi-arrow-clockwise"></i> Update
        </button>
    </div>
</div>

<!-- Archiv Dropdown -->
<div class="mb-3 px-1">
    <div class="input-group input-group-sm">
        <label class="input-group-text bg-dark text-info border-info" for="historyArchiveSelect">Archiv (24h)</label>
        <select class="form-select bg-dark text-white border-info" id="historyArchiveSelect">
            <option value="live" selected>Aktuelle Live-Daten</option>
            <?php foreach ($backups as $b): ?>
                <option value="<?= htmlspecialchars($b['file']) ?>"><?= htmlspecialchars($b['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="ratio ratio-1x1 w-100" style="min-height: 400px; max-height: 70vh;">
    <iframe id="historyFrame" src="live_diagramm.html?t=<?= file_exists($live_diagramm) ? filemtime($live_diagramm) : time() ?>" style="width:100%; height:100%; border:none; border-radius: 8px;" title="Live Historie"></iframe>
</div>

<script>
(function(){
    var btn = document.getElementById('historyUpdateBtn');
    var archiveSelect = document.getElementById('historyArchiveSelect');
    var status = document.getElementById('historyUpdateStatus');
    var frame = document.getElementById('historyFrame');
    var filterGroup = document.getElementById('timeFilterGroup');
    var timeBtns = document.querySelectorAll('#timeFilterGroup .btn');
    var currentHours = 6; // Standardwert
    var currentFile = '';
    window.triggerHistoryUpdate = triggerUpdate; // Make it globally accessible

    // Button Click-Logik für Zeitauswahl
    timeBtns.forEach(function(tBtn) {
        tBtn.addEventListener('click', function() {
            // Aktiven Status umschalten
            timeBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Stunden setzen und sofort Update triggern
            currentHours = this.getAttribute('data-hours');
            currentFile = '';
            archiveSelect.value = 'live';
            triggerUpdate();
        });
    });

    // Dropdown Logik für Archiv-Dateien
    archiveSelect.addEventListener('change', function() {
        if (this.value === 'live') {
            // Zurück zu Live-Daten
            currentFile = '';
            filterGroup.classList.remove('d-none');
            // Aktuelle Stunden vom aktiven Button holen
            var activeBtn = document.querySelector('#timeFilterGroup .btn.active');
            currentHours = activeBtn ? activeBtn.getAttribute('data-hours') : 6;
            triggerUpdate();
        } else if (this.value) {
            // Archiv-Datei gewählt
            currentFile = this.value;
            currentHours = 24;
            filterGroup.classList.add('d-none');
            triggerUpdate();
        }
    });

    btn.addEventListener('click', triggerUpdate);

    function triggerUpdate(){
        btn.disabled = true;
        archiveSelect.disabled = true;
        timeBtns.forEach(b => b.disabled = true); // Buttons sperren während geladen wird
        
        var msg = currentFile ? 'Lade Archiv ' + archiveSelect.options[archiveSelect.selectedIndex].text + '...' : 'Erstelle ' + currentHours + 'h Diagramm…';
        if (status) status.textContent = msg;
        
        // Parameter hours an das Backend senden
        var url = '?action=run_live_history&hours=' + currentHours + (currentFile ? '&file=' + encodeURIComponent(currentFile) : '') + '&dark=' + (DARK_MODE ? '1' : '0');
        
        fetch(url).then(function(r){ return r.json(); }).then(function(d){
            if (!d.ok) { if (status) status.textContent = d.error || 'Fehler'; resetBtns(); return; }
            
            var check = setInterval(function(){
                fetch('?action=run_live_history&mode=status').then(function(r){ return r.json(); }).then(function(s){
                    if (!s.running) {
                        clearInterval(check);
                        if (status) status.textContent = 'Fertig';
                        frame.src = 'live_diagramm.html?t=' + Date.now();
                        resetBtns();
                        setTimeout(function(){ if (status) status.textContent = ''; }, 2000);
                    }
                });
            }, 1000);
        }).catch(function(){ if (status) status.textContent = 'Fehler'; resetBtns(); });
    }

    function resetBtns() {
        btn.disabled = false;
        archiveSelect.disabled = false;
        timeBtns.forEach(b => b.disabled = false);
    }
})();
</script>