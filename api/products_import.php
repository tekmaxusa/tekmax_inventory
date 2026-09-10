<?php
/**
 * Bulk import products from CSV / XLSX.
 *
 * GET ?template=1  → download CSV template
 * POST multipart: file and/or csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/spreadsheet_import.php';
require_once __DIR__ . '/../includes/catalog.php';
require_login();
require_admin();

$pdo = db();
$storeId = store_id_param();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['template'])) {
    import_send_csv_template('products_import_template.csv', [
        'product_name', 'sku', 'barcode', 'category', 'location_tag',
        'system_qty', 'reorder_level', 'unit_cost', 'unit_price', 'rfid_tag',
    ], [
        ['Sensationnel Cloud 9 Wig', 'C9-1B', '803868451092', 'Wigs', 'A-01', '10', '5', '24.99', '39.99', ''],
        ['Mielle Rosemary Mint Oil', 'MMO-4OZ', '850001265652', 'Hair Oil', 'B-12', '15', '5', '8.50', '14.99', ''],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$loaded = import_load_rows_from_request();
if (!$loaded['ok']) {
    json_response($loaded, 422);
}

$rows = $loaded['rows'];
if ($rows === []) {
    json_response(['ok' => false, 'error' => 'No data rows found. Include a header row.'], 422);
}

$defaultImage = 'uploads/products/seed_C9-1B.svg';
$categoryCache = [];
$created = 0;
$skipped = [];
$userId = (int) $_SESSION['user_id'];

$findCategory = $pdo->prepare('SELECT category_id FROM categories WHERE store_id = ? AND LOWER(category_name) = LOWER(?) LIMIT 1');
$insertCategory = $pdo->prepare('INSERT INTO categories (store_id, category_name) VALUES (?, ?)');
$insertProduct = $pdo->prepare(
    "INSERT INTO products
     (store_id, sku, barcode, rfid_tag, product_name, category_id, location_tag, system_qty, reorder_level, unit_cost, unit_price, image_url)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
$insertMovement = $pdo->prepare(
    "INSERT INTO stock_movements (store_id, product_id, movement_type, quantity, reason, reference_no, performed_by)
     VALUES (?, ?, 'in', ?, 'Opening stock', 'IMPORT', ?)"
);

try {
    $pdo->beginTransaction();

    foreach ($rows as $i => $row) {
        $line = $i + 2;
        $name = import_row_value($row, ['product_name', 'name', 'product']);
        $sku = import_row_value($row, ['sku']);
        $barcode = import_row_value($row, ['barcode', 'upc', 'ean']);
        $categoryName = import_row_value($row, ['category', 'category_name']);
        $location = import_row_value($row, ['location', 'location_tag']);
        $rfid = import_row_value($row, ['rfid', 'rfid_tag', 'epc', 'tag']);
        $qty = max(0, (int) import_row_value($row, ['system_qty', 'qty', 'quantity'], '0'));
        $reorder = max(0, (int) import_row_value($row, ['reorder_level', 'reorder'], '5'));
        $cost = (float) import_row_value($row, ['unit_cost', 'cost'], '0');
        $price = (float) import_row_value($row, ['unit_price', 'price'], '0');

        if ($name === '') {
            $skipped[] = ['row' => $line, 'reason' => 'product_name is required', 'raw' => $sku ?: $name];
            continue;
        }
        $ids = resolve_product_identifiers($sku, $barcode !== '' ? [$barcode] : []);
        if (!$ids['ok']) {
            $skipped[] = ['row' => $line, 'reason' => $ids['error'], 'raw' => $sku ?: $barcode ?: $name];
            continue;
        }

        $categoryId = null;
        if ($categoryName !== '') {
            $key = strtolower($categoryName);
            if (isset($categoryCache[$key])) {
                $categoryId = $categoryCache[$key];
            } else {
                $findCategory->execute([$storeId, $categoryName]);
                $cat = $findCategory->fetch();
                if ($cat) {
                    $categoryId = (int) $cat['category_id'];
                } elseif (is_admin()) {
                    try {
                        $insertCategory->execute([$storeId, $categoryName]);
                        $categoryId = (int) $pdo->lastInsertId();
                    } catch (PDOException $e) {
                        $findCategory->execute([$storeId, $categoryName]);
                        $cat = $findCategory->fetch();
                        $categoryId = $cat ? (int) $cat['category_id'] : null;
                    }
                }
                $categoryCache[$key] = $categoryId;
            }
        }

        try {
            $insertProduct->execute([
                $storeId,
                $ids['sku'],
                $ids['primary'],
                $rfid !== '' ? $rfid : null,
                $name,
                $categoryId,
                $location !== '' ? $location : null,
                $qty,
                $reorder,
                $cost,
                $price,
                $defaultImage,
            ]);
            $productId = (int) $pdo->lastInsertId();
            sync_product_barcodes($pdo, $storeId, $productId, $ids['barcodes']);
            if ($qty > 0) {
                $insertMovement->execute([$storeId, $productId, $qty, $userId]);
            }
            $created++;
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $skipped[] = ['row' => $line, 'reason' => 'SKU, barcode, or RFID already exists', 'raw' => $ids['sku'] ?: $ids['primary']];
            } else {
                $skipped[] = ['row' => $line, 'reason' => 'Could not create product', 'raw' => $ids['sku'] ?: $ids['primary']];
            }
        }
    }

    $pdo->commit();
    json_response([
        'ok' => true,
        'created' => $created,
        'skipped' => $skipped,
        'message' => $created . ' product(s) imported' . (count($skipped) ? ', ' . count($skipped) . ' skipped' : '') . '.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'Product import failed.'], 500);
}
