<?php
/**
 * Google OAuth 2.0 helpers (no Composer dependency).
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/google.php';

function google_oauth_configured(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}

function google_redirect_uri(): string
{
    // Use configured APP_URL in production; never trust raw HTTP_HOST alone.
    return app_public_origin() . app_url('api/google_auth.php?action=callback');
}

function google_http_post(string $url, array $fields): ?array
{
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
    }

    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

function google_http_get(string $url, string $accessToken): ?array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }
    }

    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : null;
}

function google_start_url(string $flow = 'login'): string
{
    return app_url('api/google_auth.php?action=start&flow=' . rawurlencode($flow));
}

function google_begin_oauth(string $flow = 'login'): void
{
    if (!google_oauth_configured()) {
        http_response_code(503);
        echo 'Google sign-in is not configured. Set credentials in config/google.php';
        exit;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['google_oauth_state'] = $state;
    $_SESSION['google_oauth_flow'] = $flow === 'signup' ? 'signup' : 'login';

    $params = http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
        'access_type' => 'online',
    ]);

    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function google_finish_oauth(string $code, string $state): array
{
    $expected = (string) ($_SESSION['google_oauth_state'] ?? '');
    unset($_SESSION['google_oauth_state']);
    $flow = ($_SESSION['google_oauth_flow'] ?? 'login') === 'signup' ? 'signup' : 'login';
    unset($_SESSION['google_oauth_flow']);

    if ($expected === '' || !hash_equals($expected, $state)) {
        return ['ok' => false, 'error' => 'Invalid OAuth state. Please try again.'];
    }

    $token = google_http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => google_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);

    if (!$token || empty($token['access_token'])) {
        return ['ok' => false, 'error' => 'Could not complete Google sign-in. Please try again.'];
    }

    $profile = google_http_get('https://www.googleapis.com/oauth2/v2/userinfo', (string) $token['access_token']);
    if (!$profile || empty($profile['email'])) {
        return ['ok' => false, 'error' => 'Could not read your Google profile.'];
    }

    if (empty($profile['verified_email'])) {
        return ['ok' => false, 'error' => 'Your Google email must be verified.'];
    }

    return google_upsert_user($profile, $flow);
}

function google_upsert_user(array $profile, string $flow): array
{
    $googleId = (string) ($profile['id'] ?? '');
    $email = strtolower(trim((string) $profile['email']));
    $name = trim((string) ($profile['name'] ?? $profile['given_name'] ?? 'Google User'));

    if ($googleId === '' || $email === '') {
        return ['ok' => false, 'error' => 'Incomplete Google profile.'];
    }

    $pdo = db();

    $byGoogle = $pdo->prepare(
        'SELECT user_id, name, email, role, is_active FROM users WHERE google_id = ? LIMIT 1'
    );
    $byGoogle->execute([$googleId]);
    $user = $byGoogle->fetch();

    if (!$user) {
        $byEmail = $pdo->prepare(
            'SELECT user_id, name, email, role, is_active, google_id FROM users WHERE email = ? LIMIT 1'
        );
        $byEmail->execute([$email]);
        $existing = $byEmail->fetch();

        if ($existing) {
            if (!empty($existing['google_id']) && $existing['google_id'] !== $googleId) {
                return ['ok' => false, 'error' => 'This email is linked to a different Google account.'];
            }
            $pdo->prepare('UPDATE users SET google_id = ?, name = ? WHERE user_id = ?')
                ->execute([$googleId, $name !== '' ? $name : $existing['name'], (int) $existing['user_id']]);
            $user = [
                'user_id' => (int) $existing['user_id'],
                'name' => $name !== '' ? $name : $existing['name'],
                'email' => $existing['email'],
                'role' => $existing['role'],
                'is_active' => $existing['is_active'],
            ];
        } else {
            if ($flow === 'login') {
                return ['ok' => false, 'error' => 'No account found for this Google email. Please sign up first.'];
            }
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, google_id, role, is_active)
                 VALUES (?, ?, NULL, ?, ?, 1)'
            );
            $stmt->execute([$name !== '' ? $name : 'Google User', $email, $googleId, 'staff']);
            $user = [
                'user_id' => (int) $pdo->lastInsertId(),
                'name' => $name !== '' ? $name : 'Google User',
                'email' => $email,
                'role' => 'staff',
                'is_active' => 1,
            ];
        }
    }

    if ((int) ($user['is_active'] ?? 1) !== 1) {
        return ['ok' => false, 'error' => 'This account is disabled. Ask an admin to reactivate it.'];
    }

    login_user($user);
    return ['ok' => true, 'user' => $user];
}
