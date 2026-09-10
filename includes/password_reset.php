<?php
/**
 * Password reset OTP helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

function password_reset_generate_otp(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function password_reset_request(string $email): array
{
    require_once __DIR__ . '/rate_limit.php';

    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    $ipBucket = 'reset:ip:' . client_ip();
    $emailBucket = 'reset:email:' . $email;
    $ipStatus = rate_limit_status($ipBucket, 5, 3600);
    $emailStatus = rate_limit_status($emailBucket, 3, 3600);
    if (!$ipStatus['ok'] || !$emailStatus['ok']) {
        $wait = max($ipStatus['retry_after'], $emailStatus['retry_after']);
        return ['ok' => false, 'error' => 'Too many reset attempts. Try again in ' . $wait . ' seconds.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT user_id, name, email, password_hash, is_active, role FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Always return generic success to avoid email enumeration
    $generic = [
        'ok' => true,
        'message' => 'If an account exists for that email, a reset code has been sent.',
    ];

    if (!$user || (int) ($user['is_active'] ?? 1) !== 1) {
        rate_limit_hit($ipBucket, 3600);
        rate_limit_hit($emailBucket, 3600);
        return $generic;
    }

    if (strtolower((string) ($user['role'] ?? '')) === 'super_admin') {
        // Super admin can still reset via OTP if they have password
    }

    if ((string) ($user['password_hash'] ?? '') === '') {
        return [
            'ok' => false,
            'error' => 'This account uses Google sign-in. Continue with Google instead.',
        ];
    }

    $otp = password_reset_generate_otp();
    $hash = password_hash($otp, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + 900);

    $pdo->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([(int) $user['user_id']]);

    $pdo->prepare(
        'INSERT INTO password_reset_otps (user_id, email, otp_hash, expires_at) VALUES (?, ?, ?, ?)'
    )->execute([(int) $user['user_id'], $email, $hash, $expires]);

    $sent = send_password_reset_otp_email($email, (string) $user['name'], $otp);
    if (!$sent['ok']) {
        return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'Could not send email. Check mail configuration.')];
    }

    rate_limit_hit($ipBucket, 3600);
    rate_limit_hit($emailBucket, 3600);
    return $generic;
}

function password_reset_complete(string $email, string $otp, string $newPassword): array
{
    require_once __DIR__ . '/rate_limit.php';

    $email = strtolower(trim($email));
    $otp = trim($otp);
    if ($email === '' || $otp === '' || strlen($newPassword) < 8) {
        return ['ok' => false, 'error' => 'Email, OTP, and a new password (8+ chars) are required.'];
    }

    $bucket = 'reset-verify:' . $email;
    $status = rate_limit_status($bucket, 8, 900);
    if (!$status['ok']) {
        return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . $status['retry_after'] . ' seconds.'];
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT r.reset_id, r.user_id, r.otp_hash, r.expires_at, u.is_active
         FROM password_reset_otps r
         JOIN users u ON u.user_id = r.user_id
         WHERE r.email = ? AND r.used_at IS NULL
         ORDER BY r.reset_id DESC
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || (int) ($row['is_active'] ?? 1) !== 1) {
        rate_limit_hit($bucket, 900);
        return ['ok' => false, 'error' => 'Invalid or expired reset code.'];
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        rate_limit_hit($bucket, 900);
        return ['ok' => false, 'error' => 'This reset code has expired. Request a new one.'];
    }

    if (!password_verify($otp, (string) $row['otp_hash'])) {
        rate_limit_hit($bucket, 900);
        return ['ok' => false, 'error' => 'Invalid or expired reset code.'];
    }

    $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $row['user_id']]);
    $pdo->prepare('UPDATE password_reset_otps SET used_at = NOW() WHERE reset_id = ?')
        ->execute([(int) $row['reset_id']]);

    rate_limit_clear($bucket);
    return ['ok' => true, 'message' => 'Password updated. You can sign in now.'];
}
