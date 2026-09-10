<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/catalog.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());

function compute_audit_status(?int $physical, int $system): array
{
    if ($physical === null) {
        return [
            'diff_qty' => null,
            'diff_value' => null,
            'status' => 'not_counted',
        ];
    }
    $diff = $physical - $system;
    $status = 'exact';
    if ($diff < 0) {
        $status = 'shortage';
    } elseif ($diff > 0) {
        $status = 'overage';
    }
    return [
        'diff_qty' => $diff,
        'diff_value' => null, // filled by caller with unit_cost
        'status' => $status,
    ];
}

if ($method === 'GET') {
    $sessionId = (int) ($_GET['session_id'] ?? 0);
    if ($sessionId <= 0) {
        json_response(['ok' => false, 'error' => 'session_id is required.'], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT ac.count_id, ac.session_id, ac.product_id, ac.physical_qty, ac.count_method, ac.counted_at,
                p.sku, p.barcode, p.rfid_tag, p.product_name, p.location_tag, p.system_qty, p.unit_cost, p.unit_price, p.image_url,
                c.category_name
         FROM audit_counts ac
         JOIN products p ON p.product_id = ac.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE ac.session_id = ? AND p.store_id = ?
         ORDER BY p.product_name ASC"
    );
    $stmt->execute([$sessionId, $storeId]);
    $rows = $stmt->fetchAll();

    $out = [];
    $counted = 0;
    $withDiff = 0;
    $unitDiff = 0;
    $valueDiff = 0.0;

    foreach ($rows as $row) {
        $physical = $row['physical_qty'] === null ? null : (int) $row['physical_qty'];
        $system = (int) $row['system_qty'];
        $cost = (float) $row['unit_cost'];
        $meta = compute_audit_status($physical, $system);
        $diffQty = $meta['diff_qty'];
        $diffValue = $diffQty === null ? null : round($diffQty * $cost, 2);

        if ($physical !== null) {
            $counted++;
            $unitDiff += $diffQty;
            $valueDiff += $diffValue;
            if ($diffQty !== 0) {
                $withDiff++;
            }
        }

        $out[] = with_product_barcodes($pdo, with_product_image_url(array_merge($row, [
            'physical_qty' => $physical,
            'system_qty' => $system,
            'unit_cost' => $cost,
            'diff_qty' => $diffQty,
            'diff_value' => $diffValue,
            'status' => $meta['status'],
        ])));
    }

    json_response([
        'ok' => true,
        'counts' => $out,
        'summary' => [
            'total' => count($out),
            'counted' => $counted,
            'with_difference' => $withDiff,
            'unit_difference' => $unitDiff,
            'value_difference' => round($valueDiff, 2),
        ],
    ]);
}

if ($method === 'POST') {
    $sessionId = (int) ($body['session_id'] ?? 0);
    $productId = (int) ($body['product_id'] ?? 0);
    $methodName = trim((string) ($body['count_method'] ?? 'manual'));
    $increment = !empty($body['increment']);
    $hasQty = array_key_exists('physical_qty', $body);

    if ($sessionId <= 0 || $productId <= 0) {
        json_response(['ok' => false, 'error' => 'session_id and product_id are required.'], 422);
    }

    $sess = $pdo->prepare("SELECT session_id, status FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved')");
    $sess->execute([$sessionId, $storeId]);
    $sessionRow = $sess->fetch();
    if (!$sessionRow) {
        json_response(['ok' => false, 'error' => 'Audit session is not editable.'], 409);
    }
    // Any count activity after Save puts the audit back in progress (re-locks stock)
    if (($sessionRow['status'] ?? '') === 'saved') {
        $pdo->prepare("UPDATE audit_sessions SET status = 'in_progress' WHERE session_id = ? AND store_id = ?")
            ->execute([$sessionId, $storeId]);
    }

    $rowStmt = $pdo->prepare(
        "SELECT ac.*, p.system_qty, p.unit_cost, p.product_name, p.sku, p.barcode, p.rfid_tag, p.image_url
         FROM audit_counts ac
         JOIN products p ON p.product_id = ac.product_id
         WHERE ac.session_id = ? AND ac.product_id = ?"
    );
    $rowStmt->execute([$sessionId, $productId]);
    $row = $rowStmt->fetch();

    // Product exists in catalog but not yet in this audit → attach it
    if (!$row) {
        $prod = $pdo->prepare(
            'SELECT product_id, system_qty, unit_cost, product_name, sku, barcode, rfid_tag, image_url, is_active
             FROM products WHERE product_id = ? AND store_id = ?'
        );
        $prod->execute([$productId, $storeId]);
        $p = $prod->fetch();
        if (!$p) {
            json_response(['ok' => false, 'error' => 'Count row not found.'], 404);
        }
        if (!(bool)$p['is_active']) {
            json_response(['ok' => false, 'error' => 'This product has been deleted and cannot be counted.'], 409);
        }
        $pdo->prepare(
            'INSERT INTO audit_counts (session_id, product_id, physical_qty, count_method, counted_at)
             VALUES (?, ?, NULL, NULL, NULL)'
        )->execute([$sessionId, $productId]);
        $rowStmt->execute([$sessionId, $productId]);
        $row = $rowStmt->fetch();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Failed to attach product to audit.'], 500);
        }
    }

    $currentPhysical = $row['physical_qty'] === null ? null : (int) $row['physical_qty'];

    if ($increment) {
        $newQty = ($currentPhysical ?? 0) + 1;
        $methodName = $methodName !== '' ? $methodName : 'barcode';
    } elseif ($hasQty) {
        if ($body['physical_qty'] === null || $body['physical_qty'] === '') {
            $newQty = null;
        } else {
            $newQty = max(0, (int) $body['physical_qty']);
        }
        $methodName = $methodName !== '' ? $methodName : 'manual';
    } else {
        json_response(['ok' => false, 'error' => 'Provide physical_qty or increment=1.'], 422);
    }

    $upd = $pdo->prepare(
        'UPDATE audit_counts
         SET physical_qty = ?, count_method = ?, counted_at = CASE WHEN ? IS NULL THEN NULL ELSE NOW() END
         WHERE session_id = ? AND product_id = ?'
    );
    $upd->execute([$newQty, $newQty === null ? null : $methodName, $newQty, $sessionId, $productId]);

    $system = (int) $row['system_qty'];
    $cost = (float) $row['unit_cost'];
    $meta = compute_audit_status($newQty, $system);
    $diffQty = $meta['diff_qty'];
    $diffValue = $diffQty === null ? null : round($diffQty * $cost, 2);

    json_response([
        'ok' => true,
        'count' => with_product_barcodes($pdo, with_product_image_url([
            'session_id' => $sessionId,
            'product_id' => $productId,
            'product_name' => $row['product_name'],
            'sku' => $row['sku'],
            'barcode' => $row['barcode'],
            'rfid_tag' => $row['rfid_tag'] ?? null,
            'image_url' => $row['image_url'],
            'physical_qty' => $newQty,
            'system_qty' => $system,
            'diff_qty' => $diffQty,
            'diff_value' => $diffValue,
            'status' => $meta['status'],
            'count_method' => $newQty === null ? null : $methodName,
        ])),
    ]);
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
