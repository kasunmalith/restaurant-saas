ALTER TABLE restaurants
  ADD COLUMN delivery_minimum DECIMAL(12,2) NOT NULL DEFAULT 1000.00 AFTER service_charge_percent;

ALTER TABLE orders
  ADD COLUMN online_payment_method ENUM('CASH_ON_DELIVERY','PAY_AT_PICKUP','ONLINE_PAYMENT') NULL AFTER delivery_notes;

ALTER TABLE restaurant_customers
  ADD COLUMN origin ENUM('MANAGER','ONLINE_REGISTRATION','ONLINE_GUEST','POS','LEGACY') NOT NULL DEFAULT 'LEGACY' AFTER address;

ALTER TABLE restaurant_customers
  MODIFY COLUMN origin ENUM('MANAGER','ONLINE_REGISTRATION','ONLINE_GUEST','POS','LEGACY') NOT NULL DEFAULT 'MANAGER';

CREATE TABLE online_customer_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_online_account_customer (restaurant_id, customer_id),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES restaurant_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;
