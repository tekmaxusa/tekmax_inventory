<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/import_ui.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$catStmt = $pdo->prepare('SELECT category_id, category_name FROM categories WHERE store_id = ? ORDER BY category_name');
$catStmt->execute([$storeId]);
$categories = $catStmt->fetchAll();

$initialCategory = isset($_GET['category']) ? (int) $_GET['category'] : 0;
$filteredCategoryName = '';
if ($initialCategory > 0) {
    foreach ($categories as $c) {
        if ((int) $c['category_id'] === $initialCategory) {
            $filteredCategoryName = (string) $c['category_name'];
            break;
        }
    }
    if ($filteredCategoryName === '') {
        $initialCategory = 0;
    }
}

$pageTitle = 'Item List';
$pageSubtitle = $filteredCategoryName !== ''
    ? 'Showing items in ' . $filteredCategoryName
    : 'Search, filter, and manage your inventory items';
$currentPage = 'products';
ob_start();
?>
<button type="button" class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#productModal" id="btnAddProduct">
  <i class="bi bi-plus-lg me-1"></i> Add Item
</button>
<?php
$topbarActions = ob_get_clean();
require __DIR__ . '/includes/header.php';
?>

<div class="toolbar-row">
  <select id="categoryFilter" class="form-select" style="max-width:180px">
    <option value="all">All categories</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= (int) $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
    <?php endforeach; ?>
  </select>
  <div class="search-wrap flex-grow-1">
    <i class="bi bi-search"></i>
    <input type="search" id="productSearch" class="form-control" placeholder="Search by name, barcode, or SKU">
  </div>
</div>

<?php
render_bulk_import_panel([
    'api' => '/api/products_import.php',
    'title' => 'Bulk import products',
    'description' => 'Upload CSV or Excel (.xlsx). New products use a default placeholder image.',
    'template_url' => app_url('api/products_import.php?template=1'),
    'columns' => ['product_name', 'sku', 'barcode', 'category', 'location_tag', 'system_qty', 'reorder_level', 'unit_cost', 'unit_price', 'rfid_tag'],
    'panel_id' => 'productsImportPanel',
    'textarea_id' => 'productsImportCsv',
    'file_id' => 'productsImportFile',
    'result_id' => 'productsImportResult',
]);
?>

<div class="table-panel">
  <div class="table-responsive">
    <table class="table-clean" id="productsTable">
      <thead>
        <tr>
          <th>Item</th>
          <th>Location</th>
          <th class="num">Qty</th>
          <th class="num">Cost</th>
          <th class="num">Price</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="productsBody">
        <tr><td colspan="6" class="empty-state">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form class="modal-content" id="productForm" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title" id="productModalTitle">Add Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="product_id" id="product_id">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Product name *</label>
            <input class="form-control" name="product_name" id="product_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">SKU <span class="text-muted fw-normal">(optional if barcode set)</span></label>
            <input class="form-control" name="sku" id="sku" placeholder="Leave blank if barcode-only">
          </div>
          <div class="col-12">
            <label class="form-label d-flex align-items-center gap-2 flex-wrap">
              Barcodes
              <span class="text-muted fw-normal">(optional if SKU set)</span>
              <span class="badge-pill badge-green" id="barcodeScanBadge">Scanner ready</span>
            </label>
            <div id="barcodeRows" class="barcode-rows"></div>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <button type="button" class="btn btn-sm btn-outline-accent" id="btnAddBarcode">
                <i class="bi bi-plus-lg me-1"></i> Add another barcode
              </button>
              <button type="button" class="btn btn-sm btn-ghost" id="btnFocusBarcodeScan">
                <i class="bi bi-upc-scan me-1"></i> Focus scan field
              </button>
            </div>
            <div class="form-text" id="barcodeScanHint">
              Open Add Item, then scan — the code goes into Barcode only (not SKU). USB/Bluetooth scanners type the code then Enter.
            </div>
            <div id="barcodeScanFeedback" class="text-muted-sm mt-1 d-none"></div>
          </div>
          <div class="col-md-4">
            <label class="form-label">RFID tag</label>
            <input class="form-control" name="rfid_tag" id="rfid_tag" placeholder="EPC / TID from reader">
          </div>
          <div class="col-md-4">
            <label class="form-label">Category</label>
            <div class="input-group">
              <select class="form-select" name="category_id" id="category_id">
                <option value="">—</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button type="button" class="btn btn-outline-accent" id="btnToggleNewCategory" title="Add new category">
                <i class="bi bi-plus-lg"></i>
              </button>
            </div>
            <div id="newCategoryPanel" class="mt-2 d-none">
              <div class="input-group input-group-sm">
                <input type="text" class="form-control" id="new_category_name" placeholder="New category name" maxlength="100" autocomplete="off">
                <button type="button" class="btn btn-accent" id="btnSaveNewCategory">Add</button>
              </div>
              <div class="form-text">Creates a category and selects it. Existing ones stay in the list above.</div>
              <div class="text-danger small mt-1 d-none" id="newCategoryError"></div>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Location</label>
            <input class="form-control" name="location_tag" id="location_tag" placeholder="A-01">
          </div>
          <div class="col-md-4">
            <label class="form-label">System qty</label>
            <input class="form-control" type="number" min="0" name="system_qty" id="system_qty" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">Reorder level</label>
            <input class="form-control" type="number" min="0" name="reorder_level" id="reorder_level" value="5">
          </div>
          <div class="col-md-4">
            <label class="form-label">Unit cost</label>
            <input class="form-control" type="number" min="0" step="0.01" name="unit_cost" id="unit_cost" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">Unit price</label>
            <input class="form-control" type="number" min="0" step="0.01" name="unit_price" id="unit_price" value="0">
          </div>
          <div class="col-12">
            <label class="form-label" id="imageLabel">Product image *</label>
            <div class="image-upload-row">
              <div class="image-preview-wrap">
                <img id="imagePreview" class="image-preview d-none" alt="Preview">
                <div id="imagePreviewPlaceholder" class="image-preview placeholder">
                  <i class="bi bi-image"></i>
                </div>
              </div>
              <div class="flex-grow-1">
                <input class="form-control" type="file" name="image" id="image" accept="image/jpeg,image/png,image/webp,image/gif">
                <div class="form-text" id="imageHelp">Required. JPG, PNG, WEBP, or GIF — max 3 MB.</div>
                <input type="hidden" id="existing_image" value="">
              </div>
            </div>
          </div>
        </div>
        <div class="text-danger small mt-2 d-none" id="productFormError"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-accent" id="productSaveBtn">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<?php
