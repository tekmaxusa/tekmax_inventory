<?php
/**
 * Create simple SVG placeholder images for seed products and update DB.
 * Run once: C:\xampp\php\php.exe tools\seed_product_images.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/uploads.php';

$dir = product_uploads_dir();
$pdo = db();
$products = $pdo->query('SELECT product_id, sku, product_name FROM products WHERE is_active = 1')->fetchAll();

$colors = ['#4F67FF', '#0D9488', '#DB2777', '#D97706', '#7C3AED', '#2563EB', '#059669', '#DC2626'];

foreach ($products as $i => $p) {
    $initial = strtoupper(substr((string) $p['product_name'], 0, 1));
    $color = $colors[$i % count($colors)];
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $p['sku']);
    $filename = 'seed_' . $safeName . '.svg';
    $path = $dir . DIRECTORY_SEPARATOR . $filename;

    $label = htmlspecialchars((string) $p['product_name'], ENT_XML1);
    $sku = htmlspecialchars((string) $p['sku'], ENT_XML1);
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
  <rect width="400" height="400" fill="{$color}"/>
  <circle cx="200" cy="150" r="70" fill="rgba(255,255,255,0.18)"/>
  <text x="200" y="175" text-anchor="middle" font-family="Arial,sans-serif" font-size="72" font-weight="700" fill="#ffffff">{$initial}</text>
  <text x="200" y="270" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" font-weight="600" fill="#ffffff">{$sku}</text>
  <text x="200" y="310" text-anchor="middle" font-family="Arial,sans-serif" font-size="14" fill="rgba(255,255,255,0.85)">{$label}</text>
</svg>
SVG;
    file_put_contents($path, $svg);

    $rel = product_image_public_path($filename);
    $upd = $pdo->prepare('UPDATE products SET image_url = ? WHERE product_id = ?');
    $upd->execute([$rel, (int) $p['product_id']]);
    echo "OK {$p['sku']} -> {$rel}\n";
}

echo "Done. Updated " . count($products) . " products.\n";
