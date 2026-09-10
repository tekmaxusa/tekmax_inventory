<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/password_reset.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required.'], 405);
}

require_csrf();

$body = array_merge($_POST, read_json_body());
$action = strtolower(trim((string) ($body['action'] ?? '')));

if ($action === 'request') {
    json_response(password_reset_request((string) ($body['email'] ?? '')));
}

if ($action === 'reset') {
    json_response(password_reset_complete(
        (string) ($body['email'] ?? ''),
        (string) ($body['otp'] ?? ''),
        (string) ($body['password'] ?? '')
    ));
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
