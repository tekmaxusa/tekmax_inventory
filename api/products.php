<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/catalog.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// Support JSON body OR multipart form (for image upload)
$jsonBody = read_json_body();
$body = array_merge($jsonBody, $_POST);
if (isset($body['_method'])) {
    $method = strtoupper((string) $body['_method']);
}

function product_payload(PDO $pdo, array $row): array
{
    $withImg = with_product_image_url($row);
    $withBc = with_product_barcodes($pdo, $withImg);
    return $withBc ?: $withImg;
}

if ($method === 'GET') {
    $search = trim((string) ($_GET['search'] ?? ''));
    $category = $_GET['category'] ?? '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

    if ($id > 0) {
        $stmt = $pdo->prepare(
            "SELECT p.*, c.category_name
             FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ? AND p.store_id = ? AND p.is_active = 1"
        );
        $stmt->execute([$id, $storeId]);
        $row = $stmt->fetch();
        if (!$row) {
            json_response(['ok' => false, 'error' => 'Product not found.'], 404);
        }
        json_response(['ok' => true, 'product' => product_payload($pdo, $row)]);
    }

    // Exact lookup by barcode or SKU (for scan unknown flow)
    $code = trim((string) ($_GET['code'] ?? $_GET['barcode'] ?? ''));
    if ($code !== '') {
        $needle = strtolower($code);
        // Check active products first
        $stmt = $pdo->prepare(
            "SELECT p.*, c.category_name
             FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.is_active = 1 AND p.store_id = ?
               AND " . product_code_sql('p') . "
             LIMIT 1"
        );
        $stmt->execute(array_merge([$storeId], product_code_params($needle)));
        $row = $stmt->fetch();
        if ($row) {
            json_response(['ok' => true, 'found' => true, 'product' => product_payload($pdo, $row)]);
        }

        // Check if the code belongs to a soft-deleted product — surface a clear error
        // so the caller knows NOT to quick-add (it would hit a unique-key conflict).
        $stmtDel = $pdo->prepare(
            "SELECT p.product_id, p.product_name
             FROM products p
             WHERE p.is_active = 0 AND p.store_id = ?
               AND " . product_code_sql('p') . "
             LIMIT 1"
        );
        $stmtDel->execute(array_merge([$storeId], product_code_params($needle)));
        $deleted = $stmtDel->fetch();
        if ($deleted) {
            json_response([
                'ok'      => true,
                'found'   => false,
                'deleted' => true,
                'product' => null,
                'error'   => 'This product was deleted (' . $deleted['product_name'] . '). Restore it in the Products page before scanning.',
            ]);
        }

        json_response(['ok' => true, 'found' => false, 'product' => null]);
    }

    $sql = "SELECT p.*, c.category_name
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE p.is_active = 1 AND p.store_id = ?";
    $params = [$storeId];

    if ($search !== '') {
        $sql .= " AND (
            p.product_name LIKE ?
            OR IFNULL(p.sku,'') LIKE ?
            OR IFNULL(p.barcode,'') LIKE ?
            OR p.location_tag LIKE ?
            OR IFNULL(p.rfid_tag,'') LIKE ?
            OR EXISTS (
              SELECT 1 FROM product_barcodes pb
              WHERE pb.product_id = p.product_id AND pb.barcode LIKE ?
            )
        )";
        $like = '%' . $search . '%';
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    }
    if ($category !== '' && $category !== 'all') {
        $sql .= " AND p.category_id = ?";
        $params[] = (int) $category;
    }

    $sql .= " ORDER BY p.product_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = [];
    foreach ($stmt->fetchAll() as $row) {
        $products[] = product_payload($pdo, $row);
    }
    json_response(['ok' => true, 'products' => $products]);
}

