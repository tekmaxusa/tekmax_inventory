<?php
/**
 * Dashboard summary JSON
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
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

json_response([
    'ok' => true,
    'summary' => [
        'total_products' => $totalProducts,
        'stock_value' => round($stockValue, 2),
        'low_stock' => $lowStock,
        'out_of_stock' => $outOfStock,
    ],
    'recent_movements' => $recent,
    'low_stock_items' => $lowList,
]);
