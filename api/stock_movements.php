<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());

if ($method === 'GET') {
    $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int) $_GET['product_id'] : null;
    $type = $_GET['type'] ?? '';
    $from = trim((string) ($_GET['from'] ?? ''));
    $to = trim((string) ($_GET['to'] ?? ''));

    $sql = "SELECT sm.*, p.product_name, p.sku, u.name AS performed_by_name
            FROM stock_movements sm
            JOIN products p ON p.product_id = sm.product_id
            LEFT JOIN users u ON u.user_id = sm.performed_by
            WHERE sm.store_id = ?";
    $params = [$storeId];

    if ($productId) {
        $sql .= ' AND sm.product_id = ?';
        $params[] = $productId;
    }
    if ($type === 'in' || $type === 'out') {
        $sql .= ' AND sm.movement_type = ?';
        $params[] = $type;
    }
    if ($from !== '') {
        $sql .= ' AND DATE(sm.created_at) >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $sql .= ' AND DATE(sm.created_at) <= ?';
        $params[] = $to;
    }

    $sql .= ' ORDER BY sm.created_at DESC, sm.movement_id DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_response(['ok' => true, 'movements' => $stmt->fetchAll()]);
}

if ($method === 'POST') {
    $active = locking_audit_session();
    if ($active) {
        json_response([
            'ok' => false,
            'error' => 'Audit in progress — stock movements are paused. Save or close the audit to continue.',
        ], 423);
    }

    $productId = (int) ($body['product_id'] ?? 0);
    $type = strtolower(trim((string) ($body['movement_type'] ?? '')));
    $qty = (int) ($body['quantity'] ?? 0);
    $reason = trim((string) ($body['reason'] ?? ''));
    $ref = trim((string) ($body['reference_no'] ?? ''));

    if ($productId <= 0 || !in_array($type, ['in', 'out'], true) || $qty <= 0) {
        json_response(['ok' => false, 'error' => 'Valid product, type (in/out), and quantity > 0 are required.'], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT product_id, system_qty, product_name FROM products WHERE product_id = ? AND store_id = ? AND is_active = 1 FOR UPDATE');
        $stmt->execute([$productId, $storeId]);
        $product = $stmt->fetch();
        if (!$product) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Product not found.'], 404);
        }

        $current = (int) $product['system_qty'];
        $newQty = $type === 'in' ? $current + $qty : $current - $qty;
        if ($newQty < 0) {
            $pdo->rollBack();
            json_response([
                'ok' => false,
                'error' => "Insufficient stock. Available: {$current}, requested out: {$qty}.",
            ], 422);
        }

        $ins = $pdo->prepare(
            'INSERT INTO stock_movements (store_id, product_id, movement_type, quantity, reason, reference_no, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $storeId,
            $productId,
            $type,
            $qty,
            $reason !== '' ? $reason : null,
            $ref !== '' ? $ref : null,
            (int) $_SESSION['user_id'],
        ]);
        $movementId = (int) $pdo->lastInsertId();

        $upd = $pdo->prepare('UPDATE products SET system_qty = ? WHERE product_id = ?');
        $upd->execute([$newQty, $productId]);

        $pdo->commit();

        json_response([
            'ok' => true,
            'movement_id' => $movementId,
            'system_qty' => $newQty,
            'product_name' => $product['product_name'],
        ], 201);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['ok' => false, 'error' => 'Failed to record movement.'], 500);
    }
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
