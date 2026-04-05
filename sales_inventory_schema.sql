-- ============================================================
--  SALES INVENTORY SYSTEM — MySQL Database Schema (3NF)
--  Normalization: UNF → 1NF → 2NF → 3NF
--  Hosting: AwardSpace MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS sales_inventory_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sales_inventory_db;

-- ============================================================
-- 1. CATEGORIES
--    Stores product categories (e.g. Grains, Condiments)
--    Extracted in 3NF to remove transitive dependency:
--    product_id → category_name was via category_id
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
  category_id   INT           NOT NULL AUTO_INCREMENT,
  category_name VARCHAR(100)  NOT NULL,
  description   TEXT,
  created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (category_id),
  UNIQUE KEY uq_category_name (category_name)
) ENGINE=InnoDB;


-- ============================================================
-- 2. SUPPLIERS
--    Extracted in 3NF — previously supplier_name/phone/email
--    were stored inside the PRODUCTS table, causing transitive
--    dependency: product_id → supplier_id → supplier_name
-- ============================================================
CREATE TABLE IF NOT EXISTS suppliers (
  supplier_id    INT           NOT NULL AUTO_INCREMENT,
  supplier_name  VARCHAR(150)  NOT NULL,
  contact_person VARCHAR(100),
  phone          VARCHAR(20),
  email          VARCHAR(150),
  address        TEXT,
  created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  UNIQUE KEY uq_supplier_email (email)
) ENGINE=InnoDB;


