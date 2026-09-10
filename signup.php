<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';
require_once __DIR__ . '/includes/rate_limit.php';

if (!empty($_SESSION['user_id'])) {
    $existing = current_user();
    if ($existing && (int) ($existing['is_active'] ?? 1) === 1 && user_has_store((int) $_SESSION['user_id'])) {
        header('Location: ' . app_url('dashboard.php'));
        exit;
    }
}

$error = auth_page_error();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(request_csrf_token())) {
        $error = 'Session expired. Please try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['password_confirm'] ?? '');
        $storeName = trim((string) ($_POST['store_name'] ?? ''));
        $businessType = trim((string) ($_POST['business_type'] ?? ''));
        $address = trim((string) ($_POST['address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $contactEmail = strtolower(trim((string) ($_POST['contact_email'] ?? '')));
        $ipBucket = 'signup:ip:' . client_ip();

        $ipStatus = rate_limit_status($ipBucket, 8, 3600);
        if (!$ipStatus['ok']) {
            $error = 'Too many sign-up attempts. Try again in ' . $ipStatus['retry_after'] . ' seconds.';
        } elseif ($storeName === '') {
            $error = 'Store / business name is required.';
        } elseif ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Your name and a valid email are required.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo = db();
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role, is_active)
                     VALUES (?, ?, ?, ?, 1)'
                );
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
                $userId = (int) $pdo->lastInsertId();

                $slug = make_unique_store_slug($pdo, $storeName);
                $pdo->prepare(
                    'INSERT INTO stores (store_name, business_type, address, phone, contact_email, slug, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                )->execute([
                    $storeName,
                    $businessType !== '' ? $businessType : null,
                    $address !== '' ? $address : null,
                    $phone !== '' ? $phone : null,
                    ($contactEmail !== '' ? $contactEmail : $email),
                    $slug,
                ]);
                $storeId = (int) $pdo->lastInsertId();

                $pdo->prepare(
                    'INSERT INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)'
                )->execute([$storeId, $userId, 'admin']);

                $pdo->commit();
                rate_limit_clear($ipBucket);

                login_user([
                    'user_id' => $userId,
                    'name' => $name,
                    'email' => $email,
                    'role' => 'admin',
                ]);
                set_session_store($storeId);
                header('Location: ' . app_url('dashboard.php'));
                exit;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                rate_limit_hit($ipBucket, 3600);
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    $error = 'An account with this email already exists. Try signing in instead.';
                } else {
                    $error = 'Could not create account. Please try again.';
                }
            }
        }
    }
}

render_auth_head('Sign up');
render_auth_shell_start('signup', 'Create your account', 'Register your business and get a private inventory workspace.');
render_auth_alert($error);
render_auth_google_button('signup');
render_auth_divider();
?>
<form method="post" action="" class="auth-form" autocomplete="on">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
  <p class="auth-section-label">Your account</p>
  <?php
  render_auth_field('name', 'Full name', 'text', [
      'value' => $_POST['name'] ?? '',
      'placeholder' => 'Jane Doe',
      'required' => true,
      'maxlength' => 100,
  ]);
  render_auth_field('email', 'Email address', 'email', [
      'value' => $_POST['email'] ?? '',
      'placeholder' => 'you@company.com',
      'required' => true,
  ]);
  render_auth_field('password', 'Password', 'password', [
      'placeholder' => 'At least 8 characters',
      'required' => true,
      'minlength' => 8,
  ]);
  render_auth_field('password_confirm', 'Confirm password', 'password', [
      'placeholder' => 'Repeat your password',
      'required' => true,
      'minlength' => 8,
  ]);
  ?>
  <p class="auth-section-label mt-3">Your business</p>
  <?php
  render_auth_field('store_name', 'Store / business name', 'text', [
      'value' => $_POST['store_name'] ?? '',
      'placeholder' => 'e.g. Beauty Supply Manila',
      'required' => true,
      'maxlength' => 255,
  ]);
  ?>
  <div class="auth-field">
    <label class="auth-label" for="business_type">Business type</label>
    <div class="auth-input-wrap">
      <select class="auth-input" id="business_type" name="business_type">
        <?php
        $types = ['Retail', 'Salon / Spa', 'Warehouse', 'Wholesale', 'Online shop', 'Other'];
        $selected = $_POST['business_type'] ?? 'Retail';
        foreach ($types as $type):
        ?>
          <option value="<?= htmlspecialchars($type) ?>" <?= $selected === $type ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <?php
  render_auth_field('address', 'Business address', 'text', [
      'value' => $_POST['address'] ?? '',
      'placeholder' => 'Street, city, province',
      'maxlength' => 500,
  ]);
  render_auth_field('phone', 'Phone number', 'tel', [
      'value' => $_POST['phone'] ?? '',
      'placeholder' => '+63 9XX XXX XXXX',
      'maxlength' => 50,
  ]);
  render_auth_field('contact_email', 'Business email (optional)', 'email', [
      'value' => $_POST['contact_email'] ?? '',
      'placeholder' => 'Defaults to your login email',
  ]);
  ?>
  <button type="submit" class="btn btn-accent auth-submit w-100">Create account &amp; store</button>
</form>
<?php
render_auth_shell_end(
    'Already have an account?',
    app_url('login.php'),
    'Sign in',
    'Each new account gets its own inventory — separate from other businesses on the platform.'
);
