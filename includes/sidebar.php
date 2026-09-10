<?php
declare(strict_types=1);

if (is_super_admin()) {
    $navGroups = [
        [
            'label' => 'Platform',
            'items' => [
                'platform' => ['label' => 'Overview', 'href' => app_url('platform.php'), 'icon' => 'bi-shield-lock'],
            ],
        ],
    ];
} else {
    $navGroups = [
        [
            'label' => 'Inventory',
            'items' => [
                'dashboard'  => ['label' => 'Dashboard',        'href' => app_url('dashboard.php'),   'icon' => 'bi-grid'],
                'products'   => ['label' => 'Item List',        'href' => app_url('products.php'),    'icon' => 'bi-box-seam'],
                'stock'      => ['label' => 'Stock In / Out',   'href' => app_url('stock_in_out.php'),'icon' => 'bi-arrow-left-right'],
                'categories' => ['label' => 'Categories',       'href' => app_url('categories.php'),  'icon' => 'bi-folder'],
            ],
        ],
        [
            'label' => 'Tools',
            'items' => [
                'audit'   => ['label' => 'Inventory Count', 'href' => app_url('audit.php'),   'icon' => 'bi-clipboard-check'],
                'reports' => ['label' => 'Reports',         'href' => app_url('reports.php'), 'icon' => 'bi-bar-chart-line'],
            ],
        ],
    ];
    if (is_admin()) {
        $navGroups[] = [
            'label' => 'Admin',
            'items' => [
                'users' => ['label' => 'Users', 'href' => app_url('users.php'), 'icon' => 'bi-people'],
            ],
        ];
    }
}
$currentPage = $currentPage ?? '';
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <div class="brand-mark">IMS</div>
    <div class="brand-text">
      <strong><?= is_super_admin() ? 'Platform' : 'Inventory' ?></strong>
      <small><?= is_super_admin() ? 'Super Admin' : 'Management' ?></small>
    </div>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navGroups as $group): ?>
      <div class="sidebar-section-label"><?= htmlspecialchars($group['label']) ?></div>
      <?php foreach ($group['items'] as $key => $item): ?>
        <a href="<?= $item['href'] ?>"
           class="nav-item <?= $currentPage === $key ? 'active' : '' ?>">
          <i class="bi <?= $item['icon'] ?>"></i>
          <span><?= $item['label'] ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-section-label">Account</div>
    <form method="post" action="<?= htmlspecialchars(app_url('api/auth.php')) ?>">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <button type="submit" class="nav-item nav-item-btn">
        <i class="bi bi-box-arrow-left"></i>
        <span>Sign out</span>
      </button>
    </form>
  </div>
</aside>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
