<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_super_admin();

$pageTitle = 'Platform Admin';
$pageSubtitle = 'Monitor all client stores and user accounts';
$currentPage = 'platform';
$platformMode = true;
require __DIR__ . '/includes/header.php';
?>

<div class="kpi-grid mb-4" id="platformKpis">
  <div class="kpi-card"><div class="kpi-label">Stores</div><div class="kpi-value" id="kpiStores">—</div></div>
  <div class="kpi-card"><div class="kpi-label">Active stores</div><div class="kpi-value" id="kpiStoresActive">—</div></div>
  <div class="kpi-card"><div class="kpi-label">Users</div><div class="kpi-value" id="kpiUsers">—</div></div>
  <div class="kpi-card"><div class="kpi-label">Products (all stores)</div><div class="kpi-value" id="kpiProducts">—</div></div>
</div>

<div class="filter-tabs mb-3" id="platformTabs">
  <button type="button" class="filter-tab active" data-tab="stores">Stores / Clients</button>
  <button type="button" class="filter-tab" data-tab="users">All Users</button>
</div>

<div id="platformStoresPanel">
  <div class="table-panel">
    <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
      <h2>Client stores</h2>
      <span class="text-muted-sm">Suspend or delete stores that no longer use the system</span>
    </div>
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Store</th>
            <th>Owner</th>
            <th class="num">Users</th>
            <th class="num">Products</th>
            <th>Status</th>
            <th>Created</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="platformStoresBody">
          <tr><td colspan="7" class="empty-state">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="platformUsersPanel" class="d-none">
  <div class="table-panel">
    <div class="panel-head"><h2>All users</h2></div>
    <div class="table-responsive">
      <table class="table-clean">
        <thead>
          <tr>
            <th>Name</th>
            <th>Email</th>
            <th>Stores</th>
            <th>Role</th>
            <th>Status</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="platformUsersBody">
          <tr><td colspan="6" class="empty-state">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="toast-wrap" id="toastWrap"></div>

<?php
ob_start();
?>
<script src="<?= htmlspecialchars(app_url('assets/js/platform.js')) ?>"></script>
<?php
$pageScripts = ob_get_clean();
require __DIR__ . '/includes/footer.php';
