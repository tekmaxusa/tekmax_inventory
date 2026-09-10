<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . post_login_redirect_url());
    exit;
}

$capabilities = [
    [
        'icon' => 'bi-grid',
        'title' => 'Executive Dashboard',
        'desc' => 'Monitor stock value, product counts, low-stock alerts, and recent movements from a centralized control panel.',
        'tag' => 'Analytics',
    ],
    [
        'icon' => 'bi-box-seam',
        'title' => 'Product Catalog',
        'desc' => 'Maintain a complete item registry with SKU, barcode, RFID, pricing, location tags, and product imagery.',
        'tag' => 'Catalog',
    ],
    [
        'icon' => 'bi-arrow-left-right',
        'title' => 'Stock Movements',
        'desc' => 'Record stock in and stock out transactions with reason codes, references, and full audit history.',
        'tag' => 'Operations',
    ],
    [
        'icon' => 'bi-folder',
        'title' => 'Category Management',
        'desc' => 'Structure inventory by category and navigate directly to filtered product lists for faster review.',
        'tag' => 'Organization',
    ],
    [
        'icon' => 'bi-clipboard-check',
        'title' => 'Physical Inventory Count',
        'desc' => 'Conduct cycle counts and full audits using manual entry, barcode scanning, or bulk CSV / RFID import.',
        'tag' => 'Audit',
    ],
    [
        'icon' => 'bi-bar-chart-line',
        'title' => 'Reporting & Export',
        'desc' => 'Generate valuation summaries, movement logs, and low-stock reports with one-click CSV export.',
        'tag' => 'Reporting',
    ],
];

$workflow = [
    [
        'step' => '01',
        'title' => 'Centralize inventory data',
        'desc' => 'Register products, assign categories, and establish reorder levels across your operation.',
    ],
    [
        'step' => '02',
        'title' => 'Track daily stock activity',
        'desc' => 'Log receipts, sales, returns, and adjustments with complete movement traceability.',
    ],
    [
        'step' => '03',
        'title' => 'Reconcile and report',
        'desc' => 'Run physical counts, close audits, and export insights for management review.',
    ],
];

