<?php
/* =====================================================================
   mobile_history.php - Mobile Historie mit Zeitauswahl
   ===================================================================== */
$paths = getInstallPaths();
$live_diagramm = '/var/www/html/live_diagramm.php';
$live_diagramm = '/var/www/html/live_diagramm.php';
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
    <iframe id="historyFrame" src="live_diagramm.php?t=<?= file_exists($live_diagramm) ? filemtime($live_diagramm) : time() ?>" style="width:100%; height:100%; border:none; border-radius: 8px;" title="Live Historie"></iframe>
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

    // Button Click-Logik fÃ¼r Zeitauswahl
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

    // Dropdown Logik fÃ¼r Archiv-Dateien
    archiveSelect.addEventListener('change', function() {
        if (this.value === 'live') {
            // ZurÃ¼ck zu Live-Daten
            currentFile = '';
            filterGroup.classList.remove('d-none');
            // Aktuelle Stunden vom aktiven Button holen
            var activeBtn = document.querySelector('#timeFilterGroup .btn.active');
            currentHours = activeBtn ? activeBtn.getAttribute('data-hours') : 6;
            triggerUpdate();
        } else if (this.value) {
            // Archiv-Datei gewÃ¤hlt
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
        timeBtns.forEach(b => b.disabled = true); // Buttons sperren wÃ¤hrend geladen wird
        
        var msg = currentFile ? 'Lade Archiv ' + archiveSelect.options[archiveSelect.selectedIndex].text + '...' : 'Erstelle ' + currentHours + 'h Diagrammâ€¦';
        if (status) status.textContent = msg;
        
        var url = 'live_diagramm.php?hours=' + currentHours + (currentFile ? '&file=' + encodeURIComponent(currentFile) : '') + '&dark=' + (DARK_MODE ? '1' : '0') + '&t=' + Date.now();
        
        frame.src = url;
        frame.onload = function() {
            if (status) status.textContent = 'Fertig';
            resetBtns();
            setTimeout(function(){ if (status) status.textContent = ''; }, 2000);
        };
    }

    function resetBtns() {
        btn.disabled = false;
        archiveSelect.disabled = false;
        timeBtns.forEach(b => b.disabled = false);
    }
})();
</script>