if ($method === 'POST') {
    $sku = trim((string) ($body['sku'] ?? ''));
    $barcodes = barcodes_from_request($body);
    $name = trim((string) ($body['product_name'] ?? ''));
    $categoryId = $body['category_id'] !== '' && $body['category_id'] !== null ? (int) $body['category_id'] : null;
    $location = trim((string) ($body['location_tag'] ?? ''));
    $qty = (int) ($body['system_qty'] ?? 0);
    $reorder = (int) ($body['reorder_level'] ?? 5);
    $cost = (float) ($body['unit_cost'] ?? 0);
    $price = (float) ($body['unit_price'] ?? 0);
    $rfidTag = trim((string) ($body['rfid_tag'] ?? ''));
    $rfidTag = $rfidTag === '' ? null : $rfidTag;

    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Product name is required.'], 422);
    }
    $ids = resolve_product_identifiers($sku, $barcodes);
    if (!$ids['ok']) {
        json_response(['ok' => false, 'error' => $ids['error']], 422);
    }

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        json_response(['ok' => false, 'error' => 'Product image is required.'], 422);
    }
    $saved = save_product_image($_FILES['image']);
    if (!$saved['ok']) {
        json_response(['ok' => false, 'error' => $saved['error']], 422);
    }
    $imagePath = $saved['path'];

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "INSERT INTO products
             (store_id, sku, barcode, rfid_tag, product_name, category_id, location_tag, system_qty, reorder_level, unit_cost, unit_price, image_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $openingQty = max(0, $qty);
        $stmt->execute([
            $storeId, $ids['sku'], $ids['primary'], $rfidTag, $name, $categoryId, $location ?: null,
            $openingQty, $reorder, $cost, $price, $imagePath,
        ]);
        $newId = (int) $pdo->lastInsertId();
        sync_product_barcodes($pdo, $storeId, $newId, $ids['barcodes']);

        if ($openingQty > 0) {
            $mv = $pdo->prepare(
                "INSERT INTO stock_movements (store_id, product_id, movement_type, quantity, reason, reference_no, performed_by)
                 VALUES (?, ?, 'in', ?, 'Opening stock', NULL, ?)"
            );
            $mv->execute([$storeId, $newId, $openingQty, (int) $_SESSION['user_id']]);
        }

        $pdo->commit();

        $stmt = $pdo->prepare(
            "SELECT p.*, c.category_name FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ?"
        );
        $stmt->execute([$newId]);
        json_response(['ok' => true, 'product' => product_payload($pdo, $stmt->fetch())], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        delete_product_image_file($imagePath);
        if ((int) $e->errorInfo[1] === 1062) {
            json_response(['ok' => false, 'error' => 'SKU, barcode, or RFID tag already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to create product.'], 500);
    }
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['product_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'product_id is required.'], 422);
    }

    $sku = trim((string) ($body['sku'] ?? ''));
    $barcodes = barcodes_from_request($body);
    $name = trim((string) ($body['product_name'] ?? ''));
    $categoryId = $body['category_id'] !== '' && $body['category_id'] !== null ? (int) $body['category_id'] : null;
    $location = trim((string) ($body['location_tag'] ?? ''));
    $reorder = (int) ($body['reorder_level'] ?? 5);
    $cost = (float) ($body['unit_cost'] ?? 0);
    $price = (float) ($body['unit_price'] ?? 0);
    $rfidTag = trim((string) ($body['rfid_tag'] ?? ''));
    $rfidTag = $rfidTag === '' ? null : $rfidTag;

    if ($name === '') {
        json_response(['ok' => false, 'error' => 'Product name is required.'], 422);
    }
    $ids = resolve_product_identifiers($sku, $barcodes);
    if (!$ids['ok']) {
        json_response(['ok' => false, 'error' => $ids['error']], 422);
    }

    $existing = $pdo->prepare('SELECT * FROM products WHERE product_id = ? AND store_id = ? AND is_active = 1');
    $existing->execute([$id, $storeId]);
    $current = $existing->fetch();
    if (!$current) {
        json_response(['ok' => false, 'error' => 'Product not found.'], 404);
    }

    $imagePath = $current['image_url'];
    $oldImage = $current['image_url'];
    $hasNewUpload = !empty($_FILES['image']) && is_array($_FILES['image'])
        && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if ($hasNewUpload) {
        $saved = save_product_image($_FILES['image']);
        if (!$saved['ok']) {
            json_response(['ok' => false, 'error' => $saved['error']], 422);
        }
        $imagePath = $saved['path'];
    } elseif ($imagePath === null || trim((string) $imagePath) === '') {
        json_response(['ok' => false, 'error' => 'Product image is required. Please upload an image.'], 422);
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "UPDATE products SET
               sku = ?, barcode = ?, rfid_tag = ?, product_name = ?, category_id = ?, location_tag = ?,
               reorder_level = ?, unit_cost = ?, unit_price = ?, image_url = ?
             WHERE product_id = ? AND store_id = ? AND is_active = 1"
        );
        $stmt->execute([
            $ids['sku'], $ids['primary'], $rfidTag, $name, $categoryId, $location ?: null,
            $reorder, $cost, $price, $imagePath, $id, $storeId,
        ]);
        sync_product_barcodes($pdo, $storeId, $id, $ids['barcodes']);
        $pdo->commit();

        if ($hasNewUpload && $oldImage && $oldImage !== $imagePath) {
            delete_product_image_file($oldImage);
        }

        $stmt = $pdo->prepare(
            "SELECT p.*, c.category_name FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ?"
        );
        $stmt->execute([$id]);
        json_response(['ok' => true, 'product' => product_payload($pdo, $stmt->fetch())]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($hasNewUpload) {
            delete_product_image_file($imagePath);
        }
        if ((int) $e->errorInfo[1] === 1062) {
            json_response(['ok' => false, 'error' => 'SKU, barcode, or RFID tag already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to update product.'], 500);
    }
}

if ($method === 'DELETE') {
    if (!is_admin()) {
        forbid('Only admins can delete products.');
    }
    $id = (int) ($body['product_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'product_id is required.'], 422);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('UPDATE products SET is_active = 0 WHERE product_id = ? AND store_id = ?');
        $stmt->execute([$id, $storeId]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_response(['ok' => false, 'error' => 'Product not found.'], 404);
        }

        // Remove from any open/saved audit sessions so the product can be
        // re-scanned (quick-add) without hitting the unique-key conflict.
        $pdo->prepare(
            "DELETE ac FROM audit_counts ac
             JOIN audit_sessions s ON s.session_id = ac.session_id
             WHERE ac.product_id = ? AND s.store_id = ? AND s.status IN ('in_progress','saved')"
        )->execute([$id, $storeId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['ok' => false, 'error' => 'Failed to delete product.'], 500);
    }

    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
