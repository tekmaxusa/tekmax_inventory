<?php
/**
 * Reusable bulk import panel markup + JS.
 */
declare(strict_types=1);

function render_bulk_import_panel(array $config): void
{
    $api = (string) ($config['api'] ?? '');
    $title = (string) ($config['title'] ?? 'Bulk import');
    $description = (string) ($config['description'] ?? 'Upload a CSV or Excel (.xlsx) file, or paste CSV text.');
    $templateUrl = (string) ($config['template_url'] ?? '');
    $columns = (array) ($config['columns'] ?? []);
    $panelId = (string) ($config['panel_id'] ?? 'bulkImportPanel');
    $textareaId = (string) ($config['textarea_id'] ?? 'bulkImportCsv');
    $fileId = (string) ($config['file_id'] ?? 'bulkImportFile');
    $resultId = (string) ($config['result_id'] ?? 'bulkImportResult');
    ?>
<div class="import-panel card-soft p-3 mb-3" id="<?= htmlspecialchars($panelId) ?>">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
    <div>
      <h2 class="h6 mb-1"><?= htmlspecialchars($title) ?></h2>
      <p class="text-muted-sm mb-0"><?= htmlspecialchars($description) ?></p>
    </div>
    <?php if ($templateUrl !== ''): ?>
      <a class="btn btn-sm btn-outline-accent" href="<?= htmlspecialchars($templateUrl) ?>">
        <i class="bi bi-download me-1"></i> Download template
      </a>
    <?php endif; ?>
  </div>
  <?php if ($columns !== []): ?>
    <div class="import-columns mb-2">
      <span class="text-muted-sm">Columns:</span>
      <code><?= htmlspecialchars(implode(', ', $columns)) ?></code>
    </div>
  <?php endif; ?>
  <textarea id="<?= htmlspecialchars($textareaId) ?>" class="form-control mb-2" rows="4"
            placeholder="Paste CSV rows here (include header row)"></textarea>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <input type="file" id="<?= htmlspecialchars($fileId) ?>" class="form-control" style="max-width:280px"
           accept=".csv,.txt,text/csv,text/plain,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
    <button type="button" class="btn btn-accent btn-import" data-api="<?= htmlspecialchars($api) ?>"
            data-textarea="<?= htmlspecialchars($textareaId) ?>"
            data-file="<?= htmlspecialchars($fileId) ?>"
            data-result="<?= htmlspecialchars($resultId) ?>">
      <i class="bi bi-upload me-1"></i> Import
    </button>
  </div>
  <div id="<?= htmlspecialchars($resultId) ?>" class="import-result mt-2"></div>
</div>
    <?php
}

function render_bulk_import_script(): void
{
    ?>
<script>
(function () {
  const BASE = window.APP_BASE || '/inventory-system';

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }

  document.querySelectorAll('.btn-import').forEach(btn => {
    btn.addEventListener('click', async () => {
      const api = btn.dataset.api;
      const textarea = document.getElementById(btn.dataset.textarea);
      const fileInput = document.getElementById(btn.dataset.file);
      const result = document.getElementById(btn.dataset.result);
      if (!api || !result) return;

      const fd = new FormData();
      const csv = textarea?.value?.trim() || '';
      if (csv) fd.append('csv', csv);
      if (fileInput?.files?.[0]) fd.append('file', fileInput.files[0]);
      if (!csv && !(fileInput?.files?.[0])) {
        result.innerHTML = '<div class="text-danger small">Upload a file or paste CSV text.</div>';
        return;
      }

      btn.disabled = true;
      result.innerHTML = '<div class="text-muted-sm">Importing…</div>';
      try {
        const res = await fetch(BASE + api, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          result.innerHTML = '<div class="text-danger small">' + esc(data.error || 'Import failed') + '</div>';
          return;
        }
        let html = '<div class="text-success small mb-1"><strong>Import complete.</strong> ' +
          esc(data.message || ('Created/updated: ' + (data.created ?? data.updated ?? 0))) + '</div>';
        if (data.skipped && data.skipped.length) {
          html += '<div class="text-muted-sm mb-1">Skipped ' + data.skipped.length + ' row(s).</div>';
          html += '<ul class="import-skip-list">' +
            data.skipped.slice(0, 8).map(s => '<li>' + esc((s.row ? 'Row ' + s.row + ': ' : '') + (s.reason || s.raw || '')) + '</li>').join('') +
            '</ul>';
        }
        result.innerHTML = html;
        if (typeof window.onBulkImportSuccess === 'function') {
          window.onBulkImportSuccess(data);
        }
      } catch (e) {
        result.innerHTML = '<div class="text-danger small">Import failed. Check your file format.</div>';
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>
    <?php
}
