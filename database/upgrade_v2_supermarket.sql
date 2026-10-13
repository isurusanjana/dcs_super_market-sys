-- Upgrade v2: Supermarket features. Safe to run on a v1 database (run via: php database/upgrade.php)
-- Statements are executed one by one; "duplicate column/key" errors are ignored so the script can be re-run.
ALTER TABLE stock MODIFY qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY reserved_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE stock_movements MODIFY qty_change DECIMAL(12,3) NOT NULL;
ALTER TABLE stock_adjustments MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY type ENUM('increase','decrease','damaged','lost','reserve','release','expired','spoilage','theft') NOT NULL;
ALTER TABLE stock_adjustments ADD COLUMN batch_id INT NULL;
ALTER TABLE purchase_requests MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE purchase_order_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY received_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE grn_items MODIFY received_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY accepted_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY damaged_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY rejected_qty DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY shortage_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE grn_items ADD COLUMN batch_no VARCHAR(40) NULL;
ALTER TABLE grn_items ADD COLUMN expiry_date DATE NULL;
ALTER TABLE purchase_return_items MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE stock_transfer_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY received_qty DECIMAL(12,3) NULL;
ALTER TABLE stocktake_items MODIFY system_qty DECIMAL(12,3) NOT NULL, MODIFY counted_qty DECIMAL(12,3) NULL, MODIFY variance DECIMAL(12,3) NULL;
ALTER TABLE sale_items MODIFY qty DECIMAL(12,3) NOT NULL, MODIFY returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0;
ALTER TABLE sale_items ADD COLUMN unit_label VARCHAR(10) NULL;
ALTER TABLE sales_return_items MODIFY qty DECIMAL(12,3) NOT NULL;
ALTER TABLE products MODIFY reorder_level DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY min_stock DECIMAL(12,3) NOT NULL DEFAULT 0, MODIFY max_stock DECIMAL(12,3) NULL, MODIFY product_type ENUM('book','stationery','grocery','fresh','household','other') NOT NULL DEFAULT 'other';
ALTER TABLE products ADD COLUMN sold_by ENUM('unit','weight') NOT NULL DEFAULT 'unit';
ALTER TABLE products ADD COLUMN plu VARCHAR(10) NULL;
ALTER TABLE products ADD UNIQUE KEY uq_plu (plu);
ALTER TABLE products ADD COLUMN track_expiry TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN shelf_life_days INT NULL;
ALTER TABLE products ADD COLUMN age_restricted TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN is_quick TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN shelf_location VARCHAR(40) NULL;
ALTER TABLE promotions MODIFY type ENUM('percent','fixed','bogo','multibuy') NOT NULL DEFAULT 'percent', MODIFY value DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE promotions ADD COLUMN buy_qty INT NULL;
ALTER TABLE promotions ADD COLUMN get_qty INT NULL;
ALTER TABLE promotions ADD COLUMN bundle_price DECIMAL(12,2) NULL;
ALTER TABLE promotions ADD COLUMN start_time TIME NULL;
ALTER TABLE promotions ADD COLUMN end_time TIME NULL;
ALTER TABLE customers ADD COLUMN card_no VARCHAR(30) NULL;
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
