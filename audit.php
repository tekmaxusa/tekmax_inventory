<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$active = active_audit_session();
$session = $active;
$catStmt = $pdo->prepare('SELECT category_id, category_name FROM categories WHERE store_id = ? ORDER BY category_name');
$catStmt->execute([$storeId]);
$categories = $catStmt->fetchAll();

// Optional: open specific session if still editable
if (isset($_GET['session_id'])) {
    $sid = (int) $_GET['session_id'];
    $stmt = $pdo->prepare("SELECT * FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved')");
    $stmt->execute([$sid, $storeId]);
    $found = $stmt->fetch();
    if ($found) {
        // Resuming a saved audit re-locks Stock In/Out while counting
        if (($found['status'] ?? '') === 'saved') {
            $pdo->prepare("UPDATE audit_sessions SET status = 'in_progress' WHERE session_id = ? AND store_id = ?")
                ->execute([$sid, $storeId]);
            $found['status'] = 'in_progress';
        }
        $session = $found;
    }
}

$storeNameDefault = (string) (current_store()['store_name'] ?? 'My Store');
$pageTitle = 'Inventory Count';
$pageSubtitle = $session
    ? 'Enter the quantity physically counted. Differences appear instantly.'
    : 'Point, scan, and count — compare records with what’s on hand';
$currentPage = 'audit';

$topbarActions = '';
if ($session) {
    ob_start();
    ?>
    <a class="btn btn-outline-accent" href="<?= htmlspecialchars(app_url('api/audit_export.php?session_id=' . (int) $session['session_id'] . '&format=csv')) ?>">
      <i class="bi bi-download me-1"></i> Export
    </a>
    <button type="button" class="btn btn-accent" id="btnSaveAudit">
      <i class="bi bi-save me-1"></i> Save Audit
    </button>
    <?php if (is_admin()): ?>
    <button type="button" class="btn btn-dark" id="btnCloseAudit">
      Close Audit
    </button>
    <?php endif; ?>
    <?php
    $topbarActions = ob_get_clean();
}

require __DIR__ . '/includes/header.php';
?>

<?php if (!$session): ?>
  <div class="card-soft p-4 mb-3" style="max-width:520px">
    <h2 class="h5 mb-2">Start New Audit</h2>
    <p class="text-muted-sm mb-3">Creates a count row for every active product. Stock In/Out pauses while counting; <strong>Save</strong> unlocks it again, <strong>Close</strong> reconciles stock.</p>
    <form id="startAuditForm" class="row g-2">
      <div class="col-md-7">
        <label class="form-label">Store name</label>
        <input class="form-control" name="store_name" id="store_name" value="<?= htmlspecialchars($storeNameDefault) ?>" readonly required>
      </div>
      <div class="col-md-5">
        <label class="form-label">Audit date</label>
        <input type="date" class="form-control" name="audit_date" id="audit_date" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-accent mt-2">
          <i class="bi bi-play-fill me-1"></i> Start New Audit
        </button>
      </div>
    </form>
    <div class="text-danger small mt-2 d-none" id="startError"></div>
  </div>

  <div class="table-panel">
    <div class="panel-head"><h2>Recent audits</h2></div>
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr><th>Store</th><th>Date</th><th>Status</th><th>Created</th><th></th></tr>
        </thead>
        <tbody id="auditHistoryBody"><tr><td colspan="5" class="empty-state">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>
