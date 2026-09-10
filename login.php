<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';
require_once __DIR__ . '/includes/rate_limit.php';

if (!empty($_SESSION['user_id'])) {
    $existing = current_user();
    if ($existing && (int) ($existing['is_active'] ?? 1) === 1) {
        header('Location: ' . post_login_redirect_url());
        exit;
    }
}

$error = auth_page_error();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(request_csrf_token())) {
        $error = 'Session expired. Please try again.';
    } else {
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $ipBucket = 'login:ip:' . client_ip();
        $emailBucket = 'login:email:' . strtolower($email);
        $ipStatus = rate_limit_status($ipBucket, 5, 900);
        $emailStatus = rate_limit_status($emailBucket, 8, 900);

        if (!$ipStatus['ok'] || !$emailStatus['ok']) {
            $wait = max($ipStatus['retry_after'], $emailStatus['retry_after']);
            $error = 'Too many failed sign-in attempts. Try again in ' . $wait . ' seconds.';
        } elseif ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
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
                if ($user && $hash === '' && !empty($user['email'])) {
                    $error = 'This account uses Google sign-in. Continue with Google instead.';
                } else {
                    $error = !$valid
                        ? 'Invalid email or password.'
                        : 'This account is disabled. Ask an admin to reactivate it.';
                }
            } else {
                rate_limit_clear($ipBucket);
                rate_limit_clear($emailBucket);
                $role = strtolower((string) ($user['role'] ?? 'staff'));
                if ($role !== 'super_admin' && !user_has_active_store((int) $user['user_id'])) {
                    if (!user_has_store((int) $user['user_id'])) {
                        login_user($user);
                        header('Location: ' . app_url('setup_store.php'));
                        exit;
                    }
                    $error = 'Your store access has been suspended. Contact support.';
                } else {
                    login_user($user);
                    header('Location: ' . post_login_redirect_url());
                    exit;
                }
            }
        }
    }
}

render_auth_head('Sign in');
render_auth_shell_start('login', 'Sign in to your account', 'Enter your credentials to access the dashboard.');
render_auth_alert($error);
render_auth_google_button('login');
render_auth_divider();
?>
<form method="post" action="" class="auth-form" autocomplete="on">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
  <?php
  render_auth_field('email', 'Email address', 'email', [
      'value' => $_POST['email'] ?? '',
      'placeholder' => 'you@company.com',
      'required' => true,
  ]);
  render_auth_field('password', 'Password', 'password', [
      'placeholder' => 'Enter your password',
      'required' => true,
  ]);
  ?>
  <div class="auth-forgot-wrap">
    <a href="<?= htmlspecialchars(app_url('forgot_password.php')) ?>">Forgot password?</a>
  </div>
  <button type="submit" class="btn btn-accent auth-submit w-100">Sign in</button>
</form>
<?php
render_auth_shell_end(
    "Don't have an account?",
    app_url('signup.php'),
    'Create one',
    'Demo: admin@example.com / admin123 · Super admin: super@example.com / superadmin123'
);