ob_start();
?>
<script>
const API = (window.APP_BASE || '/inventory-system') + '/api/products.php';
const CAT_API = (window.APP_BASE || '/inventory-system') + '/api/categories.php';
let editMode = false;
const modalEl = document.getElementById('productModal');
const modal = new bootstrap.Modal(modalEl);
const imageInput = document.getElementById('image');
const imagePreview = document.getElementById('imagePreview');
const imagePlaceholder = document.getElementById('imagePreviewPlaceholder');
const newCategoryPanel = document.getElementById('newCategoryPanel');
const newCategoryInput = document.getElementById('new_category_name');
const newCategoryError = document.getElementById('newCategoryError');

function money(n) {
  return '$' + Number(n || 0).toFixed(2);
}
function toast(msg, type='ok') {
  const el = document.createElement('div');
  el.className = 'app-toast ' + type;
  el.textContent = msg;
  document.getElementById('toastWrap').appendChild(el);
  setTimeout(() => el.remove(), 2800);
}
function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function thumb(url, name) {
  if (url) return `<img class="prod-thumb" src="${escapeHtml(url)}" alt="">`;
  const ch = (name || '?').charAt(0).toUpperCase();
  return `<div class="prod-thumb placeholder">${ch}</div>`;
}

function hideNewCategoryPanel() {
  newCategoryPanel?.classList.add('d-none');
  if (newCategoryInput) newCategoryInput.value = '';
  newCategoryError?.classList.add('d-none');
}

function upsertCategoryOption(selectEl, id, name) {
  if (!selectEl) return;
  const value = String(id);
  let opt = selectEl.querySelector(`option[value="${value}"]`);
  if (!opt) {
    opt = document.createElement('option');
    opt.value = value;
    selectEl.appendChild(opt);
  }
  opt.textContent = name;
}

function addCategoryToLists(id, name) {
  upsertCategoryOption(document.getElementById('category_id'), id, name);
  upsertCategoryOption(document.getElementById('categoryFilter'), id, name);
  document.getElementById('category_id').value = String(id);
}

document.getElementById('btnToggleNewCategory')?.addEventListener('click', () => {
  const opening = newCategoryPanel.classList.contains('d-none');
  newCategoryPanel.classList.toggle('d-none', !opening);
  if (opening) {
    newCategoryError.classList.add('d-none');
    newCategoryInput.value = '';
    setTimeout(() => newCategoryInput.focus(), 40);
  }
});