<?php else: ?>
  <div id="auditApp"
       data-session-id="<?= (int) $session['session_id'] ?>"
       data-store="<?= htmlspecialchars($session['store_name']) ?>"
       data-date="<?= htmlspecialchars($session['audit_date']) ?>">

    <div class="audit-meta mb-3">
      <span class="loc-pill"><?= htmlspecialchars($session['store_name']) ?></span>
      <span class="text-muted-sm ms-2"><?= htmlspecialchars($session['audit_date']) ?></span>
      <span class="badge-pill badge-amber ms-2"><?= htmlspecialchars($session['status']) ?></span>
    </div>

    <div class="kpi-grid audit-kpis">
      <div class="kpi-card">
        <div class="kpi-label">Products Counted</div>
        <div class="kpi-value" id="kpiCounted">0 / 0</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Items w/ Difference</div>
        <div class="kpi-value" id="kpiDiffItems">0</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Unit Difference</div>
        <div class="kpi-value" id="kpiUnitDiff">0</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">Value Difference</div>
        <div class="kpi-value" id="kpiValueDiff">$0.00</div>
      </div>
    </div>

    <div class="method-section mb-3">
      <div class="section-label">CHOOSE COUNT METHOD</div>
      <div class="method-cards" id="methodCards">
        <button type="button" class="method-card active" data-method="manual">
          <i class="bi bi-keyboard"></i>
          <strong>Manual Count</strong>
          <span>Type quantities</span>
        </button>
        <button type="button" class="method-card" data-method="barcode">
          <i class="bi bi-upc-scan"></i>
          <strong>Barcode Scan</strong>
          <span>Scan one-by-one</span>
        </button>
        <button type="button" class="method-card" data-method="rfid">
          <i class="bi bi-broadcast"></i>
          <strong>RFID Scan</strong>
          <span>USB / serial reader</span>
        </button>
      </div>
    </div>

    <div class="toolbar-row mb-2">
      <div class="search-wrap flex-grow-1">
        <i class="bi bi-search"></i>
        <input type="search" id="auditSearch" class="form-control" placeholder="Find product name, SKU, barcode or location">
      </div>
      <button type="button" class="btn btn-accent" id="btnScanFind">
        <i class="bi bi-upc me-1"></i> Scan / Find Product
      </button>
    </div>

    <div id="barcodePanel" class="card-soft p-3 mb-3 d-none">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
        <div>
          <div class="form-label mb-0">Barcode Scan</div>
          <p class="text-muted-sm mb-0">
            Use a USB or Bluetooth barcode scanner (keyboard mode), or type barcode/SKU + Enter.
            Click the box first, then scan — each beep adds +1.
          </p>
        </div>
        <span class="badge-pill badge-green" id="scanReadyBadge">Ready to scan</span>
      </div>
      <div class="scan-input-row">
        <input type="text" id="scanInput" class="form-control form-control-lg scan-input"
               autocomplete="off" autofocus
               placeholder="Scan with USB/Bluetooth scanner, or type barcode / SKU + Enter"
               aria-label="Barcode scanner input">
        <button type="button" class="btn btn-outline-accent" id="btnScanFocus">
          Focus
        </button>
      </div>
      <div id="lastScan" class="last-scan scan-result mt-2 d-none" aria-live="polite"></div>
    </div>

    <div id="rfidPanel" class="card-soft p-3 mb-3 d-none">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-2">
        <div>
          <div class="form-label mb-0">RFID hardware</div>
          <p class="text-muted-sm mb-0">
            Plug in a USB RFID reader (keyboard/HID), or connect a serial/COM reader in Chrome or Edge.
            Each tag beep counts +1. Tags must match SKU, barcode, or the product’s RFID tag field.
          </p>
        </div>
        <span class="badge-pill badge-blue" id="rfidReadyBadge">Reader idle</span>
      </div>

      <div class="rfid-hw-row mb-3">
        <div class="rfid-status-card">
          <div class="rfid-status-dot" id="rfidStatusDot"></div>
          <div>
            <strong id="rfidStatusTitle">Waiting for USB reader</strong>
            <div class="text-muted-sm" id="rfidStatusHint">Tap a tag — HID readers type the EPC then Enter.</div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-accent" id="btnRfidListen">
            <i class="bi bi-broadcast me-1"></i> Start listening
          </button>
          <button type="button" class="btn btn-outline-accent" id="btnRfidSerial">
            <i class="bi bi-usb-symbol me-1"></i> Connect serial reader
          </button>
          <select id="rfidBaud" class="form-select" style="max-width:140px" title="Serial baud rate">
            <option value="9600">9600 baud</option>
            <option value="115200">115200 baud</option>
            <option value="57600">57600 baud</option>
            <option value="38400">38400 baud</option>
          </select>
        </div>
      </div>

      <div class="scan-input-row mb-3">
        <input type="text" id="rfidScanInput" class="form-control form-control-lg scan-input"
               autocomplete="off"
               placeholder="Reader output appears here — or type a tag / SKU and press Enter"
               aria-label="RFID scanner input">
        <button type="button" class="btn btn-outline-accent" id="btnRfidFocus">
          Focus
        </button>
      </div>
      <div id="lastRfidScan" class="last-scan scan-result mt-0 mb-3 d-none" aria-live="polite"></div>
      <div class="rfid-live-log mb-3 d-none" id="rfidLiveLog"></div>

      <div class="section-label">BULK IMPORT</div>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <label class="form-label mb-0">Paste tags (one per line) or CSV <code>sku,qty</code> / <code>barcode,qty</code> / <code>epc,qty</code></label>
        <a class="btn btn-sm btn-outline-accent" href="<?= htmlspecialchars(app_url('api/audit_bulk_import.php?template=1')) ?>">
          <i class="bi bi-download me-1"></i> Download template
        </a>
      </div>
      <textarea id="rfidCsv" class="form-control mb-2" rows="5" placeholder="C9-1B&#10;C9-1B&#10;MMO-4OZ&#10;803868451092,12&#10;850001265652,15"></textarea>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <input type="file" id="rfidFile" accept=".csv,.txt,text/csv,text/plain,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="form-control" style="max-width:280px">
        <button type="button" class="btn btn-accent" id="btnRfidImport">
          <i class="bi bi-upload me-1"></i> Import bulk
        </button>
        <button type="button" class="btn btn-outline-accent" id="btnRfidSample">Load sample</button>
      </div>
      <div class="text-muted-sm mt-2" id="rfidResult"></div>
    </div>

    <div class="filter-tabs mb-2" id="filterTabs">
      <button type="button" class="filter-tab active" data-filter="all">All Products <span class="tab-badge" id="badgeAll">0</span></button>
      <button type="button" class="filter-tab" data-filter="not_counted">Not Counted <span class="tab-badge" id="badgeNot">0</span></button>
      <button type="button" class="filter-tab" data-filter="counted">Counted <span class="tab-badge" id="badgeCounted">0</span></button>
    </div>

    <div class="table-panel">
      <div class="table-responsive">
        <table class="table-clean audit-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Location</th>
              <th class="num">System Qty</th>
              <th class="num">Physical Count</th>
              <th class="num">Diff Qty</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody id="auditBody">
            <tr><td colspan="6" class="empty-state">Loading…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="legend p-3">
        <span><i class="legend-dot exact"></i> Exact</span>
        <span><i class="legend-dot shortage"></i> Shortage</span>
        <span><i class="legend-dot overage"></i> Overage</span>
        <span><i class="legend-dot not"></i> Not Counted</span>
      </div>
    </div>
  </div>

  <div class="modal fade" id="closeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Close audit & reconcile?</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          This will set each counted product’s system qty to the physical count and log Audit Adjustment movements. Uncounted items are left unchanged.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-accent" id="confirmClose">Close & Reconcile</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="quickAddModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <form class="modal-content" id="quickAddForm" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title">New product from scan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted-sm mb-3">
            Code <strong id="quickAddCodeLabel"></strong> was not in this audit.
            Add it now — image is required. It will be counted as <strong>1</strong> immediately.
          </p>
          <input type="hidden" id="quickAddMethod" value="barcode">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Product name *</label>
              <input class="form-control" id="quick_product_name" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">SKU <span class="text-muted fw-normal">(optional)</span></label>
              <input class="form-control" id="quick_sku" placeholder="Optional if barcode set">
            </div>
            <div class="col-md-3">
              <label class="form-label">Barcode <span class="text-muted fw-normal">(optional)</span></label>
              <input class="form-control" id="quick_barcode" placeholder="Optional if SKU set">
            </div>
            <div class="col-md-4">
              <label class="form-label">RFID tag</label>
              <input class="form-control" id="quick_rfid_tag" placeholder="Optional EPC / TID">
            </div>
            <div class="col-md-4">
              <label class="form-label">Category</label>
              <select class="form-select" id="quick_category_id">
                <option value="">—</option>
                <?php foreach ($categories as $c): ?>
                  <option value="<?= (int) $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Location</label>
              <input class="form-control" id="quick_location_tag" placeholder="A-01">
            </div>
            <div class="col-md-2">
              <label class="form-label">Cost</label>
              <input class="form-control" type="number" min="0" step="0.01" id="quick_unit_cost" value="0">
            </div>
            <div class="col-md-2">
              <label class="form-label">Price</label>
              <input class="form-control" type="number" min="0" step="0.01" id="quick_unit_price" value="0">
            </div>
            <div class="col-12">
              <label class="form-label">Product image *</label>
              <div class="image-upload-row">
                <div class="image-preview-wrap">
                  <img id="quickImagePreview" class="image-preview d-none" alt="Preview">
                  <div id="quickImagePlaceholder" class="image-preview placeholder"><i class="bi bi-image"></i></div>
                </div>
                <div class="flex-grow-1">
                  <input class="form-control" type="file" id="quick_image" accept="image/jpeg,image/png,image/webp,image/gif" required>
                  <div class="form-text">Required. JPG, PNG, WEBP, or GIF — max 3 MB.</div>
                </div>
              </div>
            </div>
          </div>
          <div class="text-danger small mt-2 d-none" id="quickAddError"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-accent" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-accent" id="quickAddSaveBtn">
            <i class="bi bi-plus-lg me-1"></i> Create & count +1
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="toast-wrap" id="toastWrap"></div>

<?php
$pageScripts = '<script src="' . htmlspecialchars(app_url('assets/js/audit.js')) . '?v=20260901c"></script>';
require __DIR__ . '/includes/footer.php';
