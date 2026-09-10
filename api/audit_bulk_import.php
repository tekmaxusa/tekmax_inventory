<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/catalog.php';
require_once __DIR__ . '/../includes/spreadsheet_import.php';
require_login();

$pdo = db();
$storeId = store_id_param();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['template'])) {
    import_send_csv_template('audit_count_import_template.csv', ['code', 'quantity'], [
        ['803868451092', '10'],
        ['850001265652', '15'],
        ['C9-1B', ''],
        ['MMO-4OZ', ''],
    ]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

$body = read_json_body();
$sessionId = (int) ($_POST['session_id'] ?? $body['session_id'] ?? 0);
$mode = strtolower(trim((string) ($_POST['mode'] ?? $body['mode'] ?? 'set')));
if (!in_array($mode, ['set', 'add'], true)) {
    $mode = 'set';
}

if ($sessionId <= 0) {
    json_response(['ok' => false, 'error' => 'session_id is required.'], 422);
}

$sess = $pdo->prepare("SELECT session_id FROM audit_sessions WHERE session_id = ? AND store_id = ? AND status IN ('in_progress','saved')");
$sess->execute([$sessionId, $storeId]);
if (!$sess->fetch()) {
    json_response(['ok' => false, 'error' => 'Audit session is not editable.'], 409);
}

$csvText = trim((string) ($_POST['csv'] ?? $body['csv'] ?? ''));
if ($csvText === '' && !empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $name = strtolower((string) ($_FILES['file']['name'] ?? ''));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    try {
        if ($ext === 'xlsx') {
            $matrix = import_matrix_from_xlsx((string) $_FILES['file']['tmp_name']);
            $lineParts = [];
            foreach ($matrix as $row) {
                if ($row === []) {
                    continue;
                }
                if (count($row) >= 2 && trim((string) $row[1]) !== '') {
                    $lineParts[] = trim((string) $row[0]) . ',' . trim((string) $row[1]);
                } else {
                    $lineParts[] = trim((string) $row[0]);
                }
            }
            $csvText = implode("\n", $lineParts);
        } else {
            $csvText = (string) file_get_contents((string) $_FILES['file']['tmp_name']);
        }
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 422);
    }
}

$csvText = trim($csvText);
if ($csvText === '') {
    json_response(['ok' => false, 'error' => 'Provide a CSV file or paste tag/SKU lines.'], 422);
}

$lines = preg_split('/\r\n|\r|\n/', $csvText) ?: [];

/**
 * Parse lines into code => quantity map.
 * Supports:
 *  - sku,qty / barcode,qty
 *  - one code per line (occurrences counted)
 */
$totals = [];
$skipped = [];

foreach ($lines as $i => $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    // Skip common CSV headers
    if (preg_match('/^(sku|barcode|code|epc|tag)([,\t; ]|$)/i', $line)) {
        continue;
    }

    // Prefer comma/tab/semicolon separated code,qty
    if (preg_match('/^([^,\t;]+)[,\t;]\s*(-?\d+)\s*$/', $line, $m)) {
        $code = trim($m[1]);
        $qty = (int) $m[2];
        if ($code === '' || $qty < 0) {
            $skipped[] = ['line' => $i + 1, 'reason' => 'Bad code/qty', 'raw' => $line];
            continue;
        }
        $key = strtolower($code);
        $totals[$key] = [
            'code' => $code,
            'qty' => ($totals[$key]['qty'] ?? 0) + $qty,
        ];
        continue;
    }

    // Single code line (RFID dump) — each line counts as +1
    $code = preg_replace('/\s+/', '', $line) ?? '';
    if ($code === '' || strlen($code) < 2) {
        $skipped[] = ['line' => $i + 1, 'reason' => 'Invalid format', 'raw' => $line];
        continue;
    }
    $key = strtolower($code);
    $totals[$key] = [
        'code' => $code,
        'qty' => ($totals[$key]['qty'] ?? 0) + 1,
    ];
}

if (!$totals) {
    json_response(['ok' => false, 'error' => 'No valid tag/SKU lines found.', 'skipped' => $skipped], 422);
}

$updated = 0;
$details = [];

try {
    $pdo->beginTransaction();

    $find = $pdo->prepare(
        "SELECT ac.count_id, ac.product_id, ac.physical_qty, p.sku, p.barcode, p.rfid_tag, p.product_name, p.image_url
         FROM audit_counts ac
         JOIN products p ON p.product_id = ac.product_id
         WHERE ac.session_id = ?
           AND p.store_id = ?
           AND " . product_code_sql('p')
    );
    $upd = $pdo->prepare(
        "UPDATE audit_counts
         SET physical_qty = ?, count_method = 'rfid', counted_at = NOW()
         WHERE session_id = ? AND product_id = ?"
    );

    foreach ($totals as $item) {
        $code = $item['code'];
        $qty = (int) $item['qty'];

        $find->execute(array_merge([$sessionId, $storeId], product_code_params($code)));
        $row = $find->fetch();
        if (!$row) {
            $skipped[] = ['line' => null, 'reason' => 'SKU/barcode/RFID tag not found', 'raw' => $code];
            continue;
        }

        $current = $row['physical_qty'] === null ? null : (int) $row['physical_qty'];
        $newQty = $mode === 'add'
            ? (($current ?? 0) + $qty)
            : $qty;

        $upd->execute([$newQty, $sessionId, (int) $row['product_id']]);
        $updated++;
        $details[] = with_product_image_url([
            'product_id' => (int) $row['product_id'],
            'sku' => $row['sku'],
            'barcode' => $row['barcode'],
            'product_name' => $row['product_name'],
            'image_url' => $row['image_url'],
            'physical_qty' => $newQty,
            'imported_qty' => $qty,
        ]);
    }

    $pdo->commit();
    json_response([
        'ok' => true,
        'updated' => $updated,
        'skipped' => $skipped,
        'details' => $details,
        'mode' => $mode,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['ok' => false, 'error' => 'Bulk import failed.'], 500);
}
