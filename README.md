# Inventory Management System

XAMPP-based inventory app (PHP + MySQL/MariaDB) with Physical Inventory Audit.

## Stack

| Component | Version |
|-----------|---------|
| PHP       | **8.2+** (XAMPP `C:\xampp\php`) |
| MariaDB   | **10.4+** (XAMPP MySQL) |
| Apache    | XAMPP bundled |
| Frontend  | Bootstrap 5 CDN + custom CSS + vanilla JS |

## Features

- Login / logout (session auth) with CSRF + login rate limiting
- Google OAuth (optional), forgot/reset password OTP
- Multi-store tenancy + platform super-admin console
- Users (admin): create/edit/disable accounts, admin vs staff roles
- Dashboard KPIs, recent movements, low stock
- Products CRUD (soft-delete) + image upload + bulk import
- Categories CRUD + import
- Stock In / Out with history (paused while an audit is open)
- Physical inventory count (manual / barcode / camera / CSV bulk) → close reconciles stock
- Reports: valuation, movements, low stock + CSV export

## Run locally (XAMPP)

1. Start **Apache** + **MySQL** in XAMPP Control Panel
2. Sync project into htdocs (first time or after code changes):

```bat
robocopy "D:\TEKMAXLLC - PROJECTS\inventory-system" "C:\xampp\htdocs\inventory-system" /E
```

3. Import DB (first time only):

```bat
C:\xampp\mysql\bin\mysql.exe -u root < "D:\TEKMAXLLC - PROJECTS\inventory-system\database\inventory_schema.sql"
```

4. Open: http://localhost/inventory-system/login.php

**Default login (local only):** `admin@example.com` / `admin123`  
**Staff login:** `staff@example.com` / `staff123`  
**Platform:** `super@example.com` / `superadmin123`

## Config

| File / env | Purpose |
|------------|---------|
| `config/db.php` or `DB_*` env | Database host/user/password |
| `config/app.php` or `APP_*` env | `APP_BASE`, `APP_URL`, `APP_ENV` |
| `config/google.php` or `GOOGLE_*` env | OAuth (empty = disabled) |
| `config/mail.php` (copy from `mail.example.php`) | SMTP for password-reset OTP |

## Deploy with Docker (recommended for live / VPS)

Works on **Docker Desktop (Windows)** or any **Linux VPS** (DigitalOcean, Hostinger VPS, etc.).

### 1. One-time setup

```bat
cd "D:\TEKMAXLLC - PROJECTS\inventory-system"
copy .env.example .env
```

Edit `.env`: set strong `DB_PASS` + `DB_ROOT_PASSWORD`, and `APP_URL` (e.g. `http://localhost:8088` or `https://inventory.yourdomain.com`).

### 2. Start

**Windows:**

```bat
deploy.bat
```

**Linux VPS:**

```bash
chmod +x deploy.sh
./deploy.sh
```

Or manually: `docker compose up -d --build`

Open `http://localhost:8088/login.php` (or your `APP_URL`).  
Demo login: `admin@example.com` / `admin123` — **change immediately**.

> Default port is **8088** (avoids clash with other local apps on 8080).

### 3. Put HTTPS in front (production)

Point your domain to the VPS, then reverse-proxy port `8088` (or set `APP_PORT=80`) with Caddy/Nginx/Cloudflare and set:

```text
APP_URL=https://inventory.yourdomain.com
MAIL_DRIVER=smtp
SMTP_USER=...
SMTP_PASS=...
```

### 4. Useful commands

```bat
docker compose logs -f app
docker compose ps
docker compose down
docker compose up -d --build
```

Data persists in Docker volumes (`inventory_db_data`, uploads, logs).

## Deploy without Docker (classic PHP host)

1. **Host:** VPS or shared hosting with **PHP 8.2+**, **MySQL/MariaDB**, **Apache** (or Nginx + PHP-FPM), and **HTTPS**.
2. **Upload** the app (document root = folder with `index.php`).
3. **Create DB** + a **non-root** MySQL user.
4. **Set environment** (or Apache `SetEnv`):

```text
APP_ENV=production
APP_BASE=
APP_URL=https://your-domain.com
DB_HOST=127.0.0.1
DB_NAME=inventory_system
DB_USER=inventory_app
DB_PASS=strong-password
```

5. **Import schema** once: `database/inventory_schema.sql`, then **change** demo account passwords.
6. **Mail / Google:** same as Docker `.env` notes above.
7. Confirm `.htaccess` is active so `storage/` and `tools/` stay blocked.

## Smoke tests (local)

```bat
C:\xampp\php\php.exe "D:\TEKMAXLLC - PROJECTS\inventory-system\_smoke_test.php"
C:\xampp\php\php.exe "D:\TEKMAXLLC - PROJECTS\inventory-system\_e2e_test.php"
```
