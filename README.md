# Website Link Running On AwardSpace
- http://stocksense.scienceontheweb.net/

# StockSense — Sales Inventory System

A full-stack web-based Sales Inventory System built with **React (Vite)** for the frontend and **PHP + MySQL** for the backend. The system digitally organizes and manages sales operations and inventory control through a centralized, normalized database solving the data redundancy, inconsistency, and scalability issues found in traditional denormalized spreadsheet-based systems.

---

## Group Members

| Name                   | Role   |
|------------------------|--------|
| Cimafranca, Jearim     | Leader |
| Vergara, Grace         | Member |
| Sedigo, Vanessa        | Member |
| Baldemoro, Jeremiah    | Member |
| Teves, Shara           | Member |

---

## Problem Statement

The existing Sales Inventory System uses a denormalized structure to optimize read performance, but this causes:
- Data redundancy and inconsistency
- Inefficient updates across multiple rows
- Limited scalability as operations grow
- Challenges in ensuring data integrity

## Proposed Solution

A Sales Inventory System with a centralized, normalized database (3NF) was developed. Data is organized into dedicated tables with clear relationships — eliminating redundancy, ensuring consistency, and enabling real-time sales recording with automatic inventory updates.

---

## Features

- **Dashboard** — Live stats including total revenue, monthly sales, product count, customer count, low stock alerts, and pending purchase orders
- **Products Management** — Full CRUD with SKU, category, supplier, unit price, cost price, profit margin calculation, and expiration date tracking
- **Inventory Management** — Real-time stock level monitoring with low stock and critical stock filters, reorder level configuration, and visual stock bar indicators
- **Sales Transactions** — POS-style sale recording with item builder, customer selection, payment method tracking, and full transaction history
- **Customer Management** — Full CRUD with purchase history count and customer profiles
- **Supplier Management** — Full CRUD with linked product count per supplier
- **Purchase Orders** — Create and manage restocking orders with line items, status workflow (Draft → Sent → Partial → Received → Cancelled), and automatic inventory update on receive
- **Database Normalization** — Schema progresses from UNF → 1NF → 2NF → 3NF with full documentation
- **Auto Inventory Triggers** — MySQL triggers automatically deduct stock on sale and restore on cancellation

---

## Database Structure (3NF)

The database consists of **9 normalized tables**. Import `sales_inventory_schema.sql` to recreate the full structure including triggers, views, and seed data.

| Table | Description |
|---|---|
| `categories` | Product categories (e.g. Grains, Beverages) |
| `suppliers` | Supplier contact information |
| `products` | Product catalog with pricing and expiration |
| `customers` | Customer profiles and contact details |
| `inventory_levels` | Stock on hand, reorder level, reorder quantity |
| `sales_transactions` | Sale header — customer, total, payment method, status |
| `sales_transaction_items` | Line items per sale (product, quantity, price) |
| `purchase_orders` | Restocking orders sent to suppliers |
| `purchase_order_items` | Line items per purchase order |

### Key Relationships
- A **product** belongs to one **category** and one **supplier**
- Each **product** has one **inventory level** record
- A **sale transaction** belongs to one **customer** and contains many **sale items**
- A **purchase order** belongs to one **supplier** and contains many **order items**

### Normalization Summary
- **UNF** — All data in one flat table with repeating groups and multi-valued fields
- **1NF** — Atomic values, one product per row, composite primary key (sale_id, product_id)
- **2NF** — Partial dependencies removed; customers, products, and sale headers separated
- **3NF** — Transitive dependencies removed; suppliers and categories extracted into own tables

---

## Project File Structure

```
dist/
├── index.html                  — Main HTML entry point (React app shell)
├── favicon.svg                 — Site favicon
├── icons.svg                   — SVG icon assets
├── .htaccess                   — Apache routing rules (SPA + API)
├── assets/
│   ├── index-d8LbJljo.js       — Compiled React JavaScript bundle
│   └── index-CbDOpl0a.css      — Compiled CSS styles
└── backend/
    ├── config.php              — Database connection configuration
    ├── helpers.php             — Shared CORS headers and response helpers
    └── api/
        ├── dashboard.php       — Dashboard stats endpoint
        ├── products.php        — Products CRUD API
        ├── categories.php      — Categories list API
        ├── suppliers.php       — Suppliers CRUD API
        ├── customers.php       — Customers CRUD API
        ├── inventory.php       — Inventory levels API
        ├── sales.php           — Sales transactions CRUD API
        └── purchase_orders.php — Purchase orders CRUD API
```

---

## How to Run Locally (Arch Linux + Apache + MariaDB)

- I used Arch Linux KDE 6 myself to develop this web application so I have not placed a guide on how to run this on windows machines locally.

### Prerequisites
- Node.js v18+
- Apache (`httpd`)
- MariaDB
- PHP with `pdo_mysql` extension

### Step 1 — Start services
```bash
sudo systemctl start httpd
sudo systemctl start mariadb
```

### Step 2 — Set up the database
```bash
# Log in to MariaDB
sudo mariadb -u root -p

# Inside MariaDB shell
CREATE DATABASE sales_inventory_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'invuser'@'localhost' IDENTIFIED BY 'yourpassword';
GRANT ALL PRIVILEGES ON sales_inventory_db.* TO 'invuser'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# Import the schema
mariadb -u invuser -p sales_inventory_db < sales_inventory_schema.sql
```

### Step 3 — Enable PHP PDO MySQL extension
Open `/etc/php/php.ini` and uncomment:
```ini
extension=pdo_mysql
extension=mysqli
```
Then restart Apache:
```bash
sudo systemctl restart httpd
```

### Step 4 — Place project files
```bash
# Copy source files to Apache web root
sudo cp -r /path/to/project /srv/http/sales_inventory

# Fix permissions
sudo chown -R userName:userName /srv/http/sales_inventory
```

### Step 5 — Configure the database connection
Open `backend/config.php` and update:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'sales_inventory_db');
define('DB_USER', 'invuser');
define('DB_PASS', 'yourpassword');
```

### Step 6 — Enable PUT and DELETE in Apache
Open `/etc/httpd/conf/httpd.conf` and inside the `<Directory "/srv/http">` block set:
```apache
AllowOverride All
```
Also uncomment:
```apache
LoadModule rewrite_module modules/mod_rewrite.so
```
Restart Apache:
```bash
sudo systemctl restart httpd
```

### Step 7 — Run the React frontend
```bash
cd /path/to/project/source
npm install
npm run dev
```

Open **`http://localhost:3000`** in your browser.

---

## How to Deploy on AwardSpace

### Step 1 — Create a MySQL database
1. Log in to AwardSpace Control Panel
2. Go to **MySQL Databases** → create a new database
3. Note down the database name, username, password, and host

### Step 2 — Import the schema
1. Go to **phpMyAdmin** in AwardSpace control panel
2. Select your newly created database from the left sidebar
3. Click the **Import** tab
4. Open `sales_inventory_schema.sql` and **comment out** the first two lines:
```sql
-- CREATE DATABASE IF NOT EXISTS sales_inventory_db ...
-- USE sales_inventory_db;
```
5. Upload the edited file → click **Go**

### Step 3 — Update database credentials
Open `dist/backend/config.php` and update with your AwardSpace credentials:
```php
define('DB_HOST', 'your_awardspace_db_host');  // e.g. fdb1033.awardspace.net
define('DB_NAME', 'your_db_name');              // e.g. 12345678_stocksense
define('DB_USER', 'your_db_user');              // e.g. 12345678_stocksense
define('DB_PASS', 'your_db_password');
```

### Step 4 — Update the API URL and rebuild
In your local project open `.env.production`:
```
VITE_API_URL=https://yourdomain.awardspace.net/backend/api
```
Then rebuild:
```bash
npm run build
cp -r backend dist/backend
```

### Step 5 — Upload files to AwardSpace
1. Go to **File Manager** in AwardSpace control panel
2. Navigate to `/home/www/` (this is your web root)
3. Upload all contents inside the `dist/` folder directly into `/home/www/`:
```
/home/www/
├── index.html
├── .htaccess
├── favicon.svg
├── icons.svg
├── assets/
└── backend/
```

### Step 6 — Verify deployment
Visit your site:
```
https://yourdomain.awardspace.net
```

Test the API directly:
```
https://yourdomain.awardspace.net/backend/api/dashboard.php
```
Should return a JSON response with your dashboard stats.

---

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend | React 18, Vite, React Router DOM, Axios |
| Styling | Custom CSS with CSS Variables (dark theme) |
| Backend | PHP 8+, PDO |
| Database | MySQL / MariaDB |
| Hosting | AwardSpace (PHP + MySQL shared hosting) |
| Fonts | Syne (display), DM Sans (body) — Google Fonts |
