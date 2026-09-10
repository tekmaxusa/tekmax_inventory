<?php
/**
 * App base path / environment — override via env vars in production.
 *
 * APP_ENV   = local | production
 * APP_BASE  = URL path prefix (e.g. /inventory-system or "")
 * APP_URL   = full public origin (e.g. https://inventory.example.com) — required for OAuth in prod
 */
declare(strict_types=1);

if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: 'local');
}

if (!defined('APP_BASE')) {
    // getenv false = unset → XAMPP default. Empty string = site at domain root (Docker/prod).
    $base = getenv('APP_BASE');
    if ($base === false) {
        define('APP_BASE', '/inventory-system');
    } else {
        define('APP_BASE', rtrim($base, '/'));
    }
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim((string) (getenv('APP_URL') ?: ''), '/'));
}

if (!defined('APP_SESSION_IDLE_SECONDS')) {
    $idle = (int) (getenv('APP_SESSION_IDLE_SECONDS') ?: 7200); // 2 hours
    define('APP_SESSION_IDLE_SECONDS', $idle > 0 ? $idle : 7200);
}

function app_is_production(): bool
{
    return strtolower((string) APP_ENV) === 'production';
}

function app_url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    if ($path === '/') {
        return (APP_BASE === '' ? '' : APP_BASE) . '/';
    }
    return (APP_BASE === '' ? '' : APP_BASE) . $path;
}

/**
 * Absolute public origin for OAuth / email links. Prefer APP_URL in production.
 */
function app_public_origin(): string
{
    if (APP_URL !== '') {
        return APP_URL;
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';

    // Prefer SERVER_NAME over client-controlled HTTP_HOST.
    $host = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $port = (int) ($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $defaultPort = $https ? 443 : 80;
    if ($port > 0 && $port !== $defaultPort && !str_contains($host, ':')) {
        $host .= ':' . $port;
    }

    return $scheme . '://' . $host;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (app_is_production() || ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Harden session cookie flags before session_start().
 */
function configure_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Secure cookies only when the public URL / request is actually HTTPS
    // (do not force Secure solely from APP_ENV — breaks local Docker on http://localhost).
    $secure = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'))
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
        || (APP_URL !== '' && str_starts_with(strtolower(APP_URL), 'https://'));

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($secure) {
        ini_set('session.cookie_secure', '1');
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE === '' ? '/' : APP_BASE . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
