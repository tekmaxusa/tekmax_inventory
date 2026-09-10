<?php
declare(strict_types=1);
/**
 * CLI smoke test — run: C:\xampp\php\php.exe _smoke_test.php
 */
require_once __DIR__ . '/config/db.php';

$pdo = db();
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $fail;
    if ($ok) {
        echo "[OK] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    } else {
        $fail++;
        echo "[FAIL] $label" . ($detail !== '' ? " — $detail" : '') . PHP_EOL;
    }
}

$tables = ['users', 'stores', 'store_users', 'categories', 'products', 'stock_movements', 'audit_sessions', 'audit_counts'];
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    check("table $t", in_array($t, $existing, true));
}

$cols = $pdo->query('SHOW COLUMNS FROM stock_movements')->fetchAll(PDO::FETCH_COLUMN);
check('stock_movements.movement_type', in_array('movement_type', $cols, true), implode(',', $cols));

$user = $pdo->query("SELECT * FROM users WHERE email='admin@example.com'")->fetch();
check('admin user exists', (bool) $user);
if ($user) {
    check('admin password admin123', password_verify('admin123', $user['password_hash']));
}

$prodCount = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn();
$catCount = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
check('seed products', $prodCount >= 1, "count=$prodCount");
check('seed categories', $catCount >= 1, "count=$catCount");

// Column checks used by APIs
$pcols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
foreach (['sku', 'barcode', 'rfid_tag', 'product_name', 'system_qty', 'is_active', 'unit_cost', 'unit_price', 'store_id'] as $c) {
    check("products.$c", in_array($c, $pcols, true));
}

$ucols = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
check('users.is_active', in_array('is_active', $ucols, true));
$staff = $pdo->query("SELECT * FROM users WHERE email='staff@example.com'")->fetch();
check('staff user exists', (bool) $staff);
if ($staff) {
    check('staff password staff123', password_verify('staff123', $staff['password_hash']));
    check('staff role', ($staff['role'] ?? '') === 'staff');
}

$storeCount = (int) $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
check('stores seeded', $storeCount >= 1, "count=$storeCount");

$linkCount = (int) $pdo->query('SELECT COUNT(*) FROM store_users')->fetchColumn();
check('store_users links', $linkCount >= 1, "count=$linkCount");

$acols = $pdo->query('SHOW COLUMNS FROM audit_sessions')->fetchAll(PDO::FETCH_COLUMN);
foreach (['store_name', 'audit_date', 'status', 'created_by', 'store_id'] as $c) {
    check("audit_sessions.$c", in_array($c, $acols, true));
}

echo PHP_EOL . ($fail === 0 ? 'ALL CHECKS PASSED' : "$fail FAILED") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
