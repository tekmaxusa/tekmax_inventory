<?php
/**
 * Full HTTP end-to-end smoke test against local XAMPP.
 * Run: C:\xampp\php\php.exe _e2e_test.php
 */
declare(strict_types=1);

$base = 'http://localhost/inventory-system';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims_e2e_cookie.txt';
@unlink($cookie);
$fail = 0;
$csrf = '';

function req(string $method, string $url, ?array $json = null, array $form = [], ?array $multipart = null): array
{
    global $cookie, $csrf;
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($csrf !== '' && !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
    ]);
    if ($multipart !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart);
    } elseif ($json !== null) {
        $body = json_encode($json);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($form) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['code' => 0, 'body' => '', 'error' => curl_error($ch)];
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($raw, $headerSize);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
}

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

function e2e_png(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ims_e2e.png';
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
    file_put_contents($path, $png);
    return $path;
}

// Unauthenticated API should return JSON 401
$r = req('GET', "$base/api/products.php");
check('API 401 JSON when logged out', $r['code'] === 401 && ($r['json']['ok'] ?? true) === false, "code={$r['code']}");

// Login page + CSRF
$r = req('GET', "$base/login.php");
check('Login page loads', $r['code'] === 200 && str_contains($r['body'], 'csrf_token'));
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
}
check('CSRF token present', $csrf !== '', substr($csrf, 0, 8));

$r = req('POST', "$base/login.php", null, [
    'email' => 'admin@example.com',
    'password' => 'admin123',
    'csrf_token' => $csrf,
]);
check('Login redirects', in_array($r['code'], [302, 303], true), "code={$r['code']}");

// Refresh CSRF from an authenticated page (session regenerated on login)
$r = req('GET', "$base/dashboard.php");
check('Dashboard after login', $r['code'] === 200 && str_contains($r['body'], 'csrf-token'));
if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
} elseif (preg_match('/window\.CSRF_TOKEN\s*=\s*"([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
}

// Close leftover open audit so stock tests can run
$r = req('GET', "$base/api/audit_sessions.php");
$active = $r['json']['active'] ?? null;
if (is_array($active) && !empty($active['session_id'])) {
    $close = req('POST', "$base/api/audit_sessions.php?action=close", [
        'action' => 'close',
        'session_id' => (int) $active['session_id'],
    ]);
    check('Close leftover audit', ($close['json']['ok'] ?? false) === true, (string) ($close['json']['error'] ?? $close['code']));
}

$r = req('GET', "$base/api/products.php");
check('Products list', ($r['json']['ok'] ?? false) === true && count($r['json']['products'] ?? []) >= 1, 'n=' . count($r['json']['products'] ?? []));
$productId = (int) ($r['json']['products'][0]['product_id'] ?? 0);

$r = req('GET', "$base/api/categories.php");
check('Categories list', ($r['json']['ok'] ?? false) === true && count($r['json']['categories'] ?? []) >= 1);

$suffix = (string) time();
$r = req('POST', "$base/api/categories.php", ['category_name' => "E2E Cat $suffix"]);
check('Create category', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? $r['code']));
$catId = (int) ($r['json']['category']['category_id'] ?? 0);

$png = e2e_png();
$r = req('POST', "$base/api/products.php", null, [], [
    'sku' => "E2E-$suffix",
    'barcode' => "BC$suffix",
    'rfid_tag' => "RFID$suffix",
    'product_name' => "E2E Product $suffix",
    'category_id' => (string) $catId,
    'location_tag' => 'Z-99',
    'system_qty' => '5',
    'reorder_level' => '2',
    'unit_cost' => '1.5',
    'unit_price' => '3.0',
    'image' => new CURLFile($png, 'image/png', 'e2e.png'),
]);
check('Create product', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? $r['code']));
$newPid = (int) ($r['json']['product']['product_id'] ?? 0);

$r = req('GET', "$base/api/products.php?code=RFID$suffix");
check('Lookup by RFID tag', ($r['json']['found'] ?? false) === true, json_encode($r['json'] ?? []));

$r = req('POST', "$base/api/stock_movements.php", [
    'product_id' => $newPid,
    'movement_type' => 'in',
    'quantity' => 3,
    'reason' => 'Purchase',
    'reference_no' => "PO-$suffix",
]);
check('Stock IN', ($r['json']['ok'] ?? false) === true && (int) ($r['json']['system_qty'] ?? 0) === 8, json_encode($r['json']));

$r = req('POST', "$base/api/stock_movements.php", [
    'product_id' => $newPid,
    'movement_type' => 'out',
    'quantity' => 2,
    'reason' => 'Sale',
]);
check('Stock OUT', ($r['json']['ok'] ?? false) === true && (int) ($r['json']['system_qty'] ?? 0) === 6, json_encode($r['json']));

$r = req('GET', "$base/api/stock_movements.php?product_id=$newPid");
check('Movements list', ($r['json']['ok'] ?? false) === true && count($r['json']['movements'] ?? []) >= 2);

