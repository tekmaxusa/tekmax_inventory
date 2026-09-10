# Inventory Management System — Full Explanation Guide

Kompletong paliwanag kung paano gumagana ang buong system: setup, modules, database, APIs, audit/scan, at important rules.

---

## 1. Ano ito?

Isang **Inventory Management System (IMS)** para sa store/warehouse:

- Mag-track ng products (SKU, barcode, qty, cost, price, location)
- Mag-record ng **Stock In / Out**
- Mag-run ng **Physical Inventory Count (Audit)** — manual, barcode scan, o CSV/RFID import
- Mag-view ng **Dashboard** at **Reports** (valuation, movements, low stock + CSV export)

**Tech stack**

| Layer | Technology |
|--------|------------|
| Backend | Native PHP 8.2+ (walang Laravel/Composer) |
| Database | MySQL / MariaDB (XAMPP) |
| Frontend | Bootstrap 5 + custom CSS + vanilla JS |
| Server | Apache (XAMPP) |
| Camera scan | `html5-qrcode` (CDN) |

**Project location**

```
D:\TEKMAXLLC - PROJECTS\inventory-system\
```

**Live local URL (pag naka-copy sa htdocs)**

```
http://localhost/inventory-system/login.php
```

**Default login**

| Field | Value |
|--------|--------|
| Email | `admin@example.com` |
| Password | `admin123` |

---

## 2. Paano i-run locally (XAMPP)

### Requirements
1. XAMPP (Apache + MySQL)
2. Project folder sa `C:\xampp\htdocs\inventory-system\`
3. Database `inventory_system` na na-import

### Steps

1. Buksan **XAMPP Control Panel** → Start **Apache** at **MySQL**
2. Sync code papunta sa htdocs (kung nage-edit ka sa Projects folder):

```bat
robocopy "D:\TEKMAXLLC - PROJECTS\inventory-system" "C:\xampp\htdocs\inventory-system" /E
```

3. Import database (first time / reset):

```bat
C:\xampp\mysql\bin\mysql.exe -u root < "D:\TEKMAXLLC - PROJECTS\inventory-system\database\inventory_schema.sql"
```

O via phpMyAdmin → Import → `database/inventory_schema.sql`

4. Buksan: http://localhost/inventory-system/login.php

### Config files

| File | Purpose |
|------|---------|
| `config/db.php` | DB host, port, name, user, password (default XAMPP root = empty password) |
| `config/app.php` | Base URL path: `APP_BASE = /inventory-system` |

Kung ibang folder name ang gagamitin mo sa htdocs, baguhin ang `APP_BASE` sa `config/app.php`.

---

## 3. Folder structure (ano ang role ng bawat parte)

```
inventory-system/
├── index.php              → Redirect: logged in → dashboard, else → login
├── login.php              → Sign-in form (session)
├── dashboard.php          → KPIs + recent movements + low stock
├── products.php           → Item List (CRUD UI)
├── categories.php         → Categories CRUD UI
├── stock_in_out.php       → Stock In/Out form + history
├── audit.php              → Physical inventory count
├── reports.php            → Valuation / movements / low stock + CSV
│
├── config/
│   ├── app.php            → Base path helper (app_url)
│   └── db.php             → PDO connection (singleton)
│
├── includes/
│   ├── auth.php           → Session, require_login, active_audit_session, JSON helpers
│   ├── header.php         → Topbar + layout start
│   ├── sidebar.php        → Navigation
│   └── footer.php         → Scripts + layout end
│
├── api/                   → JSON APIs (fetch from JS)
│   ├── auth.php
│   ├── products.php
│   ├── categories.php
│   ├── stock_movements.php
│   ├── audit_sessions.php
│   ├── audit_counts.php
│   ├── audit_bulk_import.php
│   ├── audit_export.php
│   └── dashboard_summary.php   (available; dashboard page uses PHP directly)
│
├── assets/
│   ├── css/app.css
│   └── js/
│       ├── stock.js       → Stock In/Out page logic
│       └── audit.js       → Audit + barcode/camera/RFID logic
│
└── database/
    └── inventory_schema.sql
