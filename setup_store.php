<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/auth_ui.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ' . app_url('login.php'));
    exit;
}

if (user_has_store((int) $_SESSION['user_id'])) {
    header('Location: ' . app_url('dashboard.php'));
    exit;
}

$error = auth_page_error();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify(request_csrf_token())) {
        $error = 'Session expired. Please try again.';
    } else {
        $result = create_store_for_user((int) $_SESSION['user_id'], [
            'store_name' => trim((string) ($_POST['store_name'] ?? '')),
            'business_type' => trim((string) ($_POST['business_type'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'contact_email' => strtolower(trim((string) ($_POST['contact_email'] ?? ''))),
        ]);
        if ($result['ok']) {
            header('Location: ' . app_url('dashboard.php'));
            exit;
        }
        $error = (string) ($result['error'] ?? 'Could not set up your store.');
    }
}

$user = current_user();
render_auth_head('Set up your store');
render_auth_shell_start('signup', 'Set up your business', 'Tell us about your store. This name appears in your dashboard header and keeps your inventory separate from other accounts.');
render_auth_alert($error);
?>
<form method="post" action="" class="auth-form" autocomplete="on">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
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
  render_auth_field('contact_email', 'Business email', 'email', [
      'value' => $_POST['contact_email'] ?? ($user['email'] ?? ''),
      'placeholder' => 'contact@yourstore.com',
  ]);
  ?>
  <button type="submit" class="btn btn-accent auth-submit w-100">Create my inventory workspace</button>
</form>
<?php
render_auth_shell_end(
    'Wrong account?',
    app_url('login.php'),
    'Sign out and use another account',
    'Each account gets its own isolated inventory. Other stores cannot see your products or stock.'
);
