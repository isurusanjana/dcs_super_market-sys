-- Supermarket / Retail Management System (v2) - schema (MySQL 5.7+/MariaDB 10.3+)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS settings (skey VARCHAR(60) PRIMARY KEY, svalue TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sequences (name VARCHAR(40) PRIMARY KEY, val BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL UNIQUE, description VARCHAR(255) NULL,
  is_super TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(80) NOT NULL UNIQUE, module VARCHAR(20) NOT NULL, feature VARCHAR(40) NOT NULL,
  action VARCHAR(40) NOT NULL, label VARCHAR(120) NULL, KEY (module)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT NOT NULL, permission_id INT NOT NULL, PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE, FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, address VARCHAR(255) NULL,
  phone VARCHAR(30) NULL, email VARCHAR(120) NULL, tax_rate DECIMAL(5,2) NULL, receipt_footer VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS warehouses (
  id INT AUTO_INCREMENT PRIMARY KEY, branch_id INT NOT NULL, name VARCHAR(120) NOT NULL, location VARCHAR(160) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1, FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS counters (
  id INT AUTO_INCREMENT PRIMARY KEY, branch_id INT NOT NULL, name VARCHAR(80) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employees (
  id INT AUTO_INCREMENT PRIMARY KEY, emp_no VARCHAR(20) NOT NULL UNIQUE, full_name VARCHAR(150) NOT NULL, nic VARCHAR(20) NULL,
  phone VARCHAR(30) NULL, email VARCHAR(120) NULL, address VARCHAR(255) NULL, designation VARCHAR(80) NULL, branch_id INT NULL,
  hire_date DATE NULL, status ENUM('active','inactive') NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS attendance (
  id INT AUTO_INCREMENT PRIMARY KEY, employee_id INT NOT NULL, att_date DATE NOT NULL, check_in TIME NULL, check_out TIME NULL,
  status ENUM('present','absent','late','leave','half_day') NOT NULL DEFAULT 'present', note VARCHAR(255) NULL, recorded_by INT NULL,
  UNIQUE KEY uq_att (employee_id, att_date), FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, full_name VARCHAR(120) NOT NULL, email VARCHAR(120) NULL,
  phone VARCHAR(30) NULL, password_hash VARCHAR(255) NOT NULL, employee_id INT NULL, branch_id INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, is_locked TINYINT(1) NOT NULL DEFAULT 0, failed_attempts INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL, must_change_password TINYINT(1) NOT NULL DEFAULT 0, last_login_at DATETIME NULL, last_login_ip VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE SET NULL, FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS user_roles (
  user_id INT NOT NULL, role_id INT NOT NULL, PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NULL, ip VARCHAR(45) NOT NULL, success TINYINT(1) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, username VARCHAR(50) NULL, action VARCHAR(40) NOT NULL, entity VARCHAR(60) NULL,
  entity_id VARCHAR(40) NULL, details TEXT NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY (action), KEY (entity, entity_id), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, contact_person VARCHAR(120) NULL,
  phone VARCHAR(30) NULL, email VARCHAR(120) NULL, address VARCHAR(255) NULL, payment_terms VARCHAR(80) NULL, notes TEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer_groups (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, price_type ENUM('retail','wholesale','member') NOT NULL DEFAULT 'retail',
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS customers (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(150) NOT NULL, phone VARCHAR(30) NULL, email VARCHAR(120) NULL,
  address VARCHAR(255) NULL, group_id INT NULL, credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0, loyalty_points INT NOT NULL DEFAULT 0, card_no VARCHAR(30) NULL UNIQUE,
  is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (phone), KEY (name),
  FOREIGN KEY (group_id) REFERENCES customer_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS customer_payments (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, method VARCHAR(20) NOT NULL, reference VARCHAR(80) NULL,
  note VARCHAR(255) NULL, user_id INT NULL, branch_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS loyalty_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY, customer_id INT NOT NULL, points INT NOT NULL, reason VARCHAR(160) NULL, ref VARCHAR(40) NULL, user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY, parent_id INT NULL, name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS brands (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS authors (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS publishers (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS units (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL UNIQUE, short_name VARCHAR(10) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY, product_type ENUM('book','stationery','grocery','fresh','household','other') NOT NULL DEFAULT 'other',
  sku VARCHAR(40) NOT NULL UNIQUE, barcode VARCHAR(40) NULL UNIQUE, name VARCHAR(200) NOT NULL,
  category_id INT NULL, brand_id INT NULL, unit_id INT NULL, description TEXT NULL, image VARCHAR(120) NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0, selling_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(12,2) NULL, member_price DECIMAL(12,2) NULL, tax_rate DECIMAL(5,2) NULL,
  reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0, min_stock DECIMAL(12,3) NOT NULL DEFAULT 0, max_stock DECIMAL(12,3) NULL,
  isbn VARCHAR(20) NULL UNIQUE, author_id INT NULL, publisher_id INT NULL, edition VARCHAR(40) NULL, language VARCHAR(40) NULL,
  genre VARCHAR(80) NULL, pub_year SMALLINT NULL,
  size VARCHAR(40) NULL, color VARCHAR(40) NULL, pack_qty INT NULL, parent_id INT NULL,
  sold_by ENUM('unit','weight') NOT NULL DEFAULT 'unit', plu VARCHAR(10) NULL UNIQUE, track_expiry TINYINT(1) NOT NULL DEFAULT 0, shelf_life_days INT NULL,
  age_restricted TINYINT(1) NOT NULL DEFAULT 0, is_quick TINYINT(1) NOT NULL DEFAULT 0, shelf_location VARCHAR(40) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, is_archived TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (name), KEY (product_type),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL, FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL, FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE SET NULL,
  FOREIGN KEY (publisher_id) REFERENCES publishers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS product_price_history (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, field_name VARCHAR(30) NOT NULL, old_value DECIMAL(12,2) NULL, new_value DECIMAL(12,2) NULL,
  user_id INT NULL, changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (product_id), FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS supplier_products (
  supplier_id INT NOT NULL, product_id INT NOT NULL, supplier_sku VARCHAR(60) NULL, last_price DECIMAL(12,2) NULL, PRIMARY KEY (supplier_id, product_id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_lists (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL, customer_group_id INT NULL, start_date DATE NULL, end_date DATE NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, FOREIGN KEY (customer_group_id) REFERENCES customer_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS price_list_items (
  id INT AUTO_INCREMENT PRIMARY KEY, price_list_id INT NOT NULL, product_id INT NOT NULL, price DECIMAL(12,2) NOT NULL, UNIQUE KEY uq_pl (price_list_id, product_id),
  FOREIGN KEY (price_list_id) REFERENCES price_lists(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS promotions (
  id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, type ENUM('percent','fixed','bogo','multibuy') NOT NULL DEFAULT 'percent', value DECIMAL(12,2) NOT NULL DEFAULT 0,
  buy_qty INT NULL, get_qty INT NULL, bundle_price DECIMAL(12,2) NULL, start_time TIME NULL, end_time TIME NULL,
  scope ENUM('all','category','product') NOT NULL DEFAULT 'all', category_id INT NULL, product_id INT NULL, min_qty INT NOT NULL DEFAULT 1,
  customer_group_id INT NULL, coupon_code VARCHAR(30) NULL, start_date DATE NULL, end_date DATE NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
  KEY (coupon_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock (
  warehouse_id INT NOT NULL, product_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL DEFAULT 0, reserved_qty DECIMAL(12,3) NOT NULL DEFAULT 0, damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (warehouse_id, product_id), FOREIGN KEY (warehouse_id) REFERENCES warehouses(id), FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, warehouse_id INT NOT NULL, qty_change DECIMAL(12,3) NOT NULL, type VARCHAR(30) NOT NULL,
  ref_type VARCHAR(30) NULL, ref_id INT NULL, note VARCHAR(255) NULL, user_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY (product_id), KEY (warehouse_id), KEY (type), KEY (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stock_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY, adj_no VARCHAR(30) NOT NULL UNIQUE, warehouse_id INT NOT NULL, product_id INT NOT NULL,
  type ENUM('increase','decrease','damaged','lost','reserve','release','expired','spoilage','theft') NOT NULL, qty DECIMAL(12,3) NOT NULL, reason VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', created_by INT NULL, approved_by INT NULL, batch_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, approved_at DATETIME NULL, KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_requests (
  id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, warehouse_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL,
  status ENUM('open','ordered','closed') NOT NULL DEFAULT 'open', po_id INT NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INT AUTO_INCREMENT PRIMARY KEY, po_no VARCHAR(30) NOT NULL UNIQUE, supplier_id INT NOT NULL, warehouse_id INT NOT NULL, order_date DATE NOT NULL,
  expected_date DATE NULL, status ENUM('draft','submitted','approved','partial','received','cancelled') NOT NULL DEFAULT 'draft', notes VARCHAR(255) NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0, created_by INT NULL, approved_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id), FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_order_items (
  id INT AUTO_INCREMENT PRIMARY KEY, po_id INT NOT NULL, product_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL, price DECIMAL(12,2) NOT NULL, received_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE, FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS grns (
  id INT AUTO_INCREMENT PRIMARY KEY, grn_no VARCHAR(30) NOT NULL UNIQUE, po_id INT NOT NULL, supplier_id INT NOT NULL, warehouse_id INT NOT NULL,
  received_date DATE NOT NULL, notes VARCHAR(255) NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (po_id) REFERENCES purchase_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS grn_items (
  id INT AUTO_INCREMENT PRIMARY KEY, grn_id INT NOT NULL, po_item_id INT NOT NULL, product_id INT NOT NULL, received_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  accepted_qty DECIMAL(12,3) NOT NULL DEFAULT 0, damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0, rejected_qty DECIMAL(12,3) NOT NULL DEFAULT 0, shortage_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  price DECIMAL(12,2) NOT NULL, batch_no VARCHAR(40) NULL, expiry_date DATE NULL, FOREIGN KEY (grn_id) REFERENCES grns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_invoices (
  id INT AUTO_INCREMENT PRIMARY KEY, inv_no VARCHAR(30) NOT NULL UNIQUE, supplier_invoice_no VARCHAR(60) NULL, supplier_id INT NOT NULL, grn_id INT NULL, po_id INT NULL,
  invoice_date DATE NOT NULL, due_date DATE NULL, total DECIMAL(14,2) NOT NULL, status ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS supplier_payments (
  id INT AUTO_INCREMENT PRIMARY KEY, pay_no VARCHAR(30) NOT NULL UNIQUE, supplier_id INT NOT NULL, invoice_id INT NULL, amount DECIMAL(14,2) NOT NULL,
  method VARCHAR(20) NOT NULL, reference VARCHAR(80) NULL, note VARCHAR(255) NULL, paid_date DATE NOT NULL, user_id INT NULL, branch_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_returns (
  id INT AUTO_INCREMENT PRIMARY KEY, pr_no VARCHAR(30) NOT NULL UNIQUE, supplier_id INT NOT NULL, warehouse_id INT NOT NULL, reason VARCHAR(255) NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending', total DECIMAL(14,2) NOT NULL DEFAULT 0, created_by INT NULL, approved_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS purchase_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY, pr_id INT NOT NULL, product_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL, price DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (pr_id) REFERENCES purchase_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY, tr_no VARCHAR(30) NOT NULL UNIQUE, from_warehouse_id INT NOT NULL, to_warehouse_id INT NOT NULL,
  status ENUM('draft','approved','dispatched','received','rejected','cancelled') NOT NULL DEFAULT 'draft', notes VARCHAR(255) NULL,
  created_by INT NULL, approved_by INT NULL, dispatched_by INT NULL, received_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (from_warehouse_id) REFERENCES warehouses(id), FOREIGN KEY (to_warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stock_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY, transfer_id INT NOT NULL, product_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL, received_qty DECIMAL(12,3) NULL,
  FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stocktakes (
  id INT AUTO_INCREMENT PRIMARY KEY, st_no VARCHAR(30) NOT NULL UNIQUE, warehouse_id INT NOT NULL, category_id INT NULL,
  status ENUM('counting','pending_approval','approved','cancelled') NOT NULL DEFAULT 'counting', notes VARCHAR(255) NULL,
  created_by INT NULL, approved_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS stocktake_items (
  id INT AUTO_INCREMENT PRIMARY KEY, stocktake_id INT NOT NULL, product_id INT NOT NULL, system_qty DECIMAL(12,3) NOT NULL, counted_qty DECIMAL(12,3) NULL, variance DECIMAL(12,3) NULL,
  FOREIGN KEY (stocktake_id) REFERENCES stocktakes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS pos_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY, session_no VARCHAR(30) NOT NULL UNIQUE, user_id INT NOT NULL, branch_id INT NOT NULL, counter_id INT NOT NULL,
  warehouse_id INT NOT NULL, opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0, expected_cash DECIMAL(12,2) NULL, counted_cash DECIMAL(12,2) NULL,
  variance DECIMAL(12,2) NULL, status ENUM('open','suspended','closed','reconciled') NOT NULL DEFAULT 'open', opened_at DATETIME NOT NULL, closed_at DATETIME NULL,
  notes VARCHAR(255) NULL, reconciled_by INT NULL, KEY (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY, invoice_no VARCHAR(30) NOT NULL UNIQUE, branch_id INT NOT NULL, session_id INT NULL, user_id INT NOT NULL, customer_id INT NULL,
  sale_date DATETIME NOT NULL, subtotal DECIMAL(14,2) NOT NULL, discount_total DECIMAL(14,2) NOT NULL DEFAULT 0, tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL, paid_total DECIMAL(14,2) NOT NULL DEFAULT 0, credit_amount DECIMAL(14,2) NOT NULL DEFAULT 0, change_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('completed','void') NOT NULL DEFAULT 'completed', void_reason VARCHAR(255) NULL, voided_by INT NULL, voided_at DATETIME NULL,
  receipt_token CHAR(32) NOT NULL, idem_key VARCHAR(64) NULL UNIQUE, notes VARCHAR(255) NULL, KEY (sale_date), KEY (customer_id), KEY (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sale_items (
  id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, product_id INT NOT NULL, name VARCHAR(200) NOT NULL, qty DECIMAL(12,3) NOT NULL, unit_price DECIMAL(12,2) NOT NULL,
  cost_price DECIMAL(12,2) NOT NULL DEFAULT 0, discount DECIMAL(12,2) NOT NULL DEFAULT 0, tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0, tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL, returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0, promo_name VARCHAR(120) NULL, unit_label VARCHAR(10) NULL, KEY (product_id),
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sale_payments (
  id INT AUTO_INCREMENT PRIMARY KEY, sale_id INT NOT NULL, method VARCHAR(20) NOT NULL, amount DECIMAL(14,2) NOT NULL, reference VARCHAR(80) NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS held_carts (
  id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, label VARCHAR(80) NULL, data MEDIUMTEXT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_returns (
  id INT AUTO_INCREMENT PRIMARY KEY, return_no VARCHAR(30) NOT NULL UNIQUE, sale_id INT NOT NULL, branch_id INT NOT NULL, user_id INT NOT NULL, approved_by INT NULL,
  return_date DATETIME NOT NULL, total_refund DECIMAL(14,2) NOT NULL, refund_method ENUM('cash','card','account','exchange') NOT NULL, exchange_used TINYINT(1) NOT NULL DEFAULT 0, credit_used DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NOT NULL, session_id INT NULL, FOREIGN KEY (sale_id) REFERENCES sales(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sales_return_items (
  id INT AUTO_INCREMENT PRIMARY KEY, return_id INT NOT NULL, sale_item_id INT NOT NULL, product_id INT NOT NULL, qty DECIMAL(12,3) NOT NULL, amount DECIMAL(14,2) NOT NULL,
  item_condition ENUM('good','damaged') NOT NULL DEFAULT 'good', FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expenses (
  id INT AUTO_INCREMENT PRIMARY KEY, expense_no VARCHAR(30) NOT NULL UNIQUE, category VARCHAR(80) NOT NULL, description VARCHAR(255) NULL, amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL, method VARCHAR(20) NOT NULL DEFAULT 'cash', branch_id INT NULL, status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  created_by INT NULL, approved_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cash_adjustments (
  id INT AUTO_INCREMENT PRIMARY KEY, branch_id INT NOT NULL, type ENUM('in','out') NOT NULL, amount DECIMAL(12,2) NOT NULL, reason VARCHAR(255) NOT NULL,
  user_id INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fin_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, txn_date DATETIME NOT NULL, type VARCHAR(30) NOT NULL, direction ENUM('in','out') NOT NULL, method VARCHAR(20) NOT NULL,
  amount DECIMAL(14,2) NOT NULL, branch_id INT NULL, session_id INT NULL, ref_type VARCHAR(30) NULL, ref_id INT NULL, note VARCHAR(255) NULL, user_id INT NULL,
  KEY (txn_date), KEY (type), KEY (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS=1;

CREATE TABLE IF NOT EXISTS stock_batches (
  id INT AUTO_INCREMENT PRIMARY KEY, warehouse_id INT NOT NULL, product_id INT NOT NULL, batch_no VARCHAR(40) NOT NULL DEFAULT '', expiry_date DATE NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0, received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (product_id, warehouse_id), KEY (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS product_barcodes (
  id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, barcode VARCHAR(40) NOT NULL UNIQUE, qty_per_scan DECIMAL(12,3) NOT NULL DEFAULT 1, label VARCHAR(60) NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gift_cards (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, initial_amount DECIMAL(12,2) NOT NULL, balance DECIMAL(12,2) NOT NULL,
  status ENUM('active','used','blocked') NOT NULL DEFAULT 'active', customer_id INT NULL, expires_on DATE NULL, note VARCHAR(160) NULL,
  issued_by INT NULL, branch_id INT NULL, issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gift_card_txns (
  id INT AUTO_INCREMENT PRIMARY KEY, gift_card_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, type ENUM('issue','redeem','refund','adjust') NOT NULL,
  sale_id INT NULL, user_id INT NULL, note VARCHAR(160) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (gift_card_id),
  FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_schedules (
  id INT AUTO_INCREMENT PRIMARY KEY, report VARCHAR(40) NOT NULL, frequency ENUM('daily','weekly','monthly') NOT NULL, email VARCHAR(120) NOT NULL,
  period_days INT NOT NULL DEFAULT 1, is_active TINYINT(1) NOT NULL DEFAULT 1, last_run DATETIME NULL, created_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upgrade v2: Supermarket features. Safe to run on a v1 database (run via: php database/upgrade.php)
-- Statements are executed one by one; "duplicate column/key" errors are ignored so the script can be re-run.
ALTER TABLE stock MODIFY qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY reserved_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE stock_movements MODIFY qty_change DECIMAL(12,3) NOT NULL;
ALTER TABLE stock_adjustments MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY type ENUM('increase','decrease','damaged','lost','reserve','release','expired','spoilage','theft') NOT NULL;
-- ALTER TABLE stock_adjustments ADD COLUMN batch_id INT NULL;
ALTER TABLE purchase_requests MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE purchase_order_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY received_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE grn_items MODIFY received_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY accepted_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY rejected_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY shortage_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
-- ALTER TABLE grn_items ADD COLUMN batch_no VARCHAR(40) NULL;
-- ALTER TABLE grn_items ADD COLUMN expiry_date DATE NULL;
ALTER TABLE purchase_return_items MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE stock_transfer_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY received_qty DECIMAL(12,3) NULL;
ALTER TABLE stocktake_items MODIFY system_qty DECIMAL(12,3) NOT NULL, MODIFY counted_qty DECIMAL(12,3) NULL, MODIFY variance DECIMAL(12,3) NULL;
ALTER TABLE sale_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
-- ALTER TABLE sale_items ADD COLUMN unit_label VARCHAR(10) NULL;
ALTER TABLE sales_return_items MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE products MODIFY reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY min_stock DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY max_stock DECIMAL(12,3) NULL, MODIFY product_type ENUM('book','stationery','grocery','fresh','household','other') NOT NULL DEFAULT 'other';
-- ALTER TABLE products ADD COLUMN sold_by ENUM('unit','weight') NOT NULL DEFAULT 'unit';
-- ALTER TABLE products ADD COLUMN plu VARCHAR(10) NULL;
ALTER TABLE products ADD UNIQUE KEY uq_plu (plu);
-- ALTER TABLE products ADD COLUMN track_expiry TINYINT(1) NOT NULL DEFAULT 0;
-- ALTER TABLE products ADD COLUMN shelf_life_days INT NULL;
-- ALTER TABLE products ADD COLUMN age_restricted TINYINT(1) NOT NULL DEFAULT 0;
-- ALTER TABLE products ADD COLUMN is_quick TINYINT(1) NOT NULL DEFAULT 0;
-- ALTER TABLE products ADD COLUMN shelf_location VARCHAR(40) NULL;
ALTER TABLE promotions MODIFY type ENUM('percent','fixed','bogo','multibuy') NOT NULL DEFAULT 'percent', MODIFY value DECIMAL(12,2) NOT NULL DEFAULT 0;
-- ALTER TABLE promotions ADD COLUMN buy_qty INT NULL;
-- ALTER TABLE promotions ADD COLUMN get_qty INT NULL;
-- ALTER TABLE promotions ADD COLUMN bundle_price DECIMAL(12,2) NULL;
-- ALTER TABLE promotions ADD COLUMN start_time TIME NULL;
-- ALTER TABLE promotions ADD COLUMN end_time TIME NULL;
-- ALTER TABLE customers ADD COLUMN card_no VARCHAR(30) NULL;
ALTER TABLE customers ADD UNIQUE KEY uq_card (card_no);
CREATE TABLE IF NOT EXISTS stock_batches (
  id INT AUTO_INCREMENT PRIMARY KEY, warehouse_id INT NOT NULL, product_id INT NOT NULL, batch_no VARCHAR(40) NOT NULL DEFAULT '', expiry_date DATE NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0, received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (product_id, warehouse_id), KEY (expiry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS product_barcodes (
  id INT AUTO_INCREMENT PRIMARY KEY, product_id INT NOT NULL, barcode VARCHAR(40) NOT NULL UNIQUE, qty_per_scan DECIMAL(12,3) NOT NULL DEFAULT 1, label VARCHAR(60) NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gift_cards (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(30) NOT NULL UNIQUE, initial_amount DECIMAL(12,2) NOT NULL, balance DECIMAL(12,2) NOT NULL,
  status ENUM('active','used','blocked') NOT NULL DEFAULT 'active', customer_id INT NULL, expires_on DATE NULL, note VARCHAR(160) NULL,
  issued_by INT NULL, branch_id INT NULL, issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS gift_card_txns (
  id INT AUTO_INCREMENT PRIMARY KEY, gift_card_id INT NOT NULL, amount DECIMAL(12,2) NOT NULL, type ENUM('issue','redeem','refund','adjust') NOT NULL,
  sale_id INT NULL, user_id INT NULL, note VARCHAR(160) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, KEY (gift_card_id),
  FOREIGN KEY (gift_card_id) REFERENCES gift_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO stock (warehouse_id, product_id, qty) SELECT w.id, p.id, 0 FROM warehouses w CROSS JOIN products p