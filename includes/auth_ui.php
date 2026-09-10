<?php
/**
 * Shared markup for login / signup pages.
 */
declare(strict_types=1);

require_once __DIR__ . '/google_auth.php';

function auth_page_error(): string
{
    return trim((string) ($_GET['error'] ?? ''));
}

function auth_google_available(): bool
{
    return google_oauth_configured();
}

function render_auth_head(string $pageTitle): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> — Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
    <?php
}

function render_auth_shell_start(string $mode, string $heading, string $subtitle): void
{
    $isSignup = $mode === 'signup';
    $points = $isSignup
        ? [
            ['icon' => 'bi-shop', 'text' => 'Your own private inventory workspace'],
            ['icon' => 'bi-shield-lock', 'text' => 'Secure email or Google authentication'],
            ['icon' => 'bi-box-seam', 'text' => 'Separate from other businesses on the platform'],
        ]
        : [
            ['icon' => 'bi-speedometer2', 'text' => 'Real-time stock visibility and KPIs'],
            ['icon' => 'bi-clipboard-check', 'text' => 'Physical counts and audit workflows'],
            ['icon' => 'bi-bar-chart-line', 'text' => 'Reports and CSV export on demand'],
        ];
    ?>
<body class="auth-page">
  <div class="auth-shell">
    <aside class="auth-showcase">
      <a href="<?= htmlspecialchars(app_url('/')) ?>" class="auth-showcase-logo">
        <span class="brand-mark round">IMS</span>
        <span class="auth-showcase-logo-text">
          <strong>Inventory Management</strong>
          <small>Enterprise Platform</small>
        </span>
      </a>
      <div class="auth-showcase-copy">
        <span class="auth-showcase-eyebrow"><?= $isSignup ? 'Get started' : 'Welcome back' ?></span>
        <h1><?= $isSignup ? 'Create your business inventory account' : 'Sign in to manage inventory with confidence' ?></h1>
        <p><?= $isSignup
            ? 'Register your store to track products, stock, and audits in a workspace that belongs only to your business.'
            : 'Access dashboards, stock operations, physical counts, and reporting from one secure platform.' ?></p>
        <ul class="auth-showcase-list">
          <?php foreach ($points as $point): ?>
            <li><i class="bi <?= htmlspecialchars($point['icon']) ?>"></i><?= htmlspecialchars($point['text']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="auth-showcase-footer">
        <span>&copy; <?= date('Y') ?> Inventory Management System</span>
      </div>
    </aside>

    <main class="auth-panel">
      <div class="auth-panel-inner">
        <a href="<?= htmlspecialchars(app_url('/')) ?>" class="auth-back">
          <i class="bi bi-arrow-left"></i> Back to overview
        </a>

        <div class="auth-panel-head">
          <h2><?= htmlspecialchars($heading) ?></h2>
          <p><?= htmlspecialchars($subtitle) ?></p>
        </div>

        <?php render_auth_tabs($mode); ?>
    <?php
}

function render_auth_shell_end(string $switchPrompt, string $switchHref, string $switchLabel, string $hint = ''): void
{
    ?>
        <p class="auth-switch">
          <?= htmlspecialchars($switchPrompt) ?>
          <a href="<?= htmlspecialchars($switchHref) ?>"><?= htmlspecialchars($switchLabel) ?></a>
        </p>
        <?php if ($hint !== ''): ?>
          <p class="auth-hint"><?= $hint ?></p>
        <?php endif; ?>
      </div>
    </main>
  </div>
<?php render_auth_password_toggle_script(); ?>
</body>
</html>
    <?php
}

function render_auth_alert(string $error): void
{
    if ($error === '') {
        return;
    }
    ?>
    <div class="auth-alert" role="alert">
      <i class="bi bi-exclamation-circle"></i>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php
}

function render_auth_google_button(string $flow): void
{
    if (!auth_google_available()) {
        ?>
        <div class="auth-google-note">
          <i class="bi bi-info-circle"></i>
          <span>Google sign-in requires OAuth credentials in <code>config/google.php</code>.</span>
        </div>
        <?php
        return;
    }
    $url = htmlspecialchars(google_start_url($flow));
    $label = $flow === 'signup' ? 'Sign up with Google' : 'Continue with Google';
    ?>
    <a href="<?= $url ?>" class="btn btn-google w-100">
      <svg class="google-icon" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#EA4335" d="M12 10.2v3.84h5.38c-.23 1.22-1.56 3.58-5.38 3.58-3.24 0-5.88-2.68-5.88-5.98S6.76 5.66 10 5.66c1.85 0 3.08.79 3.79 1.47l2.58-2.49C15.64 3.34 13.95 2.5 12 2.5 6.98 2.5 2.73 6.74 2.73 11.76S6.98 21.02 12 21.02c6.08 0 7.51-4.24 7.51-6.47 0-.44-.05-.77-.11-1.1H12z"/>
        <path fill="#34A853" d="M4.27 7.55l3.15 2.31C8.18 7.99 9.97 6.5 12 6.5c1.85 0 3.08.79 3.79 1.47l2.58-2.49C15.64 3.34 13.95 2.5 12 2.5 8.64 2.5 5.8 5.05 4.27 7.55z"/>
        <path fill="#4A90E2" d="M12 21.02c3.19 0 5.86-1.04 7.81-2.84l-3.61-2.8c-.98.66-2.24 1.12-4.2 1.12-3.22 0-5.95-2.17-6.93-5.1l-3.14 2.42C5.79 18.45 8.63 21.02 12 21.02z"/>
        <path fill="#FBBC05" d="M2.73 11.76c0-.98.17-1.93.48-2.82L6.35 11.36c.22.66.58 1.24 1.04 1.7.46.46 1.04.82 1.7 1.04l3.14-2.42c-.24-.72-.38-1.49-.38-2.28 0-.79.14-1.56.38-2.28L9.09 4.7A9.96 9.96 0 0 0 2.73 11.76z"/>
      </svg>
      <?= htmlspecialchars($label) ?>
    </a>
    <?php
}

function render_auth_divider(): void
{
    ?>
    <div class="auth-divider"><span>or continue with email</span></div>
    <?php
}

function render_auth_tabs(string $active): void
{
    $loginActive = $active === 'login';
    ?>
    <div class="auth-tabs">
      <a href="<?= htmlspecialchars(app_url('login.php')) ?>" class="auth-tab <?= $loginActive ? 'active' : '' ?>">Sign in</a>
      <a href="<?= htmlspecialchars(app_url('signup.php')) ?>" class="auth-tab <?= !$loginActive ? 'active' : '' ?>">Sign up</a>
    </div>
    <?php
}

function render_auth_field(string $id, string $label, string $type = 'text', array $attrs = []): void
{
    $value = htmlspecialchars((string) ($attrs['value'] ?? ''));
    $placeholder = htmlspecialchars((string) ($attrs['placeholder'] ?? ''));
    $required = !empty($attrs['required']) ? 'required' : '';
    $extra = '';
    if (!empty($attrs['minlength'])) {
        $extra .= ' minlength="' . (int) $attrs['minlength'] . '"';
    }
    if (!empty($attrs['maxlength'])) {
        $extra .= ' maxlength="' . (int) $attrs['maxlength'] . '"';
    }
    $isPassword = $type === 'password';
    ?>
    <div class="auth-field">
      <label class="auth-label" for="<?= htmlspecialchars($id) ?>"><?= htmlspecialchars($label) ?></label>
      <div class="auth-input-wrap<?= $isPassword ? ' password-field-wrap' : '' ?>">
        <input class="auth-input" type="<?= htmlspecialchars($type) ?>" id="<?= htmlspecialchars($id) ?>"
               name="<?= htmlspecialchars($id) ?>" <?= $required ?> value="<?= $value ?>"
               placeholder="<?= $placeholder ?>"<?= $extra ?><?= $isPassword ? ' autocomplete="' . ($id === 'password' ? 'current-password' : 'new-password') . '"' : '' ?>>
        <?php if ($isPassword): ?>
          <button type="button" class="password-toggle-btn" aria-label="Show password">
            <i class="bi bi-eye" aria-hidden="true"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

function render_auth_password_toggle_script(): void
{
    ?>
<script src="<?= htmlspecialchars(app_url('assets/js/password-toggle.js')) ?>"></script>
    <?php
}