-- ============================================================
-- 3. PRODUCTS
--    In 1NF: product info mixed with sale rows.
--    In 2NF: separated from sales — partial dependency removed.
--    In 3NF: supplier_id FK replaces embedded supplier columns;
--            category_id FK replaces embedded category columns.
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
  product_id      INT             NOT NULL AUTO_INCREMENT,
  category_id     INT             NOT NULL,
  supplier_id     INT             NOT NULL,
  product_name    VARCHAR(200)    NOT NULL,
  sku             VARCHAR(50)     NOT NULL,
  unit_price      DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
  cost_price      DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
  expiration_date DATE,
  created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id),
  UNIQUE KEY uq_sku (sku),
  CONSTRAINT fk_product_category FOREIGN KEY (category_id)
    REFERENCES categories (category_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_product_supplier FOREIGN KEY (supplier_id)
    REFERENCES suppliers (supplier_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 4. CUSTOMERS
--    In 1NF/2NF: customer columns repeated on every sale row,
--    causing partial dependency on sale_id only (not full PK).
--    In 3NF: customer is its own entity with a surrogate PK.
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
  customer_id  INT           NOT NULL AUTO_INCREMENT,
  first_name   VARCHAR(80)   NOT NULL,
  last_name    VARCHAR(80)   NOT NULL,
  email        VARCHAR(150),
  phone        VARCHAR(20),
  address      TEXT,
  created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (customer_id),
  UNIQUE KEY uq_customer_email (email)
) ENGINE=InnoDB;


-- ============================================================
-- 5. INVENTORY_LEVELS
--    One row per product. Tracks stock on hand, reorder point,
--    and reorder quantity. Separate from PRODUCTS to isolate
--    operational state from product definition (3NF principle:
--    keep independent facts in separate tables).
-- ============================================================
CREATE TABLE IF NOT EXISTS inventory_levels (
  inventory_id      INT      NOT NULL AUTO_INCREMENT,
  product_id        INT      NOT NULL,
  quantity_on_hand  INT      NOT NULL DEFAULT 0,
  reorder_level     INT      NOT NULL DEFAULT 10,
  reorder_quantity  INT      NOT NULL DEFAULT 50,
  last_updated      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (inventory_id),
  UNIQUE KEY uq_product_inventory (product_id),
  CONSTRAINT fk_inventory_product FOREIGN KEY (product_id)
    REFERENCES products (product_id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 6. SALES_TRANSACTIONS
--    Sale header. References customer_id (3NF FK).
--    In UNF: totals and customer info mixed with line items.
--    In 1NF: still repeated across product rows.
--    In 2NF+: isolated — all attributes depend fully on
--    transaction_id alone.
-- ============================================================
CREATE TABLE IF NOT EXISTS sales_transactions (
  transaction_id   INT             NOT NULL AUTO_INCREMENT,
  customer_id      INT,                            -- nullable: walk-in / guest sale
  total_amount     DECIMAL(12, 2)  NOT NULL DEFAULT 0.00,
  tax_amount       DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
  discount_amount  DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
  payment_method   ENUM('Cash','GCash','Card','Bank Transfer','Others')
                                   NOT NULL DEFAULT 'Cash',
  status           ENUM('Pending','Completed','Cancelled','Refunded')
                                   NOT NULL DEFAULT 'Completed',
  notes            TEXT,
  transaction_date DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  CONSTRAINT fk_sale_customer FOREIGN KEY (customer_id)
    REFERENCES customers (customer_id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 7. SALES_TRANSACTION_ITEMS
--    Line items (junction/bridge table). Composite natural key
--    would be (transaction_id, product_id), but we use a
--    surrogate PK for simplicity and FK reference integrity.
--    In 2NF: every attribute here depends on BOTH FKs.
-- ============================================================
CREATE TABLE IF NOT EXISTS sales_transaction_items (
  item_id        INT             NOT NULL AUTO_INCREMENT,
  transaction_id INT             NOT NULL,
  product_id     INT             NOT NULL,
  quantity       INT             NOT NULL DEFAULT 1,
  unit_price     DECIMAL(10, 2)  NOT NULL,   -- price at time of sale (snapshot)
  subtotal       DECIMAL(12, 2)  NOT NULL,
  PRIMARY KEY (item_id),
  UNIQUE KEY uq_sale_product (transaction_id, product_id),
  CONSTRAINT fk_item_transaction FOREIGN KEY (transaction_id)
    REFERENCES sales_transactions (transaction_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_item_product FOREIGN KEY (product_id)
    REFERENCES products (product_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 8. PURCHASE_ORDERS
--    Tracks restocking orders to suppliers.
--    Isolated from PRODUCTS and SUPPLIERS so one supplier can
--    have many POs without any embedded redundancy.
-- ============================================================
CREATE TABLE IF NOT EXISTS purchase_orders (
  po_id          INT            NOT NULL AUTO_INCREMENT,
  supplier_id    INT            NOT NULL,
  po_number      VARCHAR(50)    NOT NULL,
  status         ENUM('Draft','Sent','Partial','Received','Cancelled')
                                NOT NULL DEFAULT 'Draft',
  total_amount   DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  order_date     DATE           NOT NULL,
  expected_date  DATE,
  received_date  DATE,
  notes          TEXT,
  created_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (po_id),
  UNIQUE KEY uq_po_number (po_number),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id)
    REFERENCES suppliers (supplier_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- 9. PURCHASE_ORDER_ITEMS
--    Line items for each PO. Tracks ordered vs received qty.
--    (po_id, product_id) determines all other attributes — 3NF.
-- ============================================================
CREATE TABLE IF NOT EXISTS purchase_order_items (
  poi_id            INT             NOT NULL AUTO_INCREMENT,
  po_id             INT             NOT NULL,
  product_id        INT             NOT NULL,
  quantity_ordered  INT             NOT NULL DEFAULT 0,
  quantity_received INT             NOT NULL DEFAULT 0,
  unit_cost         DECIMAL(10, 2)  NOT NULL DEFAULT 0.00,
  PRIMARY KEY (poi_id),
  UNIQUE KEY uq_po_product (po_id, product_id),
  CONSTRAINT fk_poi_po FOREIGN KEY (po_id)
    REFERENCES purchase_orders (po_id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id)
    REFERENCES products (product_id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;


-- ============================================================
--  TRIGGERS — Auto-update inventory after a sale
-- ============================================================

DELIMITER $$

-- Deduct stock when a sale item is inserted
CREATE TRIGGER trg_after_sale_item_insert
AFTER INSERT ON sales_transaction_items
FOR EACH ROW
BEGIN
  UPDATE inventory_levels
  SET quantity_on_hand = quantity_on_hand - NEW.quantity,
      last_updated     = CURRENT_TIMESTAMP
  WHERE product_id = NEW.product_id;
END$$

-- Restore stock when a sale item is deleted (e.g. cancelled)
CREATE TRIGGER trg_after_sale_item_delete
AFTER DELETE ON sales_transaction_items
FOR EACH ROW
BEGIN
  UPDATE inventory_levels
  SET quantity_on_hand = quantity_on_hand + OLD.quantity,
      last_updated     = CURRENT_TIMESTAMP
  WHERE product_id = OLD.product_id;
END$$

-- Add stock when a PO item is received
CREATE TRIGGER trg_after_po_item_update
AFTER UPDATE ON purchase_order_items
FOR EACH ROW
BEGIN
  IF NEW.quantity_received > OLD.quantity_received THEN
    UPDATE inventory_levels
    SET quantity_on_hand = quantity_on_hand + (NEW.quantity_received - OLD.quantity_received),
        last_updated     = CURRENT_TIMESTAMP
    WHERE product_id = NEW.product_id;
  END IF;
END$$

DELIMITER ;


-- ============================================================
--  SEED DATA — Sample records for all tables
-- ============================================================

-- Categories
INSERT INTO categories (category_name, description) VALUES
  ('Grains & Staples', 'Rice, flour, and dry staple goods'),
  ('Condiments', 'Seasonings, sauces, and vinegars'),
  ('Beverages', 'Water, juices, sodas, and drinks'),
  ('Canned Goods', 'Preserved and canned food products'),
  ('Personal Care', 'Hygiene and personal care products');

-- Suppliers
INSERT INTO suppliers (supplier_name, contact_person, phone, email, address) VALUES
  ('FarmFresh Distributors', 'Juan dela Cruz', '032-234-1111', 'juan@farmfresh.ph', 'Mandaue City, Cebu'),
  ('SugarCo Philippines', 'Maria Santos', '032-234-2222', 'maria@sugarco.ph', 'Talisay City, Cebu'),
  ('OilMart Supply', 'Pedro Reyes', '032-234-3333', 'pedro@oilmart.ph', 'Lapu-Lapu City, Cebu'),
  ('SaltCo Inc.', 'Rosa Lim', '032-234-4444', 'rosa@saltco.ph', 'Cebu City, Cebu'),
  ('BevPro Trading', 'Carlo Tan', '032-234-5555', 'carlo@bevpro.ph', 'Consolacion, Cebu');

-- Products
INSERT INTO products (category_id, supplier_id, product_name, sku, unit_price, cost_price, expiration_date) VALUES
  (1, 1, 'Premium White Rice (5kg)',   'GRN-001', 250.00, 190.00, '2025-12-31'),
  (1, 1, 'All-Purpose Flour (1kg)',    'GRN-002', 65.00,  48.00,  '2025-06-30'),
  (2, 2, 'Refined White Sugar (1kg)',  'CON-001', 85.00,  62.00,  '2025-09-30'),
  (2, 4, 'Iodized Salt (500g)',        'CON-002', 28.00,  18.00,  '2026-01-31'),
  (2, 3, 'Palm Oil (1L)',              'CON-003', 125.00, 90.00,  '2025-08-31'),
  (2, 3, 'Cane Vinegar (750ml)',       'CON-004', 45.00,  30.00,  '2026-03-31'),
  (3, 5, 'Mineral Water (500ml)',      'BEV-001', 18.00,  10.00,  '2025-07-31'),
  (3, 5, 'Orange Juice (1L)',          'BEV-002', 95.00,  65.00,  '2025-05-31'),
  (4, 1, 'Sardines in Tomato Sauce',   'CAN-001', 35.00,  22.00,  '2026-06-30'),
  (5, 4, 'Shampoo 180ml',             'PC-001',  89.00,  55.00,  NULL);

-- Inventory levels (auto-linked to products)
INSERT INTO inventory_levels (product_id, quantity_on_hand, reorder_level, reorder_quantity) VALUES
  (1, 200, 30, 100),
  (2, 150, 20, 80),
  (3, 180, 25, 100),
  (4, 300, 50, 150),
  (5, 90,  15, 60),
  (6, 120, 20, 80),
  (7, 500, 100, 300),
  (8, 60,  10, 50),
  (9, 250, 40, 120),
  (10, 80, 10, 50);

-- Customers
INSERT INTO customers (first_name, last_name, email, phone, address) VALUES
  ('Ana',     'Reyes',   'ana.reyes@gmail.com',   '09171234567', 'Cebu City, Cebu'),
  ('Benjamin','Cruz',    'ben.cruz@gmail.com',    '09181234567', 'Mandaue City, Cebu'),
  ('Carla',   'Santos',  'carla.s@yahoo.com',     '09191234567', 'Lapu-Lapu City, Cebu'),
  ('Diego',   'Lim',     'diego.lim@outlook.com', '09271234567', 'Talisay City, Cebu'),
  ('Elena',   'Torres',  'elena.t@gmail.com',     '09281234567', 'Consolacion, Cebu');

-- Sales transactions
INSERT INTO sales_transactions (customer_id, total_amount, tax_amount, payment_method, status, transaction_date) VALUES
  (1, 530.00, 0.00, 'Cash',          'Completed', '2024-01-10 09:30:00'),
  (1, 215.00, 0.00, 'GCash',         'Completed', '2024-01-10 14:15:00'),
  (2, 625.00, 0.00, 'Card',          'Completed', '2024-01-11 10:00:00'),
  (3, 380.00, 0.00, 'Cash',          'Completed', '2024-01-12 11:45:00'),
  (4, 142.00, 0.00, 'GCash',         'Completed', '2024-01-13 15:30:00'),
  (NULL,118.00,0.00,'Cash',          'Completed', '2024-01-14 08:20:00'),
  (5, 250.00, 0.00, 'Bank Transfer', 'Completed', '2024-01-14 16:00:00'),
  (2, 95.00,  0.00, 'Cash',          'Cancelled', '2024-01-15 09:10:00');

-- Sale items (6 transactions worth of items; excluded cancelled #8)
INSERT INTO sales_transaction_items (transaction_id, product_id, quantity, unit_price, subtotal) VALUES
  (1, 1, 1, 250.00, 250.00), (1, 3, 2, 85.00,  170.00), (1, 7, 6, 18.00,  108.00),
  (2, 4, 3, 28.00,  84.00),  (2, 6, 3, 45.00,  135.00),
  (3, 1, 2, 250.00, 500.00), (3, 4, 5, 28.00,   140.00),
  (4, 2, 2, 65.00,  130.00), (4, 3, 1, 85.00,   85.00), (4, 9, 5, 35.00, 175.00),
  (5, 7, 4, 18.00,  72.00),  (5, 10,1, 89.00,   89.00),
  (6, 7, 3, 18.00,  54.00),  (6, 4, 2, 28.00,   56.00),
  (7, 1, 1, 250.00, 250.00);

-- Purchase orders
INSERT INTO purchase_orders (supplier_id, po_number, status, total_amount, order_date, expected_date, received_date) VALUES
  (1, 'PO-2024-001', 'Received', 27000.00, '2024-01-02', '2024-01-07', '2024-01-07'),
  (2, 'PO-2024-002', 'Received', 6200.00,  '2024-01-03', '2024-01-08', '2024-01-08'),
  (5, 'PO-2024-003', 'Sent',     5750.00,  '2024-01-14', '2024-01-19', NULL),
  (1, 'PO-2024-004', 'Draft',    9600.00,  '2024-01-15', '2024-01-22', NULL);

-- Purchase order items
INSERT INTO purchase_order_items (po_id, product_id, quantity_ordered, quantity_received, unit_cost) VALUES
  (1, 1, 200, 200, 190.00),
  (1, 2, 100, 100, 48.00),
  (2, 3, 100, 100, 62.00),
  (3, 7, 300, 0,   10.00),
  (3, 8, 50,  0,   65.00),
  (4, 1, 100, 0,   190.00),
  (4, 9, 120, 0,   22.00);


-- ============================================================
--  USEFUL VIEWS
-- ============================================================

-- Low stock alert view
CREATE OR REPLACE VIEW vw_low_stock AS
  SELECT
    p.product_id,
    p.product_name,
    p.sku,
    il.quantity_on_hand,
    il.reorder_level,
    il.reorder_quantity,
    s.supplier_name,
    s.phone AS supplier_phone
  FROM inventory_levels il
  JOIN products  p ON il.product_id  = p.product_id
  JOIN suppliers s ON p.supplier_id  = s.supplier_id
  WHERE il.quantity_on_hand <= il.reorder_level;

-- Sales summary by product
CREATE OR REPLACE VIEW vw_product_sales_summary AS
  SELECT
    p.product_id,
    p.product_name,
    SUM(sti.quantity)  AS total_qty_sold,
    SUM(sti.subtotal)  AS total_revenue,
    COUNT(DISTINCT sti.transaction_id) AS num_transactions
  FROM sales_transaction_items sti
  JOIN products p ON sti.product_id = p.product_id
  JOIN sales_transactions st ON sti.transaction_id = st.transaction_id
  WHERE st.status = 'Completed'
  GROUP BY p.product_id, p.product_name;

-- Daily sales totals
CREATE OR REPLACE VIEW vw_daily_sales AS
  SELECT
    DATE(transaction_date)  AS sale_date,
    COUNT(*)                AS num_transactions,
    SUM(total_amount)       AS daily_revenue
  FROM sales_transactions
  WHERE status = 'Completed'
  GROUP BY DATE(transaction_date)
  ORDER BY sale_date DESC;
