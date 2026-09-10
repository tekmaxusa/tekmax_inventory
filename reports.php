<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$tab = $_GET['tab'] ?? 'valuation';
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$export = $_GET['export'] ?? '';

// —— CSV exports ——
if ($export === 'valuation') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_valuation_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SKU', 'Product', 'Category', 'Location', 'Qty', 'Unit Cost', 'Value']);
    $valStmt = $pdo->prepare(
        "SELECT p.sku, p.product_name, c.category_name, p.location_tag, p.system_qty, p.unit_cost,
                (p.system_qty * p.unit_cost) AS value
         FROM products p
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE p.is_active = 1 AND p.store_id = ?
         ORDER BY p.product_name"
    );
    $valStmt->execute([$storeId]);
    $rows = $valStmt->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['sku'], $r['product_name'], $r['category_name'], $r['location_tag'], $r['system_qty'], $r['unit_cost'], $r['value']]);
    }
    fclose($out);
    exit;
}

if ($export === 'movements') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="movements_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'SKU', 'Product', 'Type', 'Qty', 'Reason', 'Reference', 'Performed By']);
    $sql = "SELECT sm.*, p.sku, p.product_name, u.name AS performed_by_name
            FROM stock_movements sm
            JOIN products p ON p.product_id = sm.product_id
            LEFT JOIN users u ON u.user_id = sm.performed_by
            WHERE sm.store_id = ?";
    $params = [$storeId];
    if ($from !== '') {
        $sql .= ' AND DATE(sm.created_at) >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $sql .= ' AND DATE(sm.created_at) <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY sm.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [
            $r['created_at'], $r['sku'], $r['product_name'], $r['movement_type'],
            $r['quantity'], $r['reason'], $r['reference_no'], $r['performed_by_name'],
        ]);
    }
    fclose($out);
    exit;
}

if ($export === 'low_stock') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="low_stock_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SKU', 'Product', 'Location', 'Qty', 'Reorder Level']);
    $lowExport = $pdo->prepare(
        "SELECT sku, product_name, location_tag, system_qty, reorder_level
         FROM products WHERE is_active = 1 AND store_id = ? AND system_qty <= reorder_level
         ORDER BY system_qty ASC"
    );
    $lowExport->execute([$storeId]);
    $rows = $lowExport->fetchAll();
    foreach ($rows as $r) {
        fputcsv($out, [$r['sku'], $r['product_name'], $r['location_tag'], $r['system_qty'], $r['reorder_level']]);
    }
    fclose($out);
    exit;
}

$valPage = $pdo->prepare(
    "SELECT p.*, c.category_name, (p.system_qty * p.unit_cost) AS stock_value
     FROM products p
     LEFT JOIN categories c ON c.category_id = p.category_id
     WHERE p.is_active = 1 AND p.store_id = ?
     ORDER BY p.product_name"
);
$valPage->execute([$storeId]);
$valuation = $valPage->fetchAll();
$totalValue = 0.0;
foreach ($valuation as $v) {
    $totalValue += (float) $v['stock_value'];
}

$movSql = "SELECT sm.*, p.sku, p.product_name, u.name AS performed_by_name
           FROM stock_movements sm
           JOIN products p ON p.product_id = sm.product_id
           LEFT JOIN users u ON u.user_id = sm.performed_by
           WHERE sm.store_id = ?";
$movParams = [$storeId];
if ($from !== '') {
    $movSql .= ' AND DATE(sm.created_at) >= ?';
    $movParams[] = $from;
}
if ($to !== '') {
    $movSql .= ' AND DATE(sm.created_at) <= ?';
    $movParams[] = $to;
}
$movSql .= ' ORDER BY sm.created_at DESC LIMIT 300';
$movStmt = $pdo->prepare($movSql);
$movStmt->execute($movParams);
$movements = $movStmt->fetchAll();

$lowPage = $pdo->prepare(
    "SELECT * FROM products WHERE is_active = 1 AND store_id = ? AND system_qty <= reorder_level ORDER BY system_qty ASC"
);
$lowPage->execute([$storeId]);
$lowStock = $lowPage->fetchAll();

$pageTitle = 'Reports';
$pageSubtitle = 'Stock valuation, movements, and low stock';
$currentPage = 'reports';
require __DIR__ . '/includes/header.php';
?>

