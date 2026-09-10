<?php
/**
 * Product image upload helpers.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function product_uploads_dir(): string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Public URL path for a stored product image (relative web path).
 * Stored in DB as: uploads/products/filename.ext
 */
function product_image_public_path(string $filename): string
{
    return 'uploads/products/' . ltrim($filename, '/');
}

/**
 * Absolute URL path for browser (with APP_BASE).
 */
function product_image_url(?string $stored): ?string
{
    if ($stored === null || trim($stored) === '') {
        return null;
    }
    $stored = trim($stored);
    // Already absolute http(s) or data URI
    if (preg_match('#^(https?:)?//#i', $stored) || str_starts_with($stored, 'data:')) {
        return $stored;
    }
    // Absolute site path
    if (str_starts_with($stored, '/')) {
        return $stored;
    }
    return app_url($stored);
}

/**
 * Normalize product row(s) so image_url is a browser-ready URL.
 */
function with_product_image_url(array $row): array
{
    $row['image_url'] = product_image_url($row['image_url'] ?? null);
    return $row;
}

/**
 * Validate + save uploaded product image. Returns relative path for DB.
 *
 * @return array{ok:true,path:string}|array{ok:false,error:string}
 */
function save_product_image(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Product image is required.'];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Image upload failed. Try again.'];
    }

    $maxBytes = 3 * 1024 * 1024; // 3 MB
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxBytes) {
        return ['ok' => false, 'error' => 'Image must be between 1 byte and 3 MB.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid uploaded file.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Image must be JPG, PNG, WEBP, or GIF.'];
    }

    $filename = 'p_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = product_uploads_dir() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['ok' => false, 'error' => 'Could not save uploaded image.'];
    }

    return ['ok' => true, 'path' => product_image_public_path($filename)];
}

function delete_product_image_file(?string $stored): void
{
    if ($stored === null || $stored === '') {
        return;
    }
    if (preg_match('#^(https?:)?//#i', $stored) || str_starts_with($stored, 'data:')) {
        return;
    }
    $rel = preg_replace('#^/+#', '', str_replace('\\', '/', $stored));
    if (!str_starts_with($rel, 'uploads/products/')) {
        return;
    }
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($full)) {
        @unlink($full);
    }
}
