<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';
require_once __DIR__ . '/includes/password_reset.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . post_login_redirect_url());
    exit;
}

$error = '';
$success = '';
$email = strtolower(trim((string) ($_POST['email'] ?? $_GET['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(request_csrf_token())) {
        $error = 'Session expired. Please try again.';
    } else {
        $result = password_reset_request((string) ($_POST['email'] ?? ''));
        if ($result['ok']) {
            $success = (string) ($result['message'] ?? 'Check your email for the reset code.');
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        } else {
            $error = (string) ($result['error'] ?? 'Could not send reset code.');
        }
    }
}

render_auth_head('Forgot password');
render_auth_shell_start('login', 'Forgot your password?', 'Enter your account email and we will send a one-time code (OTP).');
if ($success !== '') {
    ?>
    <div class="auth-alert auth-alert-success" role="status">
      <i class="bi bi-check-circle"></i>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
    <?php if ($email !== ''): ?>
      <a class="btn btn-accent w-100 mb-2" href="<?= htmlspecialchars(app_url('reset_password.php?email=' . rawurlencode($email))) ?>">
        Enter reset code
      </a>
    <?php endif;
} else {
    render_auth_alert($error);
    ?>
    <form method="post" action="" class="auth-form">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <?php
      render_auth_field('email', 'Email address', 'email', [
          'value' => $email,
          'placeholder' => 'you@company.com',
          'required' => true,
      ]);
      ?>
      <button type="submit" class="btn btn-accent auth-submit w-100">Send reset code</button>
    </form>
    <?php
}
render_auth_shell_end('Remembered your password?', app_url('login.php'), 'Sign in');
?>
<p class="auth-hint">Local dev tip: with <code>MAIL_DRIVER=log</code>, OTP codes are saved in <code>storage/logs/mail.log</code>.</p>
<?php
