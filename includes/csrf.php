<?php
/**
 * CSRF tokens for cookie-authenticated forms and JSON APIs.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_verify(?string $token): bool
{
    $expected = $_SESSION['_csrf'] ?? '';
    return is_string($expected)
        && $expected !== ''
        && is_string($token)
        && $token !== ''
        && hash_equals($expected, $token);
}

function request_csrf_token(): string
{
    // Never accept CSRF tokens from the query string (leaks via Referer/logs).
    $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header !== '') {
        return $header;
    }
    if (isset($_POST['csrf_token'])) {
        return (string) $_POST['csrf_token'];
    }
    $json = read_json_body();
    if (isset($json['csrf_token'])) {
        return (string) $json['csrf_token'];
    }
    return '';
}

function is_write_request(): bool
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function require_csrf(): void
{
    if (!is_write_request()) {
        return;
    }
    if (csrf_verify(request_csrf_token())) {
        return;
    }

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'Invalid or missing CSRF token. Refresh the page and try again.'], 403);
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid or missing CSRF token. Refresh the page and try again.';
    exit;
}
