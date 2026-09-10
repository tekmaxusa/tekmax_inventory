<?php
/**
 * Shared session helpers, auth gate, roles, and CSRF.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

configure_secure_session();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
send_security_headers();

/** Idle session timeout — clears login if inactive too long. */
function enforce_session_idle_timeout(): void
{
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $now = time();
    $last = (int) ($_SESSION['_last_activity'] ?? $now);
    if (($now - $last) > APP_SESSION_IDLE_SECONDS) {
        clear_login_session();
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Session expired. Please sign in again.'], 401);
        }
        header('Location: ' . app_url('login.php?error=' . rawurlencode('Session expired. Please sign in again.')));
        exit;
    }
    $_SESSION['_last_activity'] = $now;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = db()->prepare(
        'SELECT user_id, name, email, role, is_active FROM users WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}

function global_user_role(): string
{
    $role = strtolower((string) (current_user()['role'] ?? $_SESSION['user_role'] ?? 'staff'));
    if ($role === 'super_admin') {
        return 'super_admin';
    }
    return $role === 'admin' ? 'admin' : 'staff';
}

function is_super_admin(): bool
{
    return global_user_role() === 'super_admin';
}

function user_role(): string
{
    if (is_super_admin()) {
        return 'super_admin';
    }
    $storeRole = strtolower((string) ($_SESSION['store_role'] ?? ''));
    if ($storeRole === 'admin' || $storeRole === 'staff') {
        return $storeRole;
    }
    $role = strtolower((string) (current_user()['role'] ?? $_SESSION['user_role'] ?? 'staff'));
    return $role === 'admin' ? 'admin' : 'staff';
}

function is_admin(): bool
{
    return user_role() === 'admin';
}

function is_platform_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    foreach (['/platform.php', '/api/platform.php'] as $path) {
        if (str_contains($script, $path)) {
            return true;
        }
    }
    return false;
}

function post_login_redirect_url(): string
{
    if (is_super_admin()) {
        return app_url('platform.php');
    }
    if (!user_has_active_store((int) ($_SESSION['user_id'] ?? 0))) {
        return app_url('login.php?error=' . rawurlencode('Your store access has been suspended. Contact support.'));
    }
    return app_url('dashboard.php');
}

function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        forbid('Platform admin access required.');
    }
}

/**
 * True when the current request is an API/JSON endpoint.
 */
function is_api_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/api/')) {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
}

function wants_json_response(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
        return true;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($script, '/api/') && str_contains($accept, 'application/json');
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function read_json_body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $cached = [];
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/store.php';

function forbid(string $message = 'You do not have permission to do that.'): void
{
    if (is_api_request()) {
        json_response(['ok' => false, 'error' => $message], 403);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        forbid('Admin access required.');
    }
}

function clear_login_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
        }
        header('Location: ' . app_url('login.php'));
        exit;
    }

    enforce_session_idle_timeout();

    $user = current_user();
    if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
        clear_login_session();
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Account is disabled.'], 401);
        }
        header('Location: ' . app_url('login.php'));
        exit;
    }

    require_csrf();

    if (is_super_admin() && !is_platform_request()) {
        if (is_api_request()) {
            json_response(['ok' => false, 'error' => 'Use the platform admin API.'], 403);
        }
        header('Location: ' . app_url('platform.php'));
        exit;
    }

    require_store_context();
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['_last_activity'] = time();
    csrf_token(); // ensure a token exists after regenerate
    if (strtolower((string) ($user['role'] ?? '')) !== 'super_admin') {
        load_user_store_session((int) $user['user_id']);
    } else {
        unset($_SESSION['store_id'], $_SESSION['store_name'], $_SESSION['store_role']);
    }
}

/**
 * Open audit that can be resumed (in progress or saved).
 */
function active_audit_session(): ?array
{
    $storeId = current_store_id();
    if ($storeId <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT session_id, store_name, audit_date, status
         FROM audit_sessions
         WHERE store_id = ? AND status IN ('in_progress', 'saved')
         ORDER BY session_id DESC
         LIMIT 1"
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Audit that currently blocks Stock In/Out — only while counting is actively in progress.
 * Saved audits unlock stock movements until counting resumes.
 */
function locking_audit_session(): ?array
{
    $storeId = current_store_id();
    if ($storeId <= 0) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT session_id, store_name, audit_date, status
         FROM audit_sessions
         WHERE store_id = ? AND status = 'in_progress'
         ORDER BY session_id DESC
         LIMIT 1"
    );
    $stmt->execute([$storeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
