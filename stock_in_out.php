<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/import_ui.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$activeAudit = locking_audit_session();
$prodStmt = $pdo->prepare(
    "SELECT product_id, sku, product_name, system_qty FROM products WHERE store_id = ? AND is_active = 1 ORDER BY product_name"
);
$prodStmt->execute([$storeId]);
$products = $prodStmt->fetchAll();

$pageTitle = 'Stock In / Out';
$pageSubtitle = 'Record what moved in or out of inventory';
$currentPage = 'stock';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card-soft p-3">
      <h2 class="h6 mb-3">New movement</h2>
      <?php if ($activeAudit): ?>
        <div class="alert alert-warning py-2 small mb-3">
          Movements paused while an audit is in progress. Save the audit to unlock Stock In/Out.
        </div>
      <?php endif; ?>
      <form id="stockForm" <?= $activeAudit ? 'class="pe-none opacity-50"' : '' ?>>
        <div class="mb-3">
          <label class="form-label">Product *</label>
          <select class="form-select" name="product_id" id="product_id" required <?= $activeAudit ? 'disabled' : '' ?>>
            <option value="">Select product…</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int) $p['product_id'] ?>" data-qty="<?= (int) $p['system_qty'] ?>">
                <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>) — qty <?= (int) $p['system_qty'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Type *</label>
          <div class="btn-group w-100" role="group">
            <input type="radio" class="btn-check" name="movement_type" id="typeIn" value="in" checked <?= $activeAudit ? 'disabled' : '' ?>>
            <label class="btn btn-outline-success" for="typeIn">Stock In</label>
            <input type="radio" class="btn-check" name="movement_type" id="typeOut" value="out" <?= $activeAudit ? 'disabled' : '' ?>>
            <label class="btn btn-outline-danger" for="typeOut">Stock Out</label>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Quantity *</label>
          <input type="number" class="form-control" name="quantity" id="quantity" min="1" value="1" required <?= $activeAudit ? 'disabled' : '' ?>>
        </div>
        <div class="mb-3">
          <label class="form-label">Reason</label>
          <select class="form-select" name="reason" id="reason" <?= $activeAudit ? 'disabled' : '' ?>>
            <option>Purchase</option>
            <option>Sale</option>
            <option>Return</option>
            <option>Damaged</option>
            <option>Adjustment</option>
            <option>Other</option>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label">Reference No.</label>
          <input type="text" class="form-control" name="reference_no" id="reference_no" placeholder="PO / Invoice #" <?= $activeAudit ? 'disabled' : '' ?>>
        </div>
        <div class="text-danger small mb-2 d-none" id="stockError"></div>
        <button type="submit" class="btn btn-accent w-100" <?= $activeAudit ? 'disabled' : '' ?>>Record movement</button>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$activeAudit): ?>
    <?php
    render_bulk_import_panel([
        'api' => '/api/stock_movements_import.php',
        'title' => 'Bulk import stock movements',
        'description' => 'Upload CSV or Excel (.xlsx) to record multiple stock in/out rows.',
        'template_url' => app_url('api/stock_movements_import.php?template=1'),
        'columns' => ['sku', 'barcode', 'movement_type', 'quantity', 'reason', 'reference_no'],
        'panel_id' => 'stockImportPanel',
        'textarea_id' => 'stockImportCsv',
        'file_id' => 'stockImportFile',
        'result_id' => 'stockImportResult',
    ]);
    ?>
    <?php endif; ?>
    <div class="toolbar-row mb-3 flex-wrap">
      <select id="filterProduct" class="form-select" style="max-width:220px">
        <option value="">All products</option>
        <?php foreach ($products as $p): ?>
          <option value="<?= (int) $p['product_id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select id="filterType" class="form-select" style="max-width:140px">
        <option value="">All types</option>
        <option value="in">In</option>
        <option value="out">Out</option>
      </select>
      <input type="date" id="filterFrom" class="form-control" style="max-width:160px">
      <input type="date" id="filterTo" class="form-control" style="max-width:160px">
      <button type="button" class="btn btn-outline-accent" id="btnFilter">Filter</button>
    </div>
    <div class="table-panel">
      <div class="panel-head"><h2>Movement history</h2></div>
      <div class="table-responsive">
        <table class="table-clean">
          <thead>
            <tr>
              <th>Product</th>
              <th>Type</th>
              <th class="num">Qty</th>
              <th>Reason</th>
              <th>Ref</th>
              <th>By</th>
              <th>When</th>
            </tr>
          </thead>
          <tbody id="movementsBody">
            <tr><td colspan="7" class="empty-state">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<div class="toast-wrap" id="toastWrap"></div>

<?php
ob_start();
render_bulk_import_script();
$pageScripts = ob_get_clean() . '<script src="' . htmlspecialchars(app_url('assets/js/stock.js')) . '"></script>';
require __DIR__ . '/includes/footer.php';