```

---

## 4. Authentication (Login / Logout)

### Paano gumagana
1. User mag-enter ng email + password sa `login.php`
2. System titingnan ang `users` table, i-verify via `password_verify()` (bcrypt hash)
3. Kapag OK → `login_user()`:
   - `session_regenerate_id(true)` (security)
   - Save `user_id`, `user_name`, `user_role` sa `$_SESSION`
4. Redirect sa Dashboard
5. Lahat ng protected pages/APIs tumatawag ng `require_login()`

### Logout
Sidebar → **Sign out** → `api/auth.php?action=logout` → clear session → balik sa login

### Important rules
- Walang public registration page — user ay nasa database (seeded admin)
- May `role` field (`admin` / `staff`) pero **hindi pa naka-enforce** ang role permissions (parehong access)
- API calls kung walang session → **JSON 401** (`Authentication required`)
- HTML pages kung walang session → redirect sa login

---

## 5. Database — buong modelo

Database name: **`inventory_system`**

### 5.1 `users`
Accounts para mag-login.

| Column | Meaning |
|--------|---------|
| user_id | Primary key |
| name | Display name |
| email | Unique login |
| password_hash | bcrypt |
| role | `admin` or `staff` |
| created_at | Timestamp |

### 5.2 `categories`
Product grouping (Wigs, Hair Oil, Cosmetics, etc.)

### 5.3 `products`
Master item list. **`system_qty` = live stock on hand.**

| Column | Meaning |
|--------|---------|
| sku | Unique stock-keeping unit |
| barcode | Unique barcode (gamit sa scan) |
| product_name | Display name |
| category_id | FK → categories (nullable) |
| location_tag | Shelf/bin (e.g. `A-01`) |
| system_qty | Current quantity |
| reorder_level | Low-stock threshold (default 5) |
| unit_cost | Cost for valuation / variance value |
| unit_price | Selling price |
| image_url | **Required** product image path (uploaded file under `uploads/products/`) |
| is_active | `1` = active, `0` = soft-deleted |

**Soft delete:** hindi tinatanggal ang row; `is_active = 0` lang. Hindi na lalabas sa lists/new audits.

### 5.4 `stock_movements`
History ng lahat ng stock changes.

| Column | Meaning |
|--------|---------|
| movement_type | `in` or `out` |
| quantity | Positive amount moved |
| reason | Purchase, Sale, Damaged, Opening stock, Audit Adjustment, etc. |
| reference_no | PO / invoice / `AUDIT-123` |
| performed_by | FK → users |

**Rule:** Kapag may Stock In/Out, sabay na ina-update ang `products.system_qty` sa loob ng DB transaction.

### 5.5 `audit_sessions`
Isang physical count “event”.

| status | Meaning |
|--------|---------|
| `in_progress` | Active counting |
| `saved` | Naka-pause / resume later (stock still locked) |
| `closed` | Tapos na; stock na-reconcile |

### 5.6 `audit_counts`
Isang row per product per session.

| Column | Meaning |
|--------|---------|
| physical_qty | Counted qty; `NULL` = not counted yet |
| count_method | `manual` / `barcode` / `rfid` |
| counted_at | When last counted |

**Hindi naka-store sa DB (computed sa PHP):**

- `diff_qty` = `physical_qty - system_qty`
- `diff_value` = `diff_qty * unit_cost`
- `status` =
  - `not_counted` kung NULL physical
  - `exact` kung diff = 0
  - `shortage` kung diff < 0
  - `overage` kung diff > 0

---

## 6. Modules — paano ginagamit at paano gumagana

### 6.1 Dashboard (`dashboard.php`)
Server-rendered PHP (hindi JS API ang main source).

Ipinapakita:
- Total active products
- Stock value (`SUM(system_qty * unit_cost)`)
- Low stock count (`0 < qty <= reorder_level`)
- Out of stock count (`qty = 0`)
- Recent stock movements
- Low stock list

### 6.2 Item List / Products (`products.php` + `api/products.php`)

**Pwede mong gawin:**
- Search by name / SKU / barcode / location
- Filter by category
- Add item
- Edit item (system qty locked sa edit — baguhin via Stock In/Out o Audit)
- Soft-delete item

**Kapag mag-create ng product na may opening qty > 0:**
- Mag-insert ang product
- Automatic may `stock_movements` row: type `in`, reason **Opening stock**

### 6.3 Categories (`categories.php` + `api/categories.php`)

**Pwede mong gawin:**
- Add / rename / delete category
- Makikita ang product count per category

**Delete rules:**
- Hindi pwedeng i-delete kung may **active** products sa category
- Soft-deleted products: `category_id` ine-null muna para hindi mag-fail ang FK

### 6.4 Stock In / Out (`stock_in_out.php` + `api/stock_movements.php`)

**Flow:**
1. Pumili ng product
2. Piliin IN o OUT
3. Ilagay qty, reason, optional reference no.
4. Submit → API updates movement + `system_qty`

**Safety rules:**
- OUT hindi pwedeng lumampas sa available qty (error: Insufficient stock)
- Lahat transactional (BEGIN/COMMIT/ROLLBACK)
- **Blocked** kung may open audit (`in_progress` o `saved`) → HTTP 423

**History filters:** product, type, date from/to

### 6.5 Inventory Count / Audit (`audit.php` + audit APIs)

Ito ang physical count module.

#### A) Start audit
1. Maglagay ng store name + date
2. System gumagawa ng `audit_sessions` row (`in_progress`)
3. Automatic gumagawa ng `audit_counts` row para sa **lahat ng active products** (`physical_qty = NULL`)
4. **Stock In/Out na-pause** hanggang ma-**close** ang audit

#### B) Count methods

**1. Manual Count**
- Type ang physical qty sa table
- Auto-save (debounce ~400ms) papunta sa API

**2. Barcode Scan**
- Click **Barcode Scan** o **Scan / Find Product**
- Lumabas ang scan panel:
  - **USB scanner:** focus sa box → scan → +1 qty
  - **Type:** barcode/SKU + Enter → +1
  - **Camera:** click Camera → allow permission → itutok sa barcode → +1
- Match: barcode **or** SKU (case-insensitive; leading zeros tolerated)
- Duplicate scan within ~700ms ignored (para hindi double-count)

**3. RFID / CSV bulk**
- Paste lines `sku,qty` o upload CSV
- Bulk update physical qty (`count_method = rfid`)
- Hindi totoong RFID hardware — CSV/paste workflow

#### C) Save vs Close

| Action | Effect |
|--------|--------|
| **Save Audit** | Status → `saved`. Pwedeng resume. Stock **naka-lock pa rin**. |
| **Close Audit** | Status → `closed`. Reconcile: `system_qty = physical_qty` para sa counted items. May `Audit Adjustment` movement kung may difference. Uncounted items **hindi** ginagalaw. Stock In/Out **babalik**. |

#### D) Export
CSV variance export via `api/audit_export.php?session_id=...&format=csv`

### 6.6 Reports (`reports.php`)

Tabs:
1. **Stock valuation** — qty × cost / price
2. **Movement history** — filter by date
3. **Low stock** — at/below reorder

Bawat tab may **CSV export** (`?export=...` sa same page).

---

## 7. API map (quick reference)

Lahat under `/inventory-system/api/…` (kailangan naka-login).

| Endpoint | Methods / Actions | Purpose |
|----------|-------------------|---------|
| `auth.php` | login, logout | Session auth |
| `products.php` | GET, POST, PUT (`_method`), DELETE (`_method`) | Product CRUD |
| `categories.php` | GET, POST, PUT, DELETE | Category CRUD |
| `stock_movements.php` | GET list, POST record | Stock in/out |
| `audit_sessions.php` | GET; POST `start` / `save` / `close` | Audit lifecycle |
| `audit_counts.php` | GET by session; POST update/increment | Physical counts |
| `audit_bulk_import.php` | POST CSV/file | Bulk RFID-style import |
| `audit_export.php` | GET CSV | Variance export |
| `dashboard_summary.php` | GET | JSON twin of dashboard (optional) |

**Note:** Maraming UI ang gumagamit ng `POST` + `_method: PUT|DELETE` dahil mas compatible sa PHP/Apache setups.

---

## 8. Important business rules (tandaan)

1. **`system_qty` is source of truth** for on-hand stock.
2. Baguhin ang qty via:
   - Stock In/Out, or
   - Audit Close (reconcile), or
   - Opening stock on product create  
   *(hindi via Edit Product)*
3. Habang may open audit (`in_progress` / `saved`):
   - Stock In/Out **disabled / blocked**
4. Soft-deleted products:
   - Hindi kasama sa new audits
   - Hindi lalabas sa item list
5. Audit close:
   - Counted only → qty overwritten to physical
   - Uncounted → untouched
6. Barcode/SKU must be unique across products.

---

## 9. Typical daily workflows

### A) Receiving goods (Stock In)
1. Item List → confirm product exists (or Add Item)
2. Stock In / Out → type **IN** → qty → reason `Purchase` → optional PO#
3. Check Dashboard / Reports

### B) Sales / usage (Stock Out)
1. Stock In / Out → **OUT** → qty → reason `Sale`
2. System blocks if insufficient stock

### C) End-of-period physical count
1. Inventory Count → Start New Audit
2. Count via Manual / Barcode / Camera / CSV
3. Review differences (shortage/overage)
4. Export CSV kung kailangan
5. **Close Audit** → system qty reconciles
6. Stock In/Out usable ulit

### D) Low stock check
1. Dashboard low stock list, or
2. Reports → Low stock tab → export

---

## 10. Seed / demo data

Pagkatapos i-import ang schema:

- 1 admin user
- 7 categories (Wigs, Hair Oil, Cosmetics, Hair Extensions, Hair Gel, Accessories, Hair Spray)
- 8 sample beauty/hair products with barcodes + locations

Sample barcodes for testing scan:

| Product | SKU | Barcode |
|---------|-----|---------|
| Sensationnel Cloud 9 Wig | C9-1B | `803868451092` |
| Mielle Rosemary Mint Oil | MMO-4OZ | `850001265652` |
| Ruby Kisses Lip Gloss | RK-LG-02 | `649674052143` |

---

## 11. Page ↔ API wiring (UI flow)

```
login.php  ──(form POST)──► session ──► dashboard.php
                                      │