$accessMatrix = [
    ['capability' => 'Dashboard & reports', 'admin' => true, 'staff' => true],
    ['capability' => 'Product create & edit', 'admin' => true, 'staff' => true],
    ['capability' => 'Stock in / out', 'admin' => true, 'staff' => true],
    ['capability' => 'Inventory count (save)', 'admin' => true, 'staff' => true],
    ['capability' => 'Category management', 'admin' => true, 'staff' => false],
    ['capability' => 'Product deletion', 'admin' => true, 'staff' => false],
    ['capability' => 'Close audit & reconcile', 'admin' => true, 'staff' => false],
    ['capability' => 'User administration', 'admin' => true, 'staff' => false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Enterprise inventory management for retail and warehouse operations. Track stock, run audits, and generate reports.">
  <title>Inventory Management System — Platform Overview</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="landing-page">
  <header class="landing-header">
    <div class="landing-container landing-header-inner">
      <a href="<?= htmlspecialchars(app_url('/')) ?>" class="landing-logo">
        <span class="brand-mark round">IMS</span>
        <span class="landing-logo-text">
          <strong>Inventory Management</strong>
          <small>Enterprise Platform</small>
        </span>
      </a>
      <nav class="landing-nav" aria-label="Primary">
        <a href="#platform">Platform</a>
        <a href="#workflow">Workflow</a>
        <a href="#access">Access Control</a>
      </nav>
      <div class="landing-header-actions">
        <a href="<?= htmlspecialchars(app_url('login.php')) ?>" class="btn btn-outline-accent d-none d-md-inline-flex">Sign in</a>
        <a href="<?= htmlspecialchars(app_url('signup.php')) ?>" class="btn btn-accent">Create Account</a>
      </div>
    </div>
  </header>

  <main>
    <section class="landing-hero">
      <div class="landing-container">
        <div class="landing-hero-grid">
          <div class="landing-hero-copy">
            <span class="landing-eyebrow">Retail &amp; warehouse operations</span>
            <h1>Professional inventory control for modern teams</h1>
            <p class="landing-lead">
              A unified platform to manage product catalogs, monitor stock levels,
              execute physical counts, and produce actionable inventory reports — designed for accuracy, accountability, and scale.
            </p>
            <ul class="landing-hero-points">
              <li><i class="bi bi-check-circle-fill"></i> Real-time stock visibility and movement history</li>
              <li><i class="bi bi-check-circle-fill"></i> Barcode, manual, and bulk count workflows</li>
              <li><i class="bi bi-check-circle-fill"></i> Role-based permissions for admin and staff</li>
            </ul>
            <div class="landing-hero-actions">
              <a href="<?= htmlspecialchars(app_url('signup.php')) ?>" class="btn btn-accent btn-lg">
                Create Account
              </a>
              <a href="<?= htmlspecialchars(app_url('login.php')) ?>" class="btn btn-outline-accent btn-lg">Sign in</a>
            </div>
          </div>

          <div class="landing-preview" aria-hidden="true">
            <div class="landing-preview-chrome">
              <span></span><span></span><span></span>
              <div class="landing-preview-title">IMS Dashboard</div>
            </div>
            <div class="landing-preview-body">
              <div class="landing-preview-sidebar">
                <div class="landing-preview-brand">IMS</div>
                <div class="landing-preview-nav-item active"></div>
                <div class="landing-preview-nav-item"></div>
                <div class="landing-preview-nav-item"></div>
                <div class="landing-preview-nav-item"></div>
              </div>
              <div class="landing-preview-main">
                <div class="landing-preview-kpis">
                  <div class="landing-preview-kpi">
                    <small>Total Products</small>
                    <strong>248</strong>
                  </div>
                  <div class="landing-preview-kpi">
                    <small>Stock Value</small>
                    <strong>$42.8K</strong>
                  </div>
                  <div class="landing-preview-kpi warn">
                    <small>Low Stock</small>
                    <strong>12</strong>
                  </div>
                  <div class="landing-preview-kpi danger">
                    <small>Out of Stock</small>
                    <strong>3</strong>
                  </div>
                </div>
                <div class="landing-preview-table">
                  <div class="landing-preview-row head">
                    <span>Product</span><span>Qty</span><span>Status</span>
                  </div>
                  <div class="landing-preview-row">
                    <span>Sensationnel Cloud 9 Wig</span><span>10</span><span class="ok">In stock</span>
                  </div>
                  <div class="landing-preview-row">
                    <span>Mielle Rosemary Mint Oil</span><span>4</span><span class="warn">Low</span>
                  </div>
                  <div class="landing-preview-row">
                    <span>Ruby Kisses Lip Gloss</span><span>0</span><span class="danger">Out</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-metrics">
      <div class="landing-container">
        <div class="landing-metrics-grid">
          <div class="landing-metric">
            <strong>6</strong>
            <span>Integrated modules</span>
          </div>
          <div class="landing-metric">
            <strong>3</strong>
            <span>Inventory count methods</span>
          </div>
          <div class="landing-metric">
            <strong>2</strong>
            <span>Permission-based roles</span>
          </div>
          <div class="landing-metric">
            <strong>CSV</strong>
            <span>Export-ready reporting</span>
          </div>
        </div>
      </div>
    </section>

    <section class="landing-section" id="platform">
      <div class="landing-container">
        <div class="landing-section-head centered">
          <span class="landing-section-label">Platform Capabilities</span>
          <h2>End-to-end inventory management</h2>
          <p>
            Every module is built to support operational accuracy — from catalog maintenance
            and stock transactions to audit reconciliation and executive reporting.
          </p>
        </div>
        <div class="landing-feature-grid">
          <?php foreach ($capabilities as $feature): ?>
            <article class="landing-feature-card">
              <div class="landing-feature-top">
                <div class="landing-feature-icon"><i class="bi <?= htmlspecialchars($feature['icon']) ?>"></i></div>
                <span class="landing-feature-tag"><?= htmlspecialchars($feature['tag']) ?></span>
              </div>
              <h3><?= htmlspecialchars($feature['title']) ?></h3>
              <p><?= htmlspecialchars($feature['desc']) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="landing-section landing-section-dark" id="workflow">
      <div class="landing-container">
        <div class="landing-section-head centered light">
          <span class="landing-section-label">Operational Workflow</span>
          <h2>Structured process from intake to reconciliation</h2>
          <p>A clear, repeatable workflow that keeps inventory data reliable across your team.</p>
        </div>
        <div class="landing-workflow-grid">
          <?php foreach ($workflow as $item): ?>
            <article class="landing-workflow-card">
              <span class="landing-workflow-step"><?= htmlspecialchars($item['step']) ?></span>
              <h3><?= htmlspecialchars($item['title']) ?></h3>
              <p><?= htmlspecialchars($item['desc']) ?></p>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="landing-section landing-section-muted" id="access">
      <div class="landing-container">
        <div class="landing-section-head centered">
          <span class="landing-section-label">Access Control</span>
          <h2>Enterprise-grade role permissions</h2>
          <p>Separate administrative control from day-to-day operations with clearly defined access levels.</p>
        </div>
        <div class="landing-access-panel">
          <table class="landing-access-table">
            <thead>
              <tr>
                <th>Capability</th>
                <th>Administrator</th>
                <th>Staff</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($accessMatrix as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['capability']) ?></td>
                  <td><?= $row['admin'] ? '<i class="bi bi-check-lg access-yes"></i>' : '<i class="bi bi-dash-lg access-no"></i>' ?></td>
                  <td><?= $row['staff'] ? '<i class="bi bi-check-lg access-yes"></i>' : '<i class="bi bi-dash-lg access-no"></i>' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="landing-section">
      <div class="landing-container">
        <div class="landing-cta">
          <div class="landing-cta-copy">
            <span class="landing-section-label">Get Started</span>
            <h2>Sign in to your inventory workspace</h2>
            <p>Authorized users can access the dashboard with administrator or staff credentials.</p>
          </div>
          <div class="landing-cta-actions">
            <a href="<?= htmlspecialchars(app_url('signup.php')) ?>" class="btn btn-accent btn-lg">Create Account</a>
            <a href="<?= htmlspecialchars(app_url('login.php')) ?>" class="btn btn-outline-accent btn-lg">Sign in</a>
            <span class="landing-cta-note">Email/password or Google sign-in supported</span>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer class="landing-footer">
    <div class="landing-container">
      <div class="landing-footer-grid">
        <div class="landing-footer-brand">
          <a href="<?= htmlspecialchars(app_url('/')) ?>" class="landing-logo">
            <span class="brand-mark round">IMS</span>
            <span class="landing-logo-text">
              <strong>Inventory Management</strong>
              <small>Enterprise Platform</small>
            </span>
          </a>
          <p>Inventory control, audit workflows, and reporting for retail and warehouse teams.</p>
        </div>
        <div>
          <h4>Platform</h4>
          <a href="#platform">Capabilities</a>
          <a href="#workflow">Workflow</a>
          <a href="#access">Access Control</a>
        </div>
        <div>
          <h4>Account</h4>
          <a href="<?= htmlspecialchars(app_url('signup.php')) ?>">Create account</a>
          <a href="<?= htmlspecialchars(app_url('login.php')) ?>">Sign in</a>
        </div>
      </div>
      <div class="landing-footer-bottom">
        <span>&copy; <?= date('Y') ?> Inventory Management System. All rights reserved.</span>
      </div>
    </div>
  </footer>
</body>
</html>
