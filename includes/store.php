<?php
/**
 * Multi-store (tenant) helpers — each account gets an isolated inventory workspace.
 */
declare(strict_types=1);

function schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function schema_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function store_slug_from_name(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'store';
    }
    return substr($slug, 0, 80);
}

function make_unique_store_slug(PDO $pdo, string $name): string
{
    $base = store_slug_from_name($name);
    $slug = $base;
    $n = 1;
    $stmt = $pdo->prepare('SELECT store_id FROM stores WHERE slug = ? LIMIT 1');
    while (true) {
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $n++;
        $slug = substr($base, 0, 72) . '-' . $n;
    }
}

function current_store_id(): int
{
    return (int) ($_SESSION['store_id'] ?? 0);
}

function current_store(): ?array
{
    $storeId = current_store_id();
    if ($storeId <= 0) {
        return null;
    }

    static $cache = [];
    if (isset($cache[$storeId])) {
        return $cache[$storeId];
    }

    $stmt = db()->prepare(
        'SELECT store_id, store_name, business_type, address, phone, contact_email, slug, is_active
         FROM stores WHERE store_id = ? AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$storeId]);
    $cache[$storeId] = $stmt->fetch() ?: null;
    return $cache[$storeId];
}

function set_session_store(int $storeId): void
{
    $_SESSION['store_id'] = $storeId;
    $stmt = db()->prepare(
        'SELECT store_id, store_name, business_type, address, phone, contact_email, slug, is_active
         FROM stores WHERE store_id = ? LIMIT 1'
    );
    $stmt->execute([$storeId]);
    $store = $stmt->fetch();
    $_SESSION['store_name'] = $store['store_name'] ?? '';
    $_SESSION['store_role'] = store_role_for_user($storeId, (int) ($_SESSION['user_id'] ?? 0));
}

function store_role_for_user(int $storeId, int $userId): string
{
    if ($storeId <= 0 || $userId <= 0 || !schema_table_exists(db(), 'store_users')) {
        return strtolower((string) ($_SESSION['user_role'] ?? 'staff')) === 'admin' ? 'admin' : 'staff';
    }
    $stmt = db()->prepare(
        'SELECT role FROM store_users WHERE store_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$storeId, $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'staff';
    }
    return strtolower((string) $row['role']) === 'admin' ? 'admin' : 'staff';
}

function user_store_ids(int $userId): array
{
    if (!schema_table_exists(db(), 'store_users')) {
        return [];
    }
    $stmt = db()->prepare('SELECT store_id FROM store_users WHERE user_id = ? ORDER BY store_id ASC');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function user_has_store(int $userId): bool
{
    return user_store_ids($userId) !== [];
}

function user_has_active_store(int $userId): bool
{
    if ($userId <= 0 || !schema_table_exists(db(), 'store_users')) {
        return false;
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM store_users su
         INNER JOIN stores s ON s.store_id = su.store_id AND s.is_active = 1
         WHERE su.user_id = ?'
    );
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn() > 0;
}

function load_user_store_session(int $userId): bool
{
    $pdo = db();
    $activeStmt = $pdo->prepare(
        'SELECT su.store_id FROM store_users su
         INNER JOIN stores s ON s.store_id = su.store_id AND s.is_active = 1
         WHERE su.user_id = ?
         ORDER BY (su.role = \'admin\') DESC, su.store_id DESC'
    );
    $activeStmt->execute([$userId]);
    $activeStores = array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN));
    if ($activeStores === []) {
        unset($_SESSION['store_id'], $_SESSION['store_name'], $_SESSION['store_role']);
        return false;
    }

    $preferred = (int) ($_SESSION['store_id'] ?? 0);
    if ($preferred > 0 && in_array($preferred, $activeStores, true)) {
        set_session_store($preferred);
        return true;
    }

    set_session_store($activeStores[0]);
    return true;
}

function store_id_param(): int
{
    $id = current_store_id();
    if ($id <= 0) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Store setup required.', 'needs_store' => true], 403);
        }
        header('Location: ' . app_url('setup_store.php'));
        exit;
    }
    return $id;
}

function store_id_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? $alias . '.' : '';
    return $prefix . 'store_id = ?';
}

function store_setup_exempt_script(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/setup_store.php', '/signup.php', '/login.php', '/forgot_password.php', '/reset_password.php', '/api/google_auth.php', '/api/auth.php', '/api/password_reset.php'] as $exempt) {
        if (str_ends_with($script, $exempt)) {
            return true;
        }
    }
    return false;
}

function require_store_context(): void
{
    if (empty($_SESSION['user_id']) || store_setup_exempt_script()) {
        return;
    }

    if (is_super_admin()) {
        return;
    }

    if (!schema_table_exists(db(), 'stores')) {
        return;
    }

    if (user_has_active_store((int) $_SESSION['user_id'])) {
        if (current_store_id() <= 0) {
            load_user_store_session((int) $_SESSION['user_id']);
        }
        return;
    }

    if (user_has_store((int) $_SESSION['user_id'])) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Your store has been suspended. Contact support.'], 403);
        }
        header('Location: ' . app_url('login.php?error=' . rawurlencode('Your store has been suspended. Contact support.')));
        exit;
    }

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'Complete your store setup first.', 'needs_store' => true], 403);
    }
    header('Location: ' . app_url('setup_store.php'));
    exit;
}

/**
 * @param array{store_name:string,business_type?:string,address?:string,phone?:string,contact_email?:string} $storeData
 */
function create_store_for_user(int $userId, array $storeData): array
{
    $storeName = trim((string) ($storeData['store_name'] ?? ''));
    if ($storeName === '') {
        return ['ok' => false, 'error' => 'Store name is required.'];
    }

    $pdo = db();
    $slug = make_unique_store_slug($pdo, $storeName);
    $businessType = trim((string) ($storeData['business_type'] ?? ''));
    $address = trim((string) ($storeData['address'] ?? ''));
    $phone = trim((string) ($storeData['phone'] ?? ''));
    $contactEmail = strtolower(trim((string) ($storeData['contact_email'] ?? '')));

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO stores (store_name, business_type, address, phone, contact_email, slug, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $storeName,
            $businessType !== '' ? $businessType : null,
            $address !== '' ? $address : null,
            $phone !== '' ? $phone : null,
            $contactEmail !== '' ? $contactEmail : null,
            $slug,
        ]);
        $storeId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)'
        )->execute([$storeId, $userId, 'admin']);

        $pdo->prepare('UPDATE users SET role = ? WHERE user_id = ?')->execute(['admin', $userId]);

        $pdo->commit();

        set_session_store($storeId);
        return ['ok' => true, 'store_id' => $storeId, 'store_name' => $storeName];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not create store. Please try again.'];
    }
}

/**
 * Link an existing user to the current store (admin invites staff).
 */
function add_user_to_current_store(int $userId, string $role = 'staff'): void
{
    $storeId = store_id_param();
    $role = strtolower($role) === 'admin' ? 'admin' : 'staff';
    $pdo = db();
    $pdo->prepare(
        'INSERT IGNORE INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)'
    )->execute([$storeId, $userId, $role]);
}
