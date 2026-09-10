<?php
/**
 * Google OAuth — start authorization or handle callback.
 *
 * GET ?action=start&flow=login|signup
 * GET ?action=callback&code=...&state=...
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google_auth.php';

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
$flow = strtolower(trim((string) ($_GET['flow'] ?? 'login')));

if ($action === 'start') {
    if (!empty($_SESSION['user_id'])) {
        header('Location: ' . app_url('dashboard.php'));
        exit;
    }
    google_begin_oauth($flow);
}

if ($action === 'callback') {
    $code = trim((string) ($_GET['code'] ?? ''));
    $state = trim((string) ($_GET['state'] ?? ''));
    $error = trim((string) ($_GET['error'] ?? ''));
    $flowTarget = (($_SESSION['google_oauth_flow'] ?? 'login') === 'signup') ? 'signup.php' : 'login.php';

    if ($error !== '') {
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_flow']);
        header('Location: ' . app_url($flowTarget . '?error=' . rawurlencode('Google sign-in was cancelled.')));
        exit;
    }

    if ($code === '' || $state === '') {
        unset($_SESSION['google_oauth_state'], $_SESSION['google_oauth_flow']);
        header('Location: ' . app_url('login.php?error=' . rawurlencode('Missing Google authorization response.')));
        exit;
    }

    $result = google_finish_oauth($code, $state);
    if (!$result['ok']) {
        header('Location: ' . app_url($flowTarget . '?error=' . rawurlencode((string) $result['error'])));
        exit;
    }

    $userId = (int) ($result['user']['user_id'] ?? 0);
    if ($userId > 0 && strtolower((string) ($result['user']['role'] ?? '')) === 'super_admin') {
        header('Location: ' . app_url('platform.php'));
        exit;
    }
    if ($userId > 0 && !user_has_store($userId)) {
        header('Location: ' . app_url('setup_store.php'));
        exit;
    }

    header('Location: ' . app_url('dashboard.php'));
    exit;
}

http_response_code(400);
echo 'Unknown action.';
