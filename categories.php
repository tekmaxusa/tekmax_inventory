<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/import_ui.php';
require_login();

$pageTitle = 'Categories';
$pageSubtitle = 'Organize products by category';
$currentPage = 'categories';
$topbarActions = '';
if (is_admin()) {
    ob_start();
    ?>
<button type="button" class="btn btn-accent" id="btnAddCat" data-bs-toggle="modal" data-bs-target="#catModal">
  <i class="bi bi-plus-lg me-1"></i> Add Category
</button>
    <?php
    $topbarActions = ob_get_clean();
}
require __DIR__ . '/includes/header.php';
?>

<?php if (is_admin()): ?>
<?php
render_bulk_import_panel([
    'api' => '/api/categories_import.php',
    'title' => 'Bulk import categories',
    'description' => 'Upload CSV or Excel (.xlsx), or paste one category name per line.',
    'template_url' => app_url('api/categories_import.php?template=1'),
    'columns' => ['category_name'],
    'panel_id' => 'categoriesImportPanel',
    'textarea_id' => 'categoriesImportCsv',
    'file_id' => 'categoriesImportFile',
    'result_id' => 'categoriesImportResult',
]);
?>
<?php endif; ?>

<div class="table-panel">
  <div class="table-responsive">
    <table class="table-clean">
      <thead>
        <tr>
          <th>Category</th>
          <th class="num">Products</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="catBody"><tr><td colspan="3" class="empty-state">Loading…</td></tr></tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="catModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="catForm">
      <div class="modal-header">
        <h5 class="modal-title" id="catModalTitle">Add Category</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="category_id">
        <label class="form-label">Name</label>
        <input class="form-control" id="category_name" required maxlength="100">
        <div class="text-danger small mt-2 d-none" id="catError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-accent">Save</button>
      </div>
    </form>
  </div>
</div>
<div class="toast-wrap" id="toastWrap"></div>

<?php
ob_start();
?>
<script>
const API = (window.APP_BASE || '/inventory-system') + '/api/categories.php';
const modal = new bootstrap.Modal('#catModal');
let editing = false;

function toast(msg, type='ok') {
  const el = document.createElement('div');
  el.className = 'app-toast ' + type;
  el.textContent = msg;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => el.remove(), 2500);
}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

const PRODUCTS_PAGE = (window.APP_BASE || '/inventory-system') + '/products.php';

function productsUrl(categoryId) {
  return PRODUCTS_PAGE + '?category=' + encodeURIComponent(categoryId);
}

async function load() {
  const res = await fetch(API);
  const data = await res.json();
  const body = document.getElementById('catBody');
  if (!data.ok || !data.categories.length) {
    body.innerHTML = '<tr><td colspan="3" class="empty-state">No categories yet.</td></tr>';
    return;
  }
  body.innerHTML = data.categories.map(c => `<tr class="cat-row">
    <td>
      <a href="${productsUrl(c.category_id)}" class="cat-link">
        <i class="bi bi-chevron-right cat-chevron" aria-hidden="true"></i>
        <strong>${esc(c.category_name)}</strong>
      </a>
    </td>
    <td class="num">
      <a href="${productsUrl(c.category_id)}" class="cat-count-link">${c.product_count}</a>
    </td>
    <td class="text-end">
      ${window.APP_IS_ADMIN ? `
      <button type="button" class="btn btn-sm btn-ghost btn-edit" data-id="${c.category_id}" data-name="${esc(c.category_name)}"><i class="bi bi-pencil"></i></button>
      <button type="button" class="btn btn-sm btn-ghost text-danger btn-del" data-id="${c.category_id}"><i class="bi bi-trash"></i></button>
      ` : ''}
    </td>
  </tr>`).join('');
}

document.getElementById('btnAddCat')?.addEventListener('click', () => {
  editing = false;
  document.getElementById('catModalTitle').textContent = 'Add Category';
  document.getElementById('category_id').value = '';
  document.getElementById('category_name').value = '';
  document.getElementById('catError').classList.add('d-none');
});

document.getElementById('catBody').addEventListener('click', async (e) => {
  const edit = e.target.closest('.btn-edit');
  const del = e.target.closest('.btn-del');
  if (edit) {
    editing = true;
    document.getElementById('catModalTitle').textContent = 'Edit Category';
    document.getElementById('category_id').value = edit.dataset.id;
    document.getElementById('category_name').value = edit.dataset.name;
    modal.show();
  }
  if (del) {
    if (!confirm('Delete this category?')) return;
    const res = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({_method:'DELETE', category_id: Number(del.dataset.id)})
    });
    const data = await res.json();
    if (data.ok) { toast('Deleted'); load(); } else toast(data.error||'Failed','err');
  }
});

document.getElementById('catForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('catError');
  err.classList.add('d-none');
  const payload = {
    category_id: document.getElementById('category_id').value || undefined,
    category_name: document.getElementById('category_name').value.trim(),
  };
  if (editing) payload._method = 'PUT';
  const res = await fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
  const data = await res.json();
  if (!data.ok) { err.textContent = data.error||'Failed'; err.classList.remove('d-none'); return; }
  modal.hide(); toast('Saved');
  load();
});

load();
window.onBulkImportSuccess = function () { load(); };
</script>
<?php
$pageScripts = ob_get_clean();
ob_start();
render_bulk_import_script();
$pageScripts .= ob_get_clean();
require __DIR__ . '/includes/footer.php';
