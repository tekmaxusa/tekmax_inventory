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
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        if ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $result = password_reset_complete(
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['otp'] ?? ''),
                $password
            );
            if ($result['ok']) {
                $success = (string) ($result['message'] ?? 'Password updated.');
            } else {
                $error = (string) ($result['error'] ?? 'Could not reset password.');
            }
        }
    }
}

render_auth_head('Reset password');
render_auth_shell_start('login', 'Reset your password', 'Enter the OTP from your email and choose a new password.');
if ($success !== '') {
    ?>
    <div class="auth-alert auth-alert-success" role="status">
      <i class="bi bi-check-circle"></i>
      <span><?= htmlspecialchars($success) ?></span>
    </div>
    <a class="btn btn-accent w-100" href="<?= htmlspecialchars(app_url('login.php')) ?>">Sign in</a>
    <?php
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
      render_auth_field('otp', 'OTP code', 'text', [
          'value' => $_POST['otp'] ?? '',
          'placeholder' => '6-digit code from email',
          'required' => true,
          'maxlength' => 6,
      ]);
      render_auth_field('password', 'New password', 'password', [
          'placeholder' => 'At least 8 characters',
          'required' => true,
          'minlength' => 8,
      ]);
      render_auth_field('password_confirm', 'Confirm new password', 'password', [
          'placeholder' => 'Repeat new password',
          'required' => true,
          'minlength' => 8,
      ]);
      ?>
      <button type="submit" class="btn btn-accent auth-submit w-100">Update password</button>
    </form>
    <?php
}
render_auth_shell_end('Need a new code?', app_url('forgot_password.php'), 'Request again');