async function saveNewCategory() {
  const name = (newCategoryInput?.value || '').trim();
  newCategoryError.classList.add('d-none');
  if (!name) {
    newCategoryError.textContent = 'Category name is required.';
    newCategoryError.classList.remove('d-none');
    return;
  }
  const btn = document.getElementById('btnSaveNewCategory');
  btn.disabled = true;
  try {
    const res = await fetch(CAT_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ category_name: name }),
    });
    const data = await res.json();
    if (!data.ok || !data.category) {
      newCategoryError.textContent = data.error || 'Failed to add category';
      newCategoryError.classList.remove('d-none');
      return;
    }
    addCategoryToLists(data.category.category_id, data.category.category_name);
    hideNewCategoryPanel();
    toast(data.existing ? 'Category already existed — selected' : 'Category added');
  } finally {
    btn.disabled = false;
  }
}

document.getElementById('btnSaveNewCategory')?.addEventListener('click', saveNewCategory);
newCategoryInput?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    e.stopPropagation();
    saveNewCategory();
  }
});

function setPreview(url) {
  if (url) {
    imagePreview.src = url;
    imagePreview.classList.remove('d-none');
    imagePlaceholder.classList.add('d-none');
  } else {
    imagePreview.removeAttribute('src');
    imagePreview.classList.add('d-none');
    imagePlaceholder.classList.remove('d-none');
  }
}

function setBarcodeRows(values) {
  const wrap = document.getElementById('barcodeRows');
  const list = (values && values.length) ? values : [''];
  wrap.innerHTML = list.map((v, i) => `
    <div class="barcode-row input-group mb-2">
      <span class="input-group-text">${i === 0 ? 'Primary' : '#' + (i + 1)}</span>
      <input type="text" class="form-control barcode-input" value="${escapeHtml(v)}" placeholder="Scan or type barcode / UPC" autocomplete="off" inputmode="none">
      <button type="button" class="btn btn-outline-secondary btn-remove-barcode" title="Remove"${list.length <= 1 ? ' disabled' : ''}>
        <i class="bi bi-x-lg"></i>
      </button>
    </div>`).join('');
}

function getBarcodeValues() {
  return Array.from(document.querySelectorAll('#barcodeRows .barcode-input'))
    .map(el => el.value.trim())
    .filter(Boolean);
}

function focusBarcodeScanField() {
  const inputs = Array.from(document.querySelectorAll('#barcodeRows .barcode-input'));
  if (!inputs.length) {
    setBarcodeRows(['']);
    return focusBarcodeScanField();
  }
  const empty = inputs.find(el => !el.value.trim()) || inputs[0];
  empty.focus();
  empty.select();
  const badge = document.getElementById('barcodeScanBadge');
  if (badge) {
    badge.textContent = 'Listening…';
    badge.className = 'badge-pill badge-green';
  }
}

function showBarcodeScanFeedback(msg, ok) {
  const el = document.getElementById('barcodeScanFeedback');
  if (!el) return;
  el.classList.remove('d-none', 'text-danger', 'text-success');
  el.classList.add(ok ? 'text-success' : 'text-danger');
  el.textContent = msg;
}

document.getElementById('btnAddBarcode').addEventListener('click', () => {
  setBarcodeRows([...getBarcodeValues(), '']);
  focusBarcodeScanField();
});

document.getElementById('btnFocusBarcodeScan').addEventListener('click', () => {
  focusBarcodeScanField();
  toast('Scanner ready — barcode field focused');
});

document.getElementById('barcodeRows').addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-remove-barcode');
  if (!btn) return;
  const values = getBarcodeValues();
  const row = btn.closest('.barcode-row');
  const idx = Array.from(document.querySelectorAll('#barcodeRows .barcode-row')).indexOf(row);
  if (idx >= 0) values.splice(idx, 1);
  setBarcodeRows(values.length ? values : ['']);
});

// USB/Bluetooth wedge scanners type digits then Enter — keep code in barcode only
document.getElementById('barcodeRows').addEventListener('keydown', (e) => {
  const input = e.target.closest('.barcode-input');
  if (!input) return;
  if (e.key !== 'Enter') return;
  e.preventDefault();
  e.stopPropagation();
  const code = input.value.trim().replace(/\s+/g, '');
  if (!code) return;
  input.value = code;
  // Don't copy into SKU — leave SKU alone
  showBarcodeScanFeedback('Scanned: ' + code, true);
  toast('Barcode captured: ' + code);
  // Add empty row for next scan if this was the last filled field
  const values = getBarcodeValues();
  const inputs = document.querySelectorAll('#barcodeRows .barcode-input');
  const isLast = inputs[inputs.length - 1] === input;
  if (isLast && values.length === inputs.length) {
    setBarcodeRows([...values, '']);
  }
  focusBarcodeScanField();
});

