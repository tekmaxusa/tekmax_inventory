<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . post_login_redirect_url());
    exit;
}

$capabilities = [
    [
        'title' => 'Live stock visibility',
        'desc' => 'Watch product levels, valuations, and low-stock signals from one dashboard.',
    ],
    [
        'title' => 'Catalog & barcodes',
        'desc' => 'Keep SKUs, barcodes, RFID tags, pricing, and product images in sync.',
    ],
    [
        'title' => 'Stock in and out',
        'desc' => 'Log every receipt, sale, and adjustment with reasons and full history.',
    ],
    [
        'title' => 'Physical counts',
        'desc' => 'Run audits with manual entry, barcode scan, camera, or bulk CSV import.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="TEKMAX Inventory — stock control, audits, and reporting for retail and warehouse teams.">
  <title>TEKMAX Inventory</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="<?= htmlspecialchars(app_url('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body class="lp">
  <header class="lp-nav">
    <div class="lp-wrap lp-nav-inner">
      <a class="lp-brand" href="<?= htmlspecialchars(app_url('/')) ?>">TEKMAX</a>
      <div class="lp-nav-actions">
        <a class="lp-link" href="<?= htmlspecialchars(app_url('login.php')) ?>">Sign in</a>
        <a class="lp-btn lp-btn-solid" href="<?= htmlspecialchars(app_url('signup.php')) ?>">Get started</a>
      </div>
    </div>
  </header>

  <main>
    <section class="lp-hero">
      <div class="lp-hero-media" aria-hidden="true">
        <img
          src="https://images.unsplash.com/photo-1553413077-190dd305871c?auto=format&fit=crop&w=2000&q=80"
          alt=""
          width="2000"
          height="1333"
        >
      </div>
      <div class="lp-hero-veil" aria-hidden="true"></div>
      <div class="lp-wrap lp-hero-copy">
        <p class="lp-brand-mark">TEKMAX</p>
        <h1>Inventory that stays accurate on the floor</h1>
        <p class="lp-lead">Track stock, run physical counts, and export clear reports for every store.</p>
        <div class="lp-hero-cta">
          <a class="lp-btn lp-btn-solid lp-btn-lg" href="<?= htmlspecialchars(app_url('signup.php')) ?>">Create account</a>
          <a class="lp-btn lp-btn-ghost lp-btn-lg" href="<?= htmlspecialchars(app_url('login.php')) ?>">Sign in</a>
        </div>
      </div>
    </section>

    <section class="lp-section" id="capabilities">
      <div class="lp-wrap">
        <div class="lp-section-intro">
          <h2>Built for daily warehouse rhythm</h2>
          <p>One workspace for catalog, movements, audits, and reporting — without the clutter.</p>
        </div>
        <ul class="lp-capability-list">
          <?php foreach ($capabilities as $i => $item): ?>
            <li class="lp-capability" style="--i: <?= (int) $i ?>">
              <span class="lp-capability-index"><?= str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
              <div>
                <h3><?= htmlspecialchars($item['title']) ?></h3>
                <p><?= htmlspecialchars($item['desc']) ?></p>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <section class="lp-section lp-section-ink" id="workflow">
      <div class="lp-wrap">
        <div class="lp-section-intro lp-section-intro-light">
          <h2>From shelf to spreadsheet</h2>
          <p>A simple loop your team can repeat every day.</p>
        </div>
        <ol class="lp-steps">
          <li>
            <strong>Catalog</strong>
            <span>Add products, categories, barcodes, and reorder levels.</span>
          </li>
          <li>
            <strong>Move</strong>
            <span>Record stock in and out with reason codes and references.</span>
          </li>
          <li>
            <strong>Reconcile</strong>
            <span>Count physically, close the audit, export the report.</span>
          </li>
        </ol>
      </div>
    </section>

    <section class="lp-section lp-cta-band">
      <div class="lp-wrap lp-cta-inner">
        <h2>Start your TEKMAX workspace</h2>
        <p>Set up a store, invite your team, and keep inventory honest.</p>
        <a class="lp-btn lp-btn-solid lp-btn-lg" href="<?= htmlspecialchars(app_url('signup.php')) ?>">Create account</a>
      </div>
    </section>
  </main>

  <footer class="lp-footer">
    <div class="lp-wrap lp-footer-inner">
      <span class="lp-brand">TEKMAX</span>
      <span>&copy; <?= date('Y') ?> TEKMAX Inventory</span>
    </div>
  </footer>
</body>
</html>
