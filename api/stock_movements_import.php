<?php
/**
 * Bulk import stock movements from CSV / XLSX.
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
    import_send_csv_template('stock_movements_import_template.csv', [
        'sku', 'barcode', 'movement_type', 'quantity', 'reason', 'reference_no',
    ], [
        ['C9-1B', '803868451092', 'in', '5', 'Purchase', 'PO-1001'],
        ['MMO-4OZ', '850001265652', 'out', '2', 'Sale', 'INV-220'],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$active = locking_audit_session();
if ($active) {
    json_response([
        'ok' => false,
        'error' => 'Audit in progress — stock movements are paused. Save or close the audit to continue.',
    ], 423);
}

$loaded = import_load_rows_from_request();
if (!$loaded['ok']) {
    json_response($loaded, 422);
}

$rows = $loaded['rows'];
if ($rows === []) {
    json_response(['ok' => false, 'error' => 'No data rows found. Include a header row.'], 422);
}

$created = 0;
$skipped = [];
$userId = (int) $_SESSION['user_id'];

$findByCode = $pdo->prepare(
    'SELECT product_id, system_qty, product_name FROM products WHERE store_id = ? AND is_active = 1 AND ' . product_code_sql('products') . ' LIMIT 1'
);
$findById = $pdo->prepare('SELECT product_id, system_qty, product_name FROM products WHERE product_id = ? AND store_id = ? AND is_active = 1 LIMIT 1');
$insertMovement = $pdo->prepare(
    'INSERT INTO stock_movements (store_id, product_id, movement_type, quantity, reason, reference_no, performed_by)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$updateQty = $pdo->prepare('UPDATE products SET system_qty = ? WHERE product_id = ?');

try {
    $pdo->beginTransaction();

    foreach ($rows as $i => $row) {
        $line = $i + 2;
        $sku = import_row_value($row, ['sku']);
        $barcode = import_row_value($row, ['barcode', 'upc', 'ean']);
        $productIdRaw = import_row_value($row, ['product_id', 'id']);
        $type = strtolower(import_row_value($row, ['movement_type', 'type'], 'in'));
        $qty = (int) import_row_value($row, ['quantity', 'qty'], '0');
        $reason = import_row_value($row, ['reason'], 'Import');
        $ref = import_row_value($row, ['reference_no', 'reference', 'ref']);

        if (!in_array($type, ['in', 'out'], true) || $qty <= 0) {
            $skipped[] = ['row' => $line, 'reason' => 'movement_type must be in/out and quantity > 0'];
            continue;
        }

        $product = null;
        if ($productIdRaw !== '' && ctype_digit($productIdRaw)) {
            $findById->execute([(int) $productIdRaw, $storeId]);
            $product = $findById->fetch() ?: null;
        }
        if (!$product) {
            $code = $sku !== '' ? $sku : $barcode;
            if ($code === '') {
                $skipped[] = ['row' => $line, 'reason' => 'sku or barcode is required'];
                continue;
            }
            $findByCode->execute(array_merge([$storeId], product_code_params($code)));
            $product = $findByCode->fetch() ?: null;
        }

        if (!$product) {
            $skipped[] = ['row' => $line, 'reason' => 'Product not found', 'raw' => $sku ?: $barcode];
            continue;
        }

        $current = (int) $product['system_qty'];
        $newQty = $type === 'in' ? $current + $qty : $current - $qty;
        if ($newQty < 0) {
            $skipped[] = [
                'row' => $line,
                'reason' => "Insufficient stock for {$product['product_name']} (have {$current}, out {$qty})",
            ];
            continue;
        }

        $insertMovement->execute([
            $storeId,
            (int) $product['product_id'],
            $type,
            $qty,
            $reason !== '' ? $reason : null,
            $ref !== '' ? $ref : null,
            $userId,
        ]);
        $updateQty->execute([$newQty, (int) $product['product_id']]);
        $product['system_qty'] = $newQty;
        $created++;
    }

    $pdo->commit();
    json_response([
        'ok' => true,
        'created' => $created,
        'skipped' => $skipped,
        'message' => $created . ' movement(s) recorded' . (count($skipped) ? ', ' . count($skipped) . ' skipped' : '') . '.',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'Stock import failed.'], 500);
}
