<?php
/**
 * Lightweight additive migrations so existing DBs pick up new columns
 * without re-importing (which would wipe data).
 */
declare(strict_types=1);

require_once __DIR__ . '/store.php';

function schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    if (!schema_column_exists($pdo, 'users', 'is_active')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
    }

    if (!schema_column_exists($pdo, 'users', 'google_id')) {
        $pdo->exec('ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL UNIQUE AFTER password_hash');
    }

    $nullableHash = $pdo->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash'"
    )->fetchColumn();
    if ($nullableHash === 'NO') {
        $pdo->exec('ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL');
    }

    if (!schema_column_exists($pdo, 'products', 'rfid_tag')) {
        $pdo->exec(
            'ALTER TABLE products
             ADD COLUMN rfid_tag VARCHAR(128) NULL AFTER barcode,
             ADD UNIQUE KEY products_rfid_tag_unique (rfid_tag)'
        );
    }

    // --- Multi-store (tenant) tables ---
    if (!schema_table_exists($pdo, 'stores')) {
        $pdo->exec(
            "CREATE TABLE stores (
              store_id        INT AUTO_INCREMENT PRIMARY KEY,
              store_name      VARCHAR(255) NOT NULL,
              business_type   VARCHAR(100) NULL,
              address         VARCHAR(500) NULL,
              phone           VARCHAR(50) NULL,
              contact_email   VARCHAR(150) NULL,
              slug            VARCHAR(100) NOT NULL UNIQUE,
              is_active       TINYINT(1) NOT NULL DEFAULT 1,
              created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!schema_table_exists($pdo, 'store_users')) {
        $pdo->exec(
            "CREATE TABLE store_users (
              store_id        INT NOT NULL,
              user_id         INT NOT NULL,
              role            VARCHAR(20) NOT NULL DEFAULT 'staff',
              PRIMARY KEY (store_id, user_id),
              FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    $storeIdColumns = [
        'categories' => 'category_id',
        'products' => 'product_id',
        'stock_movements' => 'movement_id',
        'audit_sessions' => 'session_id',
    ];
    foreach ($storeIdColumns as $table => $idColumn) {
        if (!schema_column_exists($pdo, $table, 'store_id')) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN store_id INT NULL AFTER {$idColumn}");
        }
    }

    $storeCount = (int) $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
    if ($storeCount === 0) {
        $pdo->exec(
            "INSERT INTO stores (store_name, business_type, slug, is_active)
             VALUES ('Main Store', 'Retail', 'main-store', 1)"
        );
    }

    $defaultStoreId = (int) $pdo->query('SELECT store_id FROM stores ORDER BY store_id ASC LIMIT 1')->fetchColumn();
    if ($defaultStoreId > 0) {
        $pdo->exec("UPDATE categories SET store_id = {$defaultStoreId} WHERE store_id IS NULL OR store_id = 0");
        $pdo->exec("UPDATE products SET store_id = {$defaultStoreId} WHERE store_id IS NULL OR store_id = 0");
        $pdo->exec("UPDATE stock_movements SET store_id = {$defaultStoreId} WHERE store_id IS NULL OR store_id = 0");
        $pdo->exec("UPDATE audit_sessions SET store_id = {$defaultStoreId} WHERE store_id IS NULL OR store_id = 0");

        $orphans = $pdo->query(
            "SELECT u.user_id, u.role
             FROM users u
             LEFT JOIN store_users su ON su.user_id = u.user_id
             WHERE su.user_id IS NULL AND LOWER(u.role) <> 'super_admin'"
        )->fetchAll();
        $link = $pdo->prepare(
            'INSERT INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)'
        );
        foreach ($orphans as $u) {
            $role = strtolower((string) ($u['role'] ?? 'staff')) === 'admin' ? 'admin' : 'staff';
            $link->execute([$defaultStoreId, (int) $u['user_id'], $role]);
        }

        // Remove mistaken Main Store links for users who own a separate store (legacy migration bug)
        $pdo->prepare(
            'DELETE su_main FROM store_users su_main
             INNER JOIN store_users su_other ON su_other.user_id = su_main.user_id
               AND su_other.store_id > su_main.store_id
             WHERE su_main.store_id = ?'
        )->execute([$defaultStoreId]);
    }

    // Per-store unique constraints
    if (schema_column_exists($pdo, 'categories', 'store_id') && !schema_index_exists($pdo, 'categories', 'categories_store_name_unique')) {
        if (schema_index_exists($pdo, 'categories', 'category_name')) {
            $pdo->exec('ALTER TABLE categories DROP INDEX category_name');
        }
        $pdo->exec('ALTER TABLE categories ADD UNIQUE KEY categories_store_name_unique (store_id, category_name)');
    }

    if (schema_column_exists($pdo, 'products', 'store_id')) {
        foreach (['sku', 'barcode'] as $col) {
            $idx = $col;
            $newIdx = "products_store_{$col}_unique";
            if (!schema_index_exists($pdo, 'products', $newIdx) && schema_index_exists($pdo, 'products', $idx)) {
                $pdo->exec("ALTER TABLE products DROP INDEX {$idx}");
            }
        }
        if (!schema_index_exists($pdo, 'products', 'products_store_sku_unique')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY products_store_sku_unique (store_id, sku)');
        }
        if (!schema_index_exists($pdo, 'products', 'products_store_barcode_unique')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY products_store_barcode_unique (store_id, barcode)');
        }
        if (schema_index_exists($pdo, 'products', 'products_rfid_tag_unique')) {
            $pdo->exec('ALTER TABLE products DROP INDEX products_rfid_tag_unique');
        }
        if (!schema_index_exists($pdo, 'products', 'products_store_rfid_unique')) {
            $pdo->exec('ALTER TABLE products ADD UNIQUE KEY products_store_rfid_unique (store_id, rfid_tag)');
        }
    }

    // Demo accounts — local / smoke tests only. Never auto-seed in production.
    if (!app_is_production()) {
        $staff = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $staff->execute(['staff@example.com']);
        if (!$staff->fetch()) {
            $ins = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $ins->execute([
                'Staff User',
                'staff@example.com',
                '$2y$10$jde6PpRdhXIHxmDZNNACO.XlGeXWlqVB6TW/bnXepRn.mNTFSyPEO',
                'staff',
            ]);
            if ($defaultStoreId > 0) {
                $userId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT IGNORE INTO store_users (store_id, user_id, role) VALUES (?, ?, ?)')
                    ->execute([$defaultStoreId, $userId, 'staff']);
            }
        }
    }

    if (!schema_table_exists($pdo, 'password_reset_otps')) {
        $pdo->exec(
            "CREATE TABLE password_reset_otps (
              reset_id      INT AUTO_INCREMENT PRIMARY KEY,
              user_id       INT NOT NULL,
              email         VARCHAR(150) NOT NULL,
              otp_hash      VARCHAR(255) NOT NULL,
              expires_at    DATETIME NOT NULL,
              used_at       DATETIME NULL,
              created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
              INDEX idx_reset_email (email),
              INDEX idx_reset_user_active (user_id, used_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    if (!app_is_production()) {
        $super = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
        $super->execute(['super@example.com']);
        if (!$super->fetch()) {
            $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, role, is_active)
                 VALUES (?, ?, ?, ?, 1)'
            )->execute([
                'Platform Super Admin',
                'super@example.com',
                '$2y$10$NxaDtv.TtgPHycGOCAHn9.JiwEBkvcjKrMHAmShEf3.MFjDjvsZuq',
                'super_admin',
            ]);
        }
    }

    // --- Multiple barcodes per product + optional SKU/barcode ---
    if (!schema_table_exists($pdo, 'product_barcodes')) {
        $pdo->exec(
            "CREATE TABLE product_barcodes (
              id            INT AUTO_INCREMENT PRIMARY KEY,
              store_id      INT NOT NULL,
              product_id    INT NOT NULL,
              barcode       VARCHAR(100) NOT NULL,
              is_primary    TINYINT(1) NOT NULL DEFAULT 0,
              created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY product_barcodes_store_barcode_unique (store_id, barcode),
              KEY product_barcodes_product_idx (product_id),
              FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
              FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    // Allow SKU-only or barcode-only products (empty → NULL for unique safety)
    if (schema_column_exists($pdo, 'products', 'sku')) {
        $skuNull = $pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sku'"
        )->fetchColumn();
        if ($skuNull === 'NO') {
            $pdo->exec('ALTER TABLE products MODIFY sku VARCHAR(100) NULL');
        }
    }
    if (schema_column_exists($pdo, 'products', 'barcode')) {
        $bcNull = $pdo->query(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'barcode'"
        )->fetchColumn();
        if ($bcNull === 'NO') {
            $pdo->exec('ALTER TABLE products MODIFY barcode VARCHAR(100) NULL');
        }
    }

    // Backfill primary barcodes into product_barcodes
    if (schema_table_exists($pdo, 'product_barcodes')) {
        $pdo->exec(
            "INSERT IGNORE INTO product_barcodes (store_id, product_id, barcode, is_primary)
             SELECT p.store_id, p.product_id, p.barcode, 1
             FROM products p
             WHERE p.barcode IS NOT NULL AND TRIM(p.barcode) <> ''
               AND NOT EXISTS (
                 SELECT 1 FROM product_barcodes pb WHERE pb.product_id = p.product_id AND pb.barcode = p.barcode
               )"
        );
        // Normalize blank strings to NULL so unique indexes behave correctly
        $pdo->exec("UPDATE products SET sku = NULL WHERE sku IS NOT NULL AND TRIM(sku) = ''");
        $pdo->exec("UPDATE products SET barcode = NULL WHERE barcode IS NOT NULL AND TRIM(barcode) = ''");
    }

    $done = true;
}
