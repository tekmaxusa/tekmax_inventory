-- =============================================================================
-- Inventory Management System — Full Schema + Seed Data
-- Target: MariaDB 10.4+ / MySQL 5.7+ (verified against XAMPP MariaDB 10.4.32)
-- Import via phpMyAdmin (Import tab) or CLI:
--   C:\xampp\mysql\bin\mysql.exe -u root < database\inventory_schema.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS inventory_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE inventory_system;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS audit_counts;
DROP TABLE IF EXISTS audit_sessions;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS store_users;
DROP TABLE IF EXISTS stores;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- users (global login accounts)
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  user_id         INT AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(100) NOT NULL,
  email           VARCHAR(150) UNIQUE NOT NULL,
  password_hash   VARCHAR(255) NULL,
  google_id       VARCHAR(255) UNIQUE NULL,
  role            VARCHAR(20) DEFAULT 'staff', -- admin | staff
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- stores (tenant workspaces — each signup creates a new store)
-- -----------------------------------------------------------------------------
CREATE TABLE stores (
  store_id        INT AUTO_INCREMENT PRIMARY KEY,
  store_name      VARCHAR(255) NOT NULL,
  business_type   VARCHAR(100) NULL,
  address         VARCHAR(500) NULL,
  phone           VARCHAR(50) NULL,
  contact_email   VARCHAR(150) NULL,
  slug            VARCHAR(100) NOT NULL UNIQUE,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- store_users (which users belong to which store)
-- -----------------------------------------------------------------------------
CREATE TABLE store_users (
  store_id        INT NOT NULL,
  user_id         INT NOT NULL,
  role            VARCHAR(20) NOT NULL DEFAULT 'staff',
  PRIMARY KEY (store_id, user_id),
  FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- categories (scoped per store)
-- -----------------------------------------------------------------------------
CREATE TABLE categories (
  category_id     INT AUTO_INCREMENT PRIMARY KEY,
  store_id        INT NOT NULL,
  category_name   VARCHAR(100) NOT NULL,
  UNIQUE KEY categories_store_name_unique (store_id, category_name),
  FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- products
-- -----------------------------------------------------------------------------
CREATE TABLE products (
  product_id      INT AUTO_INCREMENT PRIMARY KEY,
  store_id        INT NOT NULL,
  sku             VARCHAR(100) NULL,
  barcode         VARCHAR(100) NULL,
  rfid_tag        VARCHAR(128) NULL,
  product_name    VARCHAR(255) NOT NULL,
  category_id     INT,
  location_tag    VARCHAR(20),
  system_qty      INT NOT NULL DEFAULT 0,
  reorder_level   INT DEFAULT 5,
  unit_cost       DECIMAL(10,2) NOT NULL DEFAULT 0,
  unit_price      DECIMAL(10,2) NOT NULL DEFAULT 0,
  image_url       TEXT,
  is_active       TINYINT(1) DEFAULT 1,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY products_store_sku_unique (store_id, sku),
  UNIQUE KEY products_store_barcode_unique (store_id, barcode),
  UNIQUE KEY products_store_rfid_unique (store_id, rfid_tag),
  FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- product_barcodes (multiple barcodes / UPCs per item)
-- -----------------------------------------------------------------------------
CREATE TABLE product_barcodes (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- stock_movements
-- -----------------------------------------------------------------------------
CREATE TABLE stock_movements (
  movement_id     INT AUTO_INCREMENT PRIMARY KEY,
  store_id        INT NOT NULL,
  product_id      INT NOT NULL,
  movement_type   ENUM('in','out') NOT NULL,
  quantity        INT NOT NULL,
  reason          VARCHAR(100),
  reference_no    VARCHAR(100),
  performed_by    INT,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(product_id),
  FOREIGN KEY (performed_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_sessions
-- -----------------------------------------------------------------------------
CREATE TABLE audit_sessions (
  session_id      INT AUTO_INCREMENT PRIMARY KEY,
  store_id        INT NOT NULL,
  store_name      VARCHAR(255) NOT NULL,
  audit_date      DATE NOT NULL,
  status          VARCHAR(20) DEFAULT 'in_progress',
  created_by      INT,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  saved_at        TIMESTAMP NULL,
  FOREIGN KEY (store_id) REFERENCES stores(store_id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- audit_counts
-- -----------------------------------------------------------------------------
CREATE TABLE audit_counts (
  count_id        INT AUTO_INCREMENT PRIMARY KEY,
  session_id      INT NOT NULL,
  product_id      INT NOT NULL,
  physical_qty    INT NULL,
  count_method    VARCHAR(20),
  counted_at      TIMESTAMP NULL,
  UNIQUE KEY unique_session_product (session_id, product_id),
  FOREIGN KEY (session_id) REFERENCES audit_sessions(session_id),
  FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED DATA (demo store)
-- =============================================================================

INSERT INTO stores (store_name, business_type, slug, is_active) VALUES
('Main Store', 'Retail', 'main-store', 1);

INSERT INTO users (name, email, password_hash, role, is_active) VALUES
('Admin User', 'admin@example.com', '$2y$10$cYatk2rBp/JijRL/vBxeQu8GT0MCA0m6WzAxj1KbECLeiO2O4eiBC', 'admin', 1),
('Staff User', 'staff@example.com', '$2y$10$jde6PpRdhXIHxmDZNNACO.XlGeXWlqVB6TW/bnXepRn.mNTFSyPEO', 'staff', 1);

INSERT INTO store_users (store_id, user_id, role) VALUES
(1, 1, 'admin'),
(1, 2, 'staff');

INSERT INTO categories (store_id, category_name) VALUES
(1, 'Wigs'),
(1, 'Hair Oil'),
(1, 'Cosmetics'),
(1, 'Hair Extensions'),
(1, 'Hair Gel'),
(1, 'Accessories'),
(1, 'Hair Spray');

INSERT INTO products
  (store_id, sku, barcode, product_name, category_id, location_tag, system_qty, unit_cost, unit_price)
VALUES
  (1, 'C9-1B',      '803868451092', 'Sensationnel Cloud 9 Wig',      1, 'A-01', 10, 24.99, 39.99),
  (1, 'MMO-4OZ',    '850001265652', 'Mielle Rosemary Mint Oil',      2, 'B-12', 15,  8.50, 14.99),
  (1, 'RK-LG-02',   '649674052143', 'Ruby Kisses Lip Gloss',         3, 'C-08', 12,  4.25,  8.99),
  (1, 'XP-BR-48',   '802535428485', 'X-Pression Braiding Hair 48"',  4, 'A-14', 20,  6.75, 12.99),
  (1, 'ECO-OL-32',  '748378001020', 'Eco Style Olive Oil Gel 32oz',  5, 'B-03', 18,  9.99, 16.99),
  (1, 'MC-SB-BLK',  '636227127919', 'Magic Collection Satin Bonnet', 6, 'D-06', 25,  3.50,  7.99),
  (1, 'G2B-SP-12',  '052336916350', 'Got2b Glued Spray 12oz',        7, 'B-07', 14,  7.20, 12.99),
  (1, 'KL-COUT-01', '731509667849', 'Kiss Lash Couture',             3, 'C-11',  9,  5.99, 11.99);

INSERT INTO product_barcodes (store_id, product_id, barcode, is_primary)
SELECT store_id, product_id, barcode, 1 FROM products WHERE barcode IS NOT NULL AND barcode <> '';

-- -----------------------------------------------------------------------------
-- password_reset_otps
-- -----------------------------------------------------------------------------
CREATE TABLE password_reset_otps (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rate_limit_hits (shared across app instances)
-- -----------------------------------------------------------------------------
CREATE TABLE rate_limit_hits (
  hit_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_hash CHAR(64) NOT NULL,
  hit_at      INT UNSIGNED NOT NULL,
  INDEX idx_rate_bucket_time (bucket_hash, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform super admin: super@example.com / superadmin123
INSERT INTO users (name, email, password_hash, role, is_active) VALUES
('Platform Super Admin', 'super@example.com', '$2y$10$NxaDtv.TtgPHycGOCAHn9.JiwEBkvcjKrMHAmShEf3.MFjDjvsZuq', 'super_admin', 1);