$r = req('POST', "$base/api/audit_sessions.php?action=start", [
    'action' => 'start',
    'store_name' => 'E2E Store',
    'audit_date' => date('Y-m-d'),
]);
check('Audit start', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? $r['code']));
$sessionId = (int) ($r['json']['session']['session_id'] ?? 0);

$r = req('POST', "$base/api/stock_movements.php", [
    'product_id' => $newPid,
    'movement_type' => 'in',
    'quantity' => 1,
]);
check('Stock blocked during audit', $r['code'] === 423 || (($r['json']['ok'] ?? true) === false), "code={$r['code']}");

$r = req('POST', "$base/api/audit_counts.php", [
    'session_id' => $sessionId,
    'product_id' => $newPid,
    'physical_qty' => 7,
    'count_method' => 'manual',
]);
check('Audit count update', ($r['json']['ok'] ?? false) === true && (int) ($r['json']['count']['diff_qty'] ?? 0) === 1, json_encode($r['json']['count'] ?? []));

$r = req('POST', "$base/api/audit_sessions.php?action=save", [
    'action' => 'save',
    'session_id' => $sessionId,
]);
check('Audit save', ($r['json']['ok'] ?? false) === true);

$r = req('POST', "$base/api/stock_movements.php", [
    'product_id' => $newPid,
    'movement_type' => 'in',
    'quantity' => 1,
    'reason' => 'After save unlock',
]);
check('Stock allowed after audit save', ($r['json']['ok'] ?? false) === true, "code={$r['code']} " . json_encode($r['json']));

$r = req('POST', "$base/api/audit_sessions.php?action=close", [
    'action' => 'close',
    'session_id' => $sessionId,
]);
check('Audit close + reconcile', ($r['json']['ok'] ?? false) === true, json_encode($r['json']));

$r = req('GET', "$base/api/products.php?id=$newPid");
check('Qty reconciled to physical', (int) ($r['json']['product']['system_qty'] ?? 0) === 7, 'qty=' . ($r['json']['product']['system_qty'] ?? '?'));

$r = req('POST', "$base/api/users.php", [
    'name' => "E2E User $suffix",
    'email' => "e2e$suffix@example.com",
    'password' => 'staffpass1',
    'role' => 'staff',
]);
check('Admin create staff user', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? $r['code']));
$staffId = (int) ($r['json']['user']['user_id'] ?? 0);

$r = req('POST', "$base/api/products.php", ['_method' => 'DELETE', 'product_id' => $newPid]);
check('Soft-delete product', ($r['json']['ok'] ?? false) === true);

$r = req('POST', "$base/api/categories.php", ['_method' => 'DELETE', 'category_id' => $catId]);
check('Delete empty category', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? ''));

if ($staffId > 0) {
    $r = req('POST', "$base/api/users.php", ['_method' => 'DELETE', 'user_id' => $staffId]);
    check('Disable e2e staff user', ($r['json']['ok'] ?? false) === true, (string) ($r['json']['error'] ?? ''));
}

foreach (['dashboard.php', 'products.php', 'categories.php', 'stock_in_out.php', 'audit.php', 'reports.php', 'users.php'] as $page) {
    $r = req('GET', "$base/$page");
    check("Page $page", $r['code'] === 200 && $r['body'] !== '', "code={$r['code']} len=" . strlen($r['body']));
}

// Staff role: cannot manage users or categories
$r = req('POST', "$base/api/auth.php", ['action' => 'logout']);
check('Admin logout', in_array($r['code'], [200, 302, 303], true), "code={$r['code']}");

$r = req('GET', "$base/login.php");
if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
}
$r = req('POST', "$base/login.php", null, [
    'email' => 'staff@example.com',
    'password' => 'staff123',
    'csrf_token' => $csrf,
]);
check('Staff login redirects', in_array($r['code'], [302, 303], true), "code={$r['code']}");
$r = req('GET', "$base/dashboard.php");
if (preg_match('/name="csrf-token"\s+content="([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
} elseif (preg_match('/window\.CSRF_TOKEN\s*=\s*"([^"]+)"/', $r['body'], $m)) {
    $csrf = $m[1];
}

$r = req('GET', "$base/users.php");
check('Staff blocked from users page', $r['code'] === 403, "code={$r['code']}");
$r = req('POST', "$base/api/categories.php", ['category_name' => "Staff should fail $suffix"]);
check('Staff cannot create category', $r['code'] === 403, "code={$r['code']} " . (string) ($r['json']['error'] ?? ''));
$r = req('POST', "$base/api/audit_sessions.php?action=close", ['action' => 'close', 'session_id' => 1]);
check('Staff cannot close audit', $r['code'] === 403, "code={$r['code']}");

echo PHP_EOL . ($fail === 0 ? 'ALL E2E CHECKS PASSED' : "$fail FAILED") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
