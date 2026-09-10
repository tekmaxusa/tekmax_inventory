<?php
/**
 * Quick-add a brand-new product during an open audit (scan unknown code).
 * Creates product (image required) + audit_counts row with physical_qty = 1.
 *
 * POST multipart:
 *   session_id, product_name, sku, barcode, image (required)
 *   optional: rfid_tag, location_tag, unit_cost, unit_price, reorder_level, category_id, count_method
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/catalog.php';
require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$pdo = db();
$storeId = store_id_param();
$body = $_POST;
$sessionId = (int) ($body['session_id'] ?? 0);
$name = trim((string) ($body['product_name'] ?? ''));
$sku = trim((string) ($body['sku'] ?? ''));
$barcode = trim((string) ($body['barcode'] ?? ''));
$rfidTag = trim((string) ($body['rfid_tag'] ?? ''));
$rfidTag = $rfidTag === '' ? null : $rfidTag;
$location = trim((string) ($body['location_tag'] ?? ''));
$categoryId = $body['category_id'] !== '' && isset($body['category_id']) ? (int) $body['category_id'] : null;
$cost = (float) ($body['unit_cost'] ?? 0);
$price = (float) ($body['unit_price'] ?? 0);
$reorder = (int) ($body['reorder_level'] ?? 5);
$methodName = trim((string) ($body['count_method'] ?? 'barcode'));
if ($methodName === '') {
    $methodName = 'barcode';
}

if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'session_id is required.'], 422);
}
if ($name === '') {
    json_response(['ok' => false, 'error' => 'Product name is required.'], 422);
}
$ids = resolve_product_identifiers($sku, barcodes_from_request($body));
if (!$ids['ok']) {
    json_response(['ok' => false, 'error' => $ids['error']], 422);
}
if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
    json_response(['ok' => false, 'error' => 'Product image is required.'], 422);
}

$sess = $pdo->prepare("SELECT session_id FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved')");
$sess->execute([$sessionId, $storeId]);
if (!$sess->fetch()) {
    json_response(['ok' => false, 'error' => 'Audit session is not editable.'], 409);
}

$saved = save_product_image($_FILES['image']);
if (!$saved['ok']) {
    json_response(['ok' => false, 'error' => $saved['error']], 422);
}
$imagePath = $saved['path'];

try {
    $pdo->beginTransaction();

    $ins = $pdo->prepare(
        "INSERT INTO products
         (store_id, sku, barcode, rfid_tag, product_name, category_id, location_tag, system_qty, reorder_level, unit_cost, unit_price, image_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)"
    );
    $ins->execute([
        $storeId,
        $ids['sku'],
        $ids['primary'],
        $rfidTag,
        $name,
        $categoryId,
        $location !== '' ? $location : null,
        $reorder,
        $cost,
        $price,
        $imagePath,
    ]);
    $productId = (int) $pdo->lastInsertId();
    sync_product_barcodes($pdo, $storeId, $productId, $ids['barcodes']);

    $pdo->prepare(
        "INSERT INTO audit_counts (session_id, product_id, physical_qty, count_method, counted_at)
         VALUES (?, ?, 1, ?, NOW())"
    )->execute([$sessionId, $productId, $methodName]);

    $pdo->commit();

    $stmt = $pdo->prepare(
        "SELECT ac.count_id, ac.session_id, ac.product_id, ac.physical_qty, ac.count_method, ac.counted_at,
                p.sku, p.barcode, p.rfid_tag, p.product_name, p.location_tag, p.system_qty, p.unit_cost, p.unit_price, p.image_url,
                c.category_name
         FROM audit_counts ac
         JOIN products p ON p.product_id = ac.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE ac.session_id = ? AND ac.product_id = ?"
    );
    $stmt->execute([$sessionId, $productId]);
    $row = $stmt->fetch();
    $row = with_product_barcodes($pdo, with_product_image_url($row ?: []));
    $system = (int) ($row['system_qty'] ?? 0);
    $physical = 1;
    $diff = $physical - $system;
    $row['diff_qty'] = $diff;
    $row['diff_value'] = round($diff * (float) ($row['unit_cost'] ?? 0), 2);
    $row['status'] = $diff === 0 ? 'exact' : ($diff < 0 ? 'shortage' : 'overage');

    json_response([
        'ok' => true,
        'created' => true,
        'count' => $row,
        'product' => [
            'product_id' => $productId,
            'product_name' => $name,
            'sku' => $ids['sku'],
            'barcode' => $ids['primary'],
            'barcodes' => $ids['barcodes'],
            'image_url' => $row['image_url'] ?? null,
        ],
    ], 201);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    delete_product_image_file($imagePath);
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_response(['ok' => false, 'error' => 'SKU, barcode, or RFID tag already exists. Try scanning again.'], 409);
    }
    json_response(['ok' => false, 'error' => 'Failed to create product.'], 500);
}