modalEl.addEventListener('shown.bs.modal', () => {
  // Prefer barcode scan field over name/SKU so wedge scanners land correctly
  setTimeout(focusBarcodeScanField, 80);
});

modalEl.addEventListener('hidden.bs.modal', () => {
  const badge = document.getElementById('barcodeScanBadge');
  if (badge) {
    badge.textContent = 'Scanner ready';
    badge.className = 'badge-pill badge-green';
  }
  document.getElementById('barcodeScanFeedback')?.classList.add('d-none');
});

function resetForm() {
  document.getElementById('productForm').reset();
  document.getElementById('product_id').value = '';
  document.getElementById('existing_image').value = '';
  document.getElementById('system_qty').disabled = false;
  document.getElementById('productFormError').classList.add('d-none');
  document.getElementById('productModalTitle').textContent = 'Add Item';
  document.getElementById('imageLabel').textContent = 'Product image *';
  document.getElementById('imageHelp').textContent = 'Required. JPG, PNG, WEBP, or GIF — max 3 MB.';
  imageInput.required = true;
  setPreview(null);
  setBarcodeRows(['']);
  hideNewCategoryPanel();
  editMode = false;
}

imageInput.addEventListener('change', () => {
  const file = imageInput.files && imageInput.files[0];
  if (!file) {
    setPreview(document.getElementById('existing_image').value || null);
    return;
  }
  const url = URL.createObjectURL(file);
  setPreview(url);
});

async function loadProducts() {
  const search = document.getElementById('productSearch').value.trim();
  const category = document.getElementById('categoryFilter').value;
  const qs = new URLSearchParams({ search, category });
  const res = await fetch(API + '?' + qs.toString());
  const data = await res.json();
  const tbody = document.getElementById('productsBody');
  if (!data.ok || !data.products.length) {
    tbody.innerHTML = `<tr><td colspan="6" class="empty-state">No products found.</td></tr>`;
    return;
  }
  tbody.innerHTML = data.products.map(p => {
    const low = Number(p.system_qty) <= Number(p.reorder_level);
    return `<tr class="${low ? 'row-low' : ''}" data-id="${p.product_id}">
      <td>
        <div class="prod-cell">
          ${thumb(p.image_url, p.product_name)}
          <div>
            <strong>${escapeHtml(p.product_name)}</strong>
            <div class="text-muted-sm">${escapeHtml(p.sku || 'No SKU')}${p.barcodes && p.barcodes.length > 1 ? ' · ' + p.barcodes.length + ' barcodes' : p.barcode ? ' · ' + escapeHtml(p.barcode) : ''} · ${escapeHtml(p.category_name || 'Uncategorized')}</div>
          </div>
        </div>
      </td>
      <td><span class="loc-pill">${escapeHtml(p.location_tag || '—')}</span></td>
      <td class="num">${p.system_qty}${low ? ' <span class="dot-warn" title="Low stock"></span>' : ''}</td>
      <td class="num">${money(p.unit_cost)}</td>
      <td class="num">${money(p.unit_price)}</td>
      <td class="text-end text-nowrap">
        <button class="btn btn-sm btn-ghost btn-edit" data-id="${p.product_id}" title="Edit"><i class="bi bi-pencil"></i></button>
        ${window.APP_IS_ADMIN ? `<button class="btn btn-sm btn-ghost text-danger btn-del" data-id="${p.product_id}" title="Delete"><i class="bi bi-trash"></i></button>` : ''}
      </td>
    </tr>`;
  }).join('');
}

document.getElementById('btnAddProduct').addEventListener('click', resetForm);

