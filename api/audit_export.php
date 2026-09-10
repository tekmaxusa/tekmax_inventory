<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$sessionId = (int) ($_GET['session_id'] ?? 0);
$format = strtolower((string) ($_GET['format'] ?? 'csv'));

if ($sessionId <= 0) {
    http_response_code(422);
    header('Content-Type: text/plain');
    echo 'session_id is required';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM audit_sessions WHERE session_id = ? AND store_id = ?');
$stmt->execute([$sessionId, $storeId]);
$session = $stmt->fetch();
if (!$session) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Session not found';
    exit;
}

$rows = $pdo->prepare(
    "SELECT p.sku, p.barcode, p.product_name, p.location_tag, p.system_qty, p.unit_cost,
            ac.physical_qty, ac.count_method
     FROM audit_counts ac
     JOIN products p ON p.product_id = ac.product_id
     WHERE ac.session_id = ? AND p.store_id = ?
     ORDER BY p.product_name ASC"
);
$rows->execute([$sessionId, $storeId]);
$data = $rows->fetchAll();

$filename = 'audit_variance_' . $sessionId . '_' . date('Ymd') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');
fputcsv($out, [
    'SKU', 'Barcode', 'Product', 'Location', 'System Qty', 'Physical Qty',
    'Diff Qty', 'Diff Value', 'Status', 'Count Method', 'Store', 'Audit Date',
]);

foreach ($data as $row) {
    $physical = $row['physical_qty'] === null ? null : (int) $row['physical_qty'];
    $system = (int) $row['system_qty'];
    if ($physical === null) {
        $diff = '';
        $diffVal = '';
        $status = 'not_counted';
    } else {
        $diff = $physical - $system;
        $diffVal = round($diff * (float) $row['unit_cost'], 2);
        if ($diff === 0) {
            $status = 'exact';
        } elseif ($diff < 0) {
            $status = 'shortage';
        } else {
            $status = 'overage';
        }
    }

    fputcsv($out, [
        $row['sku'],
        $row['barcode'],
        $row['product_name'],
        $row['location_tag'],
        $system,
        $physical === null ? '' : $physical,
        $diff,
        $diffVal,
        $status,
        $row['count_method'] ?? '',
        $session['store_name'],
        $session['audit_date'],
    ]);
}

fclose($out);
exit;
