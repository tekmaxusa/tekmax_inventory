<?php
/**
 * Users API — admin CRUD + self password change.
 *
 * GET                  list users (admin)
 * POST                 create user (admin)
 * POST _method=PUT     update user (admin)
 * POST _method=DELETE  deactivate user (admin)
 * POST action=password change password (self, or admin resetting another)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo = db();
$storeId = store_id_param();
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = array_merge($_POST, read_json_body());
if (isset($body['_method'])) {
    $method = strtoupper((string) $body['_method']);
}
$action = strtolower(trim((string) ($body['action'] ?? $_GET['action'] ?? '')));

function public_user_row(array $row): array
{
    return [
        'user_id' => (int) $row['user_id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $row['role'] === 'admin' ? 'admin' : 'staff',
        'is_active' => (int) ($row['is_active'] ?? 1) === 1,
        'created_at' => $row['created_at'] ?? null,
    ];
}

function count_active_admins(PDO $pdo, int $storeId, ?int $exceptId = null): int
{
    $sql = "SELECT COUNT(*) FROM store_users su
            JOIN users u ON u.user_id = su.user_id
            WHERE su.store_id = ? AND su.role = 'admin' AND u.is_active = 1";
    $params = [$storeId];
    if ($exceptId !== null) {
        $sql .= ' AND su.user_id <> ?';
        $params[] = $exceptId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

if ($action === 'password' && $method === 'POST') {
    $targetId = (int) ($body['user_id'] ?? $_SESSION['user_id']);
    $new = (string) ($body['new_password'] ?? '');
    $current = (string) ($body['current_password'] ?? '');

    if ($targetId <= 0) {
        json_response(['ok' => false, 'error' => 'user_id is required.'], 422);
    }
    if (strlen($new) < 8) {
        json_response(['ok' => false, 'error' => 'New password must be at least 8 characters.'], 422);
    }

    $self = (int) $_SESSION['user_id'] === $targetId;
    if (!$self && !is_admin()) {
        forbid('Admin access required.');
    }

    // Admins may only reset passwords for users in their own store (prevents cross-tenant IDOR).
    if (!$self) {
        $member = $pdo->prepare(
            'SELECT 1 FROM store_users WHERE store_id = ? AND user_id = ? LIMIT 1'
        );
        $member->execute([$storeId, $targetId]);
        if (!$member->fetchColumn()) {
            json_response(['ok' => false, 'error' => 'User not found in this store.'], 404);
        }
    }

    $stmt = $pdo->prepare('SELECT user_id, password_hash FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$targetId]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'User not found.'], 404);
    }

    if ($self) {
        $existingHash = (string) ($row['password_hash'] ?? '');
        if ($existingHash !== '' && ($current === '' || !password_verify($current, $existingHash))) {
            json_response(['ok' => false, 'error' => 'Current password is incorrect.'], 401);
        }
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $targetId]);
    json_response(['ok' => true, 'message' => 'Password updated.']);
}

if (!is_admin()) {
    forbid('Admin access required.');
}

if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.name, u.email, su.role, u.is_active, u.created_at
         FROM store_users su
         JOIN users u ON u.user_id = su.user_id
         WHERE su.store_id = ?
         ORDER BY su.role ASC, u.name ASC'
    );
    $stmt->execute([$storeId]);
    $rows = $stmt->fetchAll();
    json_response(['ok' => true, 'users' => array_map('public_user_row', $rows)]);
}

if ($method === 'POST') {
    $name = trim((string) ($body['name'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $role = strtolower(trim((string) ($body['role'] ?? 'staff')));
    if ($role !== 'admin') {
        $role = 'staff';
    }

    if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Name and a valid email are required.'], 422);
    }
    if (strlen($password) < 8) {
        json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$storeId, $id, $role]);
        $row = $pdo->prepare(
            'SELECT u.user_id, u.name, u.email, su.role, u.is_active, u.created_at
             FROM store_users su
             JOIN users u ON u.user_id = su.user_id
             WHERE su.store_id = ? AND u.user_id = ?'
        );
        $row->execute([$storeId, $id]);
        json_response(['ok' => true, 'user' => public_user_row($row->fetch())], 201);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_response(['ok' => false, 'error' => 'Email already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to create user.'], 500);
    }
}

if ($method === 'PUT' || $method === 'PATCH') {
    $id = (int) ($body['user_id'] ?? $_GET['id'] ?? 0);
    $name = trim((string) ($body['name'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $role = strtolower(trim((string) ($body['role'] ?? 'staff')));
    $active = array_key_exists('is_active', $body)
        ? (int) ((int) $body['is_active'] === 1 || $body['is_active'] === true || $body['is_active'] === '1' || $body['is_active'] === 'true')
        : 1;
    if ($role !== 'admin') {
        $role = 'staff';
    }

    if ($id <= 0 || $name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'user_id, name, and a valid email are required.'], 422);
    }

    $existing = $pdo->prepare(
        'SELECT u.*, su.role AS store_role
         FROM store_users su
         JOIN users u ON u.user_id = su.user_id
         WHERE su.store_id = ? AND u.user_id = ?'
    );
    $existing->execute([$storeId, $id]);
    $current = $existing->fetch();
    if (!$current) {
        json_response(['ok' => false, 'error' => 'User not found in this store.'], 404);
    }

    if ($id === (int) $_SESSION['user_id'] && $active !== 1) {
        json_response(['ok' => false, 'error' => 'You cannot disable your own account.'], 409);
    }

    $wasAdmin = ($current['store_role'] === 'admin' && (int) $current['is_active'] === 1);
    $willBeAdmin = ($role === 'admin' && $active === 1);
    if ($wasAdmin && !$willBeAdmin && count_active_admins($pdo, $storeId, $id) < 1) {
        json_response(['ok' => false, 'error' => 'Cannot remove or disable the last admin.'], 409);
    }

    try {
        $pdo->prepare(
            'UPDATE users SET name = ?, email = ?, is_active = ? WHERE user_id = ?'
        )->execute([$name, $email, $active, $id]);
        $pdo->prepare('UPDATE store_users SET role = ? WHERE store_id = ? AND user_id = ?')
            ->execute([$role, $storeId, $id]);
        $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ?')->execute([$role, $id]);

        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                json_response(['ok' => false, 'error' => 'Password must be at least 8 characters.'], 422);
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }

        $row = $pdo->prepare(
            'SELECT u.user_id, u.name, u.email, su.role, u.is_active, u.created_at
             FROM store_users su
             JOIN users u ON u.user_id = su.user_id
             WHERE su.store_id = ? AND u.user_id = ?'
        );
        $row->execute([$storeId, $id]);
        json_response(['ok' => true, 'user' => public_user_row($row->fetch())]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_response(['ok' => false, 'error' => 'Email already exists.'], 409);
        }
        json_response(['ok' => false, 'error' => 'Failed to update user.'], 500);
    }
}

if ($method === 'DELETE') {
    $id = (int) ($body['user_id'] ?? $_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'user_id is required.'], 422);
    }
    if ($id === (int) $_SESSION['user_id']) {
        json_response(['ok' => false, 'error' => 'You cannot disable your own account.'], 409);
    }

    $existing = $pdo->prepare(
        'SELECT u.*, su.role AS store_role
         FROM store_users su
         JOIN users u ON u.user_id = su.user_id
         WHERE su.store_id = ? AND u.user_id = ?'
    );
    $existing->execute([$storeId, $id]);
    $current = $existing->fetch();
    if (!$current) {
        json_response(['ok' => false, 'error' => 'User not found in this store.'], 404);
    }
    if ($current['store_role'] === 'admin' && (int) $current['is_active'] === 1 && count_active_admins($pdo, $storeId, $id) < 1) {
        json_response(['ok' => false, 'error' => 'Cannot disable the last admin.'], 409);
    }

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE user_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM store_users WHERE store_id = ? AND user_id = ?')->execute([$storeId, $id]);
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