products.php  ◄──fetch──► api/products.php
categories.php ◄──fetch──► api/categories.php
stock_in_out.php ◄── stock.js ──► api/stock_movements.php
audit.php ◄── audit.js ──► api/audit_sessions.php
                         ├─► api/audit_counts.php
                         ├─► api/audit_bulk_import.php
                         └─► api/audit_export.php
reports.php ──(PHP + ?export=)──► CSV download
```

---

## 12. Troubleshooting

| Problem | Check |
|---------|--------|
| Page not loading | Apache running? URL `/inventory-system/` correct? |
| DB connection failed | MySQL running? `config/db.php` credentials? |
| Login fails | Re-import schema / verify `admin@example.com` / `admin123` |
| Stock In/Out blocked | May open audit — Close Audit muna |
| Barcode not recognized | Barcode/SKU sa product record dapat exact match |
| Camera won’t start | Allow browser camera permission; use `localhost` (not LAN IP without HTTPS) |
| Category delete fails | May active products pa; or soft-deleted rows — system nulls FK automatically on delete |
| Code changes not showing | Re-robocopy to `C:\xampp\htdocs\inventory-system\` + hard refresh (Ctrl+F5) |

### Optional smoke tests

```bat
C:\xampp\php\php.exe "D:\TEKMAXLLC - PROJECTS\inventory-system\_smoke_test.php"
C:\xampp\php\php.exe "D:\TEKMAXLLC - PROJECTS\inventory-system\_e2e_test.php"
```

---

## 13. Security notes (current state)

**Mayroon:**
- Password hashing (bcrypt)
- Session gate on pages/APIs
- Session regenerate on login
- PDO prepared statements (SQL injection resistant)
- Soft delete instead of hard wipe for products

**Wala pa / limited:**
- Role-based permissions (admin vs staff unused)
- CSRF tokens
- Rate limiting
- User management UI
- Image upload (URL only)

Para production, idagdag ang mga ito at palitan ang default admin password.

---

## 14. One-page summary

> Mag-login → manage **Categories** + **Products** → mag-record ng **Stock In/Out** araw-araw → kapag inventory time, mag-**Start Audit**, mag-count (manual/scan/camera/CSV), tapos **Close** para i-reconcile ang `system_qty` → tingnan **Dashboard/Reports** para valuation, movements, at low stock.

---

*Document generated for TEKMAXLLC Inventory System — local XAMPP edition.*
