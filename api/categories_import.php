<?php
/**
 * Bulk import categories from CSV / XLSX.
 *
 * GET ?template=1  → download CSV template
 * POST multipart: file and/or csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/spreadsheet_import.php';
require_login();

if (!is_admin()) {
    forbid('Only admins can import categories.');
}

$pdo = db();
$storeId = store_id_param();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['template'])) {
    import_send_csv_template('categories_import_template.csv', ['category_name'], [
        ['Wigs'],
        ['Hair Oil'],
        ['Cosmetics'],
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
    // Allow one-column paste without headers
    $paste = trim((string) ($_POST['csv'] ?? ''));
    if ($paste !== '') {
        foreach (preg_split('/\r\n|\r|\n/', $paste) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && stripos($line, 'category') !== 0) {
                $rows[] = ['category_name' => $line];
            }
        }
    }
}

if ($rows === []) {
    json_response(['ok' => false, 'error' => 'No category rows found.'], 422);
}

$created = 0;
$skipped = [];
$insert = $pdo->prepare('INSERT INTO categories (store_id, category_name) VALUES (?, ?)');
$exists = $pdo->prepare('SELECT category_id FROM categories WHERE store_id = ? AND LOWER(category_name) = LOWER(?) LIMIT 1');

foreach ($rows as $i => $row) {
    $line = $i + 2;
    $name = import_row_value($row, ['category_name', 'category', 'name', 'value']);
    if ($name === '') {
        $skipped[] = ['row' => $line, 'reason' => 'Category name is required'];
        continue;
    }

    $exists->execute([$storeId, $name]);
    if ($exists->fetch()) {
        $skipped[] = ['row' => $line, 'reason' => 'Category already exists', 'raw' => $name];
        continue;
    }

    try {
        $insert->execute([$storeId, $name]);
        $created++;
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            $skipped[] = ['row' => $line, 'reason' => 'Category already exists', 'raw' => $name];
        } else {
            $skipped[] = ['row' => $line, 'reason' => 'Could not create category', 'raw' => $name];
        }
    }
}

json_response([
    'ok' => true,
    'created' => $created,
    'skipped' => $skipped,
    'message' => $created . ' category(ies) imported' . (count($skipped) ? ', ' . count($skipped) . ' skipped' : '') . '.',
]);
