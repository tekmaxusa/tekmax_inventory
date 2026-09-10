<?php
declare(strict_types=1);
/**
 * Shared page header. Expects $pageTitle and optional $pageSubtitle, $topbarActions.
 * Call require_login() before including this file.
 */
$user = current_user();
$store = current_store();
$platformMode = !empty($platformMode);
$storeDisplayName = $platformMode
    ? 'Platform Admin'
    : ($store['store_name'] ?? ($_SESSION['store_name'] ?? 'My Store'));
$pageTitle = $pageTitle ?? 'Inventory';
$pageSubtitle = $pageSubtitle ?? '';
$currentPage = $currentPage ?? '';
$activeAudit = locking_audit_session();
$hidePageHeader = !empty($hidePageHeader);
$isAdmin = is_admin();
$isSuper = is_super_admin();
$roleLabel = $isSuper ? 'Super Admin' : ($isAdmin ? 'Admin' : 'Staff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>" rel="stylesheet">
  <script>
    window.APP_BASE = <?= json_encode(APP_BASE, JSON_UNESCAPED_SLASHES) ?>;
    window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    window.APP_ROLE = <?= json_encode(user_role()) ?>;
    window.APP_IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    window.APP_IS_SUPER = <?= $isSuper ? 'true' : 'false' ?>;
  </script>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/sidebar.php'; ?>
  <div class="main-wrap">
    <header class="topbar">
      <button type="button" class="btn btn-ghost d-lg-none" id="sidebarToggle" aria-label="Menu">
        <i class="bi bi-list fs-4"></i>
      </button>
      <div class="topbar-team">
        <span class="team-dot"></span>
        <span><?= htmlspecialchars($storeDisplayName) ?></span>
      </div>
      <div class="topbar-actions">
        <div class="dropdown">
          <button type="button" class="user-chip user-chip-btn" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="user-avatar"><?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?></span>
            <span class="d-none d-md-inline"><?= htmlspecialchars($user['name'] ?? '') ?></span>
            <span class="role-pill"><?= htmlspecialchars($roleLabel) ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <?php if (!$isSuper): ?>
            <li><button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#passwordModal">
              <i class="bi bi-key me-2"></i>Change password
            </button></li>
            <li><hr class="dropdown-divider"></li>
            <?php endif; ?>
            <li>
              <form method="post" action="<?= htmlspecialchars(app_url('api/auth.php')) ?>">
                <input type="hidden" name="action" value="logout">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="dropdown-item text-danger">
                  <i class="bi bi-box-arrow-left me-2"></i>Sign out
                </button>
              </form>
            </li>
          </ul>
        </div>
      </div>
    </header>
    <main class="main-content">
      <?php if (!$hidePageHeader): ?>
      <div class="page-header">
        <div>
          <h1 class="page-title"><?= htmlspecialchars($pageTitle) ?></h1>
          <?php if ($pageSubtitle !== ''): ?>
            <p class="page-subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
          <?php endif; ?>
        </div>
        <?php if (!empty($topbarActions)): ?>
          <div class="page-header-actions"><?= $topbarActions ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($activeAudit && ($currentPage ?? '') === 'stock'): ?>
        <div class="alert alert-warning audit-banner mb-3">
          <i class="bi bi-exclamation-triangle-fill me-2"></i>
          Audit in progress — stock movements are paused until you <strong>Save</strong> or <strong>Close</strong> the audit
          (<?= htmlspecialchars($activeAudit['store_name']) ?>, <?= htmlspecialchars($activeAudit['audit_date']) ?>).
        </div>
      <?php endif; ?>