document.getElementById('productsBody').addEventListener('click', async (e) => {
  const editBtn = e.target.closest('.btn-edit');
  const delBtn = e.target.closest('.btn-del');
  if (editBtn) {
    const id = editBtn.dataset.id;
    const res = await fetch(API + '?id=' + id);
    const data = await res.json();
    if (!data.ok) return toast(data.error || 'Load failed', 'err');
    const p = data.product;
    editMode = true;
    document.getElementById('productModalTitle').textContent = 'Edit Item';
    document.getElementById('product_id').value = p.product_id;
    document.getElementById('product_name').value = p.product_name;
    document.getElementById('sku').value = p.sku || '';
    setBarcodeRows((p.barcodes && p.barcodes.length) ? p.barcodes : (p.barcode ? [p.barcode] : ['']));
    document.getElementById('rfid_tag').value = p.rfid_tag || '';
    document.getElementById('category_id').value = p.category_id || '';
    document.getElementById('location_tag').value = p.location_tag || '';
    document.getElementById('system_qty').value = p.system_qty;
    document.getElementById('system_qty').disabled = true;
    document.getElementById('reorder_level').value = p.reorder_level;
    document.getElementById('unit_cost').value = p.unit_cost;
    document.getElementById('unit_price').value = p.unit_price;
    document.getElementById('existing_image').value = p.image_url || '';
    imageInput.value = '';
    imageInput.required = !p.image_url;
    document.getElementById('imageLabel').textContent = p.image_url ? 'Product image' : 'Product image *';
    document.getElementById('imageHelp').textContent = p.image_url
      ? 'Leave empty to keep current image, or choose a new file to replace.'
      : 'Required. JPG, PNG, WEBP, or GIF — max 3 MB.';
    setPreview(p.image_url || null);
    document.getElementById('productFormError').classList.add('d-none');
    hideNewCategoryPanel();
    modal.show();
  }
  if (delBtn) {
    if (!confirm('Soft-delete this product?')) return;
    const res = await fetch(API, {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ _method: 'DELETE', product_id: Number(delBtn.dataset.id) })
    });
    const data = await res.json();
    if (data.ok) { toast('Product deleted'); loadProducts(); }
    else toast(data.error || 'Delete failed', 'err');
  }
});

document.getElementById('productForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const err = document.getElementById('productFormError');
  err.classList.add('d-none');

  const hasFile = imageInput.files && imageInput.files.length > 0;
  const hasExisting = !!document.getElementById('existing_image').value;
  if (!editMode && !hasFile) {
    err.textContent = 'Product image is required.';
    err.classList.remove('d-none');
    return;
  }
  if (editMode && !hasFile && !hasExisting) {
    err.textContent = 'Product image is required. Please upload an image.';
    err.classList.remove('d-none');
    return;
  }

  const sku = document.getElementById('sku').value.trim();
  const barcodes = getBarcodeValues();
  if (!sku && barcodes.length === 0) {
    err.textContent = 'Provide a SKU and/or at least one barcode.';
    err.classList.remove('d-none');
    return;
  }

  const fd = new FormData();
  fd.append('product_name', document.getElementById('product_name').value.trim());
  fd.append('sku', sku);
  fd.append('barcodes', JSON.stringify(barcodes));
  if (barcodes[0]) fd.append('barcode', barcodes[0]);
  fd.append('rfid_tag', document.getElementById('rfid_tag').value.trim());
  fd.append('category_id', document.getElementById('category_id').value);
  fd.append('location_tag', document.getElementById('location_tag').value.trim());
  fd.append('system_qty', String(Number(document.getElementById('system_qty').value || 0)));
  fd.append('reorder_level', String(Number(document.getElementById('reorder_level').value || 5)));
  fd.append('unit_cost', String(Number(document.getElementById('unit_cost').value || 0)));
  fd.append('unit_price', String(Number(document.getElementById('unit_price').value || 0)));
  if (editMode) {
    fd.append('_method', 'PUT');
    fd.append('product_id', document.getElementById('product_id').value);
  }
  if (hasFile) {
    fd.append('image', imageInput.files[0]);
  }

  const res = await fetch(API, { method: 'POST', body: fd });
  const data = await res.json();
  if (!data.ok) {
    err.textContent = data.error || 'Save failed';
    err.classList.remove('d-none');
    return;
  }
  modal.hide();
  toast(editMode ? 'Product updated' : 'Product created');
  loadProducts();
});

let t;
document.getElementById('productSearch').addEventListener('input', () => {
  clearTimeout(t); t = setTimeout(loadProducts, 250);
});
document.getElementById('categoryFilter').addEventListener('change', loadProducts);

const initialCategory = <?= (int) $initialCategory ?>;
if (initialCategory > 0) {
  const filter = document.getElementById('categoryFilter');
  if (filter.querySelector(`option[value="${initialCategory}"]`)) {
    filter.value = String(initialCategory);
  }
}
loadProducts();
setBarcodeRows(['']);
window.onBulkImportSuccess = function () { loadProducts(); };
</script>
<?php
$pageScripts = ob_get_clean();
ob_start();
render_bulk_import_script();
$pageScripts .= ob_get_clean();
require __DIR__ . '/includes/footer.php';
