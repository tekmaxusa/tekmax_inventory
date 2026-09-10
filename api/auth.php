<?php
/**
 * Auth API — login / logout
 * POST { "action": "login", "email": "...", "password": "...", "csrf_token": "..." }
 * POST { "action": "logout" }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $method === 'POST' ? array_merge($_POST, read_json_body()) : [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

if ($method === 'POST' && $action === 'login') {
    require_csrf();
    $email = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $ipBucket = 'login:ip:' . client_ip();
    $emailBucket = 'login:email:' . strtolower($email);
    $ipStatus = rate_limit_status($ipBucket, 5, 900);
    $emailStatus = rate_limit_status($emailBucket, 8, 900);

    if (!$ipStatus['ok'] || !$emailStatus['ok']) {
        $wait = max($ipStatus['retry_after'], $emailStatus['retry_after']);
        json_response(['ok' => false, 'error' => 'Too many failed sign-in attempts. Try again in ' . $wait . ' seconds.'], 429);
    }

    if ($email === '' || $password === '') {
        json_response(['ok' => false, 'error' => 'Email and password are required.'], 422);
    }

    $stmt = db()->prepare(
        'SELECT user_id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $hash = (string) ($user['password_hash'] ?? '');
    $valid = $user && $hash !== '' && password_verify($password, $hash);
    $active = $valid && (int) ($user['is_active'] ?? 1) === 1;

    if (!$valid || !$active) {
        rate_limit_hit($ipBucket, 900);
        rate_limit_hit($emailBucket, 900);
        $message = !$valid
            ? ($user && $hash === '' ? 'This account uses Google sign-in. Continue with Google instead.' : 'Invalid email or password.')
            : 'This account is disabled. Ask an admin to reactivate it.';
        json_response([
            'ok' => false,
            'error' => $message,
        ], 401);
    }

    rate_limit_clear($ipBucket);
    rate_limit_clear($emailBucket);
    login_user($user);

    json_response([
        'ok' => true,
        'redirect' => post_login_redirect_url(),
        'csrf_token' => csrf_token(),
        'user' => [
            'user_id' => (int) $user['user_id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ],
    ]);
}

if ($action === 'logout') {
    if ($method === 'POST') {
        require_csrf();
    } elseif ($method === 'GET') {
        // GET logout is disabled (CSRF). Use the Sign out button.
        header('Location: ' . app_url('login.php'));
        exit;
    } else {
        json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
    }

    clear_login_session();

    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    $preferJson = str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    if ($preferJson) {
        json_response(['ok' => true, 'redirect' => app_url('login.php')]);
    }
    header('Location: ' . app_url('login.php'));
    exit;
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
