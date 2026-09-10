<?php
/**
 * Platform super-admin API — monitor and manage all stores & users.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_super_admin();

$pdo = db();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());
$action = strtolower(trim((string) ($body['action'] ?? $_GET['action'] ?? '')));
$view = strtolower(trim((string) ($_GET['view'] ?? '')));

if ($method === 'GET' && ($view === 'overview' || $action === 'overview')) {
    $stats = [
        'stores_total' => (int) $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn(),
        'stores_active' => (int) $pdo->query('SELECT COUNT(*) FROM stores WHERE is_active = 1')->fetchColumn(),
        'users_total' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'super_admin'")->fetchColumn(),
        'users_active' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'super_admin' AND is_active = 1")->fetchColumn(),
        'products_total' => (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn(),
    ];
    json_response(['ok' => true, 'overview' => $stats]);
}

if ($method === 'GET' && ($view === 'stores' || $action === 'stores')) {
    $rows = $pdo->query(
        "SELECT s.store_id, s.store_name, s.business_type, s.contact_email, s.phone, s.slug,
                s.is_active, s.created_at,
                (SELECT COUNT(*) FROM store_users su WHERE su.store_id = s.store_id) AS user_count,
                (SELECT COUNT(*) FROM products p WHERE p.store_id = s.store_id AND p.is_active = 1) AS product_count,
                (SELECT u.name FROM store_users su
                 JOIN users u ON u.user_id = su.user_id
                 WHERE su.store_id = s.store_id AND su.role = 'admin'
                 ORDER BY su.user_id ASC LIMIT 1) AS owner_name,
                (SELECT u.email FROM store_users su
                 JOIN users u ON u.user_id = su.user_id
                 WHERE su.store_id = s.store_id AND su.role = 'admin'
                 ORDER BY su.user_id ASC LIMIT 1) AS owner_email
         FROM stores s
         ORDER BY s.created_at DESC, s.store_id DESC"
    )->fetchAll();
    json_response(['ok' => true, 'stores' => $rows]);
}

if ($method === 'GET' && ($view === 'users' || $action === 'users')) {
    $rows = $pdo->query(
        "SELECT u.user_id, u.name, u.email, u.role, u.is_active, u.created_at,
                GROUP_CONCAT(DISTINCT s.store_name ORDER BY s.store_name SEPARATOR ', ') AS stores
         FROM users u
         LEFT JOIN store_users su ON su.user_id = u.user_id
         LEFT JOIN stores s ON s.store_id = su.store_id
         WHERE u.role <> 'super_admin'
         GROUP BY u.user_id
         ORDER BY u.created_at DESC, u.user_id DESC"
    )->fetchAll();
    json_response(['ok' => true, 'users' => $rows]);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$storeId = (int) ($body['store_id'] ?? 0);
$userId = (int) ($body['user_id'] ?? 0);

if ($action === 'store_disable') {
    if ($storeId <= 0) {
        json_response(['ok' => false, 'error' => 'store_id is required.'], 422);
    }
    $pdo->prepare('UPDATE stores SET is_active = 0 WHERE store_id = ?')->execute([$storeId]);
    json_response(['ok' => true, 'message' => 'Store suspended. Users cannot access inventory.']);
}

if ($action === 'store_enable') {
    if ($storeId <= 0) {
        json_response(['ok' => false, 'error' => 'store_id is required.'], 422);
    }
    $pdo->prepare('UPDATE stores SET is_active = 1 WHERE store_id = ?')->execute([$storeId]);
    json_response(['ok' => true, 'message' => 'Store reactivated.']);
}

if ($action === 'store_delete') {
    if ($storeId <= 0) {
        json_response(['ok' => false, 'error' => 'store_id is required.'], 422);
    }
    $pdo->prepare('DELETE FROM stores WHERE store_id = ?')->execute([$storeId]);
    json_response(['ok' => true, 'message' => 'Store and all its inventory data were permanently deleted.']);
}

if ($action === 'user_disable') {
    if ($userId <= 0) {
        json_response(['ok' => false, 'error' => 'user_id is required.'], 422);
    }
    guard_platform_user($pdo, $userId);
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?')->execute([$userId]);
    json_response(['ok' => true, 'message' => 'User account disabled.']);
}

if ($action === 'user_enable') {
    if ($userId <= 0) {
        json_response(['ok' => false, 'error' => 'user_id is required.'], 422);
    }
    guard_platform_user($pdo, $userId);
    $pdo->prepare('UPDATE users SET is_active = 1 WHERE user_id = ?')->execute([$userId]);
    json_response(['ok' => true, 'message' => 'User account enabled.']);
}

if ($action === 'user_delete') {
    if ($userId <= 0) {
        json_response(['ok' => false, 'error' => 'user_id is required.'], 422);
    }
    guard_platform_user($pdo, $userId);
    $pdo->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
    json_response(['ok' => true, 'message' => 'User account permanently deleted.']);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);

function guard_platform_user(PDO $pdo, int $userId): void
{
    if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
        json_response(['ok' => false, 'error' => 'You cannot modify your own super-admin account here.'], 409);
    }
    $stmt = $pdo->prepare('SELECT role FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'User not found.'], 404);
    }
    if (strtolower((string) $row['role']) === 'super_admin') {
        json_response(['ok' => false, 'error' => 'Cannot modify a super-admin account.'], 409);
    }
}
