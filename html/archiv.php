<?php
/* =====================================================================
   archiv.php - Mobile Archivansicht für PWA
   ===================================================================== */

$paths = getInstallPaths();
$base_path = rtrim($paths['install_path'], '/') . '/';

$entries = getArchivedDebugFiles($base_path);

$selectedFile = null;
$changed = null;

if (isset($_GET['file'])) {
    $requestedFile = basename($_GET['file']);
    if (validateFilename($requestedFile)) {
        $fullpath = $base_path . $requestedFile;
        if (is_file($fullpath) && is_readable($fullpath)) {
            $selectedFile = $requestedFile;
            $changed = date('d.m.Y H:i', filemtime($fullpath));
        }
    }
}
?>

<div class="px-2 pb-5">
    <div id="archivHeader" class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="m-0 fw-bold text-white">Archiv</h5>
        <?php if ($changed): ?>
            <span class="badge border border-secondary text-info" style="background: #1a1f29; font-size: 0.8rem;">
                <?= htmlspecialchars($changed) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($entries)): ?>
        <div class='alert alert-secondary py-2 mt-2 border-0 bg-dark text-white-50 text-center'>Keine Archiv-Dateien gefunden.</div>
    <?php else: ?>
        <div class="card shadow-sm border-secondary bg-dark text-white mb-3" style="border-radius: 16px; border: 1px solid #2d3748;">
            <div class="card-body p-3">
                <form id="archivForm" method="GET" action="<?= htmlspecialchars(getContextPageUrl('archiv')) ?>">
                    <input type="hidden" name="seite" value="archiv">
                    <label class="form-label text-white-50 small mb-1">Log-Datei auswählen</label>
                    <div class="d-flex gap-2">
                        <select name="file" class="form-select bg-dark text-white border-secondary flex-grow-1">
                            <option value="">Bitte wählen…</option>
                            <?php foreach ($entries as $e): ?>
                                <option value="<?= htmlspecialchars($e['file']) ?>" <?= ($selectedFile === $e['file']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($e['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-outline-info rounded-pill px-3 fw-bold">Zeigen</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div id="archivContainer">
        <?php if ($selectedFile): ?>
            <div id="archivFrameWrap" class="card shadow-sm border-secondary bg-dark text-white" style="border-radius: 16px; border: 1px solid #2d3748; overflow: hidden;">
                <iframe
                    id="archivFrame"
                    src="index.php?action=archiv_diagram&file=<?= urlencode($selectedFile) ?>&ts=<?= time() ?>&dark=<?= $darkMode ? '1' : '0' ?>"
                    style="width: 100%; min-height: 450px; border: none;">
                </iframe>
            </div>
        <?php else: ?>
            <div class="card shadow-sm border-secondary bg-dark text-white text-center p-4 mt-2" style="border-radius: 16px; border: 1px solid #2d3748;">
                <i class="fas fa-file-alt text-white-50 fs-1 mb-2"></i>
                <span class="text-white-50 small">Bitte wählen Sie oben eine Archivdatei aus, um das Diagramm anzuzeigen.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Um die Akkordeons im Config-Editor via Bootstrap ansteuern zu können
if (typeof bootstrap === 'undefined') {
    var script = document.createElement('script');
    script.src = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js";
    document.head.appendChild(script);
}
</script>