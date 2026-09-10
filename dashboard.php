<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND store_id = ?');
$countStmt->execute([$storeId]);
$totalProducts = (int) $countStmt->fetchColumn();

$valueStmt = $pdo->prepare('SELECT COALESCE(SUM(system_qty * unit_cost), 0) FROM products WHERE is_active = 1 AND store_id = ?');
$valueStmt->execute([$storeId]);
$stockValue = (float) $valueStmt->fetchColumn();

$lowStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM products WHERE is_active = 1 AND store_id = ? AND system_qty > 0 AND system_qty <= reorder_level'
);
$lowStmt->execute([$storeId]);
$lowStock = (int) $lowStmt->fetchColumn();

$outStmt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE is_active = 1 AND store_id = ? AND system_qty = 0');
$outStmt->execute([$storeId]);
$outOfStock = (int) $outStmt->fetchColumn();

$recentStmt = $pdo->prepare(
    "SELECT sm.movement_id, sm.movement_type, sm.quantity, sm.reason, sm.reference_no, sm.created_at,
            p.product_name, p.sku,
            u.name AS performed_by_name
     FROM stock_movements sm
     JOIN products p ON p.product_id = sm.product_id
     LEFT JOIN users u ON u.user_id = sm.performed_by
     WHERE sm.store_id = ?
     ORDER BY sm.created_at DESC, sm.movement_id DESC
     LIMIT 10"
);
$recentStmt->execute([$storeId]);
$recent = $recentStmt->fetchAll();

$lowListStmt = $pdo->prepare(
    "SELECT product_id, sku, product_name, system_qty, reorder_level, location_tag
     FROM products
     WHERE is_active = 1 AND store_id = ? AND system_qty <= reorder_level
     ORDER BY system_qty ASC, product_name ASC
     LIMIT 20"
);
$lowListStmt->execute([$storeId]);
$lowList = $lowListStmt->fetchAll();

$pageTitle = 'Dashboard';
$pageSubtitle = 'At-a-glance stock health and recent activity';
$currentPage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-label">Total Products</div>
    <div class="kpi-value"><?= number_format($totalProducts) ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Total Stock Value</div>
    <div class="kpi-value">$<?= number_format($stockValue, 2) ?></div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Low Stock Items</div>
    <div class="kpi-value"><?= number_format($lowStock) ?></div>
    <div class="kpi-hint">At or below reorder level</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-label">Out of Stock</div>
    <div class="kpi-value"><?= number_format($outOfStock) ?></div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="table-panel">
      <div class="panel-head">
        <h2>Recent stock movements</h2>
      </div>
      <?php if (!$recent): ?>
        <div class="empty-state">No movements yet. Record stock in/out to see history here.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table-clean">
            <thead>
              <tr>
                <th>Product</th>
                <th>Type</th>
                <th class="num">Qty</th>
                <th>Reason</th>
                <th>When</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent as $m): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string) ($m['product_name'] ?? '')) ?></strong>
                    <div class="text-muted-sm"><?= htmlspecialchars((string) ($m['sku'] ?? '')) ?></div>
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
                  <td class="text-muted-sm"><?= htmlspecialchars(date('M j, g:ia', strtotime($m['created_at']))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="table-panel">
      <div class="panel-head">
        <h2>Low stock alerts</h2>
      </div>
      <?php if (!$lowList): ?>
        <div class="empty-state">All products are above reorder levels.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table-clean">
            <thead>
              <tr>
                <th>Product</th>
                <th class="num">Qty</th>
                <th class="num">Reorder</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lowList as $p): ?>
                <tr>
                  <td>
                    <span class="dot-warn"></span>
                    <strong><?= htmlspecialchars((string) ($p['product_name'] ?? '')) ?></strong>
                    <div class="text-muted-sm"><?= htmlspecialchars((string) ($p['sku'] ?? '')) ?> · <?= htmlspecialchars((string) ($p['location_tag'] ?? '—')) ?></div>
                  </td>
                  <td class="num"><?= (int) $p['system_qty'] ?></td>
                  <td class="num"><?= (int) $p['reorder_level'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
