<?php
declare(strict_types=1);

// Run only after taking a local database backup. Idempotent, additive migration.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$env = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
    $env[trim($key)] = trim($value, ' "');
}
if (!in_array($env['DB_HOST'] ?? '', ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This migration is restricted to a local database host.');
}
$db = new PDO('mysql:host='.$env['DB_HOST'].';dbname='.$env['DB_DATABASE'].';charset=utf8mb4', $env['DB_USERNAME'], $env['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$column = $db->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
$ensureColumn = static function (string $table, string $name, string $definition) use ($db, $column): void {
    $column->execute([$table, $name]);
    if ($column->fetchColumn() === false) $db->exec('ALTER TABLE `'.$table.'` ADD COLUMN '.$definition);
};
$ensureColumn('restaurants', 'service_charge_percent', 'service_charge_percent DECIMAL(5,2) NOT NULL DEFAULT 10.00');
$column->execute(['orders', 'table_id']);
if ($column->fetchColumn() === 'NO') $db->exec('ALTER TABLE orders MODIFY table_id BIGINT UNSIGNED NULL');
$ensureColumn('orders', 'source', "source ENUM('QR','POS') NOT NULL DEFAULT 'QR'");
$ensureColumn('orders', 'order_type', "order_type ENUM('DINE_IN','TAKEAWAY','DELIVERY') NOT NULL DEFAULT 'DINE_IN'");
$ensureColumn('orders', 'customer_name', 'customer_name VARCHAR(120) NULL');
$ensureColumn('orders', 'customer_phone', 'customer_phone VARCHAR(40) NULL');
$ensureColumn('orders', 'delivery_address', 'delivery_address VARCHAR(500) NULL');
$ensureColumn('orders', 'delivery_notes', 'delivery_notes VARCHAR(500) NULL');
$ensureColumn('orders', 'delivery_charge', 'delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0');
$ensureColumn('orders', 'packaging_charge', 'packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0');
$ensureColumn('orders', 'guest_count', 'guest_count SMALLINT UNSIGNED NULL');
$ensureColumn('orders', 'created_by', 'created_by BIGINT UNSIGNED NULL');
$ensureColumn('orders', 'delivery_status', "delivery_status ENUM('NONE','READY_FOR_DELIVERY','OUT_FOR_DELIVERY','DELIVERED') NOT NULL DEFAULT 'NONE'");
$ensureColumn('orders', 'customer_id', 'customer_id BIGINT UNSIGNED NULL');
$ensureColumn('order_items', 'special_instruction', 'special_instruction VARCHAR(500) NULL');
$db->exec("ALTER TABLE orders MODIFY status ENUM('PENDING','ACCEPTED','PREPARING','READY','SERVED','REJECTED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING'");
$statements = [
    "CREATE TABLE IF NOT EXISTS restaurant_customers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, name VARCHAR(120) NULL, phone VARCHAR(40) NOT NULL, address VARCHAR(500) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_customer_phone (restaurant_id,phone), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_invoices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, invoice_number VARCHAR(40) NOT NULL, subtotal DECIMAL(12,2) NOT NULL, discount DECIMAL(12,2) NOT NULL DEFAULT 0, service_charge DECIMAL(12,2) NOT NULL DEFAULT 0, tax DECIMAL(12,2) NOT NULL DEFAULT 0, packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0, delivery_charge DECIMAL(12,2) NOT NULL DEFAULT 0, total DECIMAL(12,2) NOT NULL, paid_total DECIMAL(12,2) NOT NULL DEFAULT 0, payment_status ENUM('UNPAID','PARTIALLY_PAID','PAID') NOT NULL DEFAULT 'UNPAID', generated_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE KEY uq_pos_invoice_order (restaurant_id,order_id), UNIQUE KEY uq_pos_invoice_number (restaurant_id,invoice_number), KEY idx_pos_invoice_date (restaurant_id,created_at), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_invoice_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, invoice_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NULL, menu_item_id BIGINT UNSIGNED NULL, item_name VARCHAR(150) NOT NULL, portion ENUM('HALF','FULL') NULL, quantity INT UNSIGNED NOT NULL, unit_price DECIMAL(12,2) NOT NULL, discount DECIMAL(12,2) NOT NULL DEFAULT 0, line_total DECIMAL(12,2) NOT NULL, special_instruction VARCHAR(500) NULL, FOREIGN KEY (invoice_id) REFERENCES pos_invoices(id) ON DELETE CASCADE, FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL, FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL, method ENUM('CASH','CARD','BANK_TRANSFER','ONLINE_PAYMENT','OTHER') NOT NULL, amount DECIMAL(12,2) NOT NULL, amount_received DECIMAL(12,2) NULL, reference VARCHAR(120) NULL, notes VARCHAR(500) NULL, recorded_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, KEY idx_pos_payment_tenant_date (restaurant_id,created_at), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (invoice_id) REFERENCES pos_invoices(id) ON DELETE CASCADE, FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_kots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, kot_number VARCHAR(40) NOT NULL, created_by BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, UNIQUE KEY uq_pos_kot_number (restaurant_id,kot_number), KEY idx_pos_kot_order (restaurant_id,order_id), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_kot_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, kot_id BIGINT UNSIGNED NOT NULL, order_item_id BIGINT UNSIGNED NULL, item_name VARCHAR(150) NOT NULL, portion VARCHAR(20) NULL, quantity INT NOT NULL, action ENUM('ADDED','CANCELLED') NOT NULL, special_instruction VARCHAR(500) NULL, FOREIGN KEY (kot_id) REFERENCES pos_kots(id) ON DELETE CASCADE, FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_bill_edit_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, invoice_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, user_name VARCHAR(120) NOT NULL, user_role VARCHAR(30) NOT NULL, action_type VARCHAR(40) NOT NULL, item_id BIGINT UNSIGNED NULL, previous_value TEXT NULL, new_value TEXT NULL, reason VARCHAR(500) NULL, ip_address VARCHAR(45) NULL, created_at DATETIME NOT NULL, KEY idx_pos_audit_invoice (restaurant_id,invoice_id,id), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY (invoice_id) REFERENCES pos_invoices(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS pos_delivery_status_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, restaurant_id BIGINT UNSIGNED NOT NULL, order_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, previous_status VARCHAR(30) NOT NULL, new_status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, KEY idx_pos_delivery_order (restaurant_id,order_id,id), FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT) ENGINE=InnoDB",
];
foreach ($statements as $statement) $db->exec($statement);
$ensureColumn('pos_invoices', 'packaging_charge', 'packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0');
$ensureColumn('pos_invoice_items', 'order_item_id', 'order_item_id BIGINT UNSIGNED NULL');
$ensureColumn('pos_payments', 'idempotency_key', 'idempotency_key CHAR(64) NULL');
$index=$db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
$index->execute(['pos_payments','uq_pos_payment_retry']);
if(!(int)$index->fetchColumn())$db->exec('ALTER TABLE pos_payments ADD UNIQUE KEY uq_pos_payment_retry (restaurant_id,idempotency_key)');
$constraint=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
$constraint->execute(['orders','fk_pos_order_customer']);
if(!(int)$constraint->fetchColumn())$db->exec('ALTER TABLE orders ADD CONSTRAINT fk_pos_order_customer FOREIGN KEY (customer_id) REFERENCES restaurant_customers(id) ON DELETE SET NULL');
echo "POS order, invoice, payment, KOT and audit schema is ready. Existing QR orders were retained.\n";