<div class="filter-tabs mb-3">
  <a class="filter-tab <?= $tab === 'valuation' ? 'active' : '' ?>" href="?tab=valuation">Stock Valuation</a>
  <a class="filter-tab <?= $tab === 'movements' ? 'active' : '' ?>" href="?tab=movements">Movement History</a>
  <a class="filter-tab <?= $tab === 'low' ? 'active' : '' ?>" href="?tab=low">Low Stock</a>
</div>

<?php if ($tab === 'valuation'): ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="kpi-card" style="min-width:200px">
      <div class="kpi-label">Total Value</div>
      <div class="kpi-value">$<?= number_format($totalValue, 2) ?></div>
    </div>
    <a class="btn btn-outline-accent" href="?tab=valuation&export=valuation">
      <i class="bi bi-download me-1"></i> Export CSV
    </a>
  </div>
  <div class="table-panel">
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Product</th>
            <th>Category</th>
            <th>Location</th>
            <th class="num">Qty</th>
            <th class="num">Unit Cost</th>
            <th class="num">Value</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$valuation): ?>
            <tr><td colspan="6" class="empty-state">No products.</td></tr>
          <?php else: foreach ($valuation as $r): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars($r['product_name']) ?></strong>
                <div class="text-muted-sm"><?= htmlspecialchars($r['sku']) ?></div>
              </td>
              <td><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
              <td><span class="loc-pill"><?= htmlspecialchars($r['location_tag'] ?? '—') ?></span></td>
              <td class="num"><?= (int) $r['system_qty'] ?></td>
              <td class="num">$<?= number_format((float) $r['unit_cost'], 2) ?></td>
              <td class="num">$<?= number_format((float) $r['stock_value'], 2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'movements'): ?>
  <form class="toolbar-row mb-3" method="get">
    <input type="hidden" name="tab" value="movements">
    <input type="date" name="from" class="form-control" style="max-width:160px" value="<?= htmlspecialchars($from) ?>">
    <input type="date" name="to" class="form-control" style="max-width:160px" value="<?= htmlspecialchars($to) ?>">
    <button class="btn btn-outline-accent" type="submit">Apply</button>
    <a class="btn btn-outline-accent" href="?tab=movements&export=movements&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
      <i class="bi bi-download me-1"></i> Export CSV
    </a>
  </form>
  <div class="table-panel">
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>When</th>
            <th>Product</th>
            <th>Type</th>
            <th class="num">Qty</th>
            <th>Reason</th>
            <th>By</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$movements): ?>
            <tr><td colspan="6" class="empty-state">No movements in range.</td></tr>
          <?php else: foreach ($movements as $m): ?>
            <tr>
              <td class="text-muted-sm"><?= htmlspecialchars($m['created_at']) ?></td>
              <td>
                <strong><?= htmlspecialchars($m['product_name']) ?></strong>
                <div class="text-muted-sm"><?= htmlspecialchars($m['sku']) ?></div>
              </td>
              <td>
                <?php if ($m['movement_type'] === 'in'): ?>
                  <span class="badge-pill badge-green">IN</span>
                <?php else: ?>
                  <span class="badge-pill badge-red">OUT</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= (int) $m['quantity'] ?></td>
              <td><?= htmlspecialchars($m['reason'] ?? '—') ?></td>
              <td><?= htmlspecialchars($m['performed_by_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>
  <div class="d-flex justify-content-end mb-3">
    <a class="btn btn-outline-accent" href="?tab=low&export=low_stock">
      <i class="bi bi-download me-1"></i> Export CSV
    </a>
  </div>
  <div class="table-panel">
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Product</th>
            <th>Location</th>
            <th class="num">Qty</th>
            <th class="num">Reorder</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$lowStock): ?>
            <tr><td colspan="4" class="empty-state">No low-stock items.</td></tr>
          <?php else: foreach ($lowStock as $p): ?>
            <tr class="row-low">
              <td>
                <span class="dot-warn"></span>
                <strong><?= htmlspecialchars($p['product_name']) ?></strong>
                <div class="text-muted-sm"><?= htmlspecialchars($p['sku']) ?></div>
              </td>
              <td><span class="loc-pill"><?= htmlspecialchars($p['location_tag'] ?? '—') ?></span></td>
              <td class="num"><?= (int) $p['system_qty'] ?></td>
              <td class="num"><?= (int) $p['reorder_level'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
