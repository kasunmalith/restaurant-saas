CREATE TABLE online_customer_addresses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  label VARCHAR(80) NULL,
  address VARCHAR(500) NOT NULL,
  address_hash CHAR(64) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_online_customer_address (restaurant_id, customer_id, address_hash),
  INDEX idx_online_customer_addresses (restaurant_id, customer_id, is_default, id),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  FOREIGN KEY (customer_id) REFERENCES restaurant_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO online_customer_addresses
  (restaurant_id, customer_id, label, address, address_hash, is_default, created_at, updated_at)
SELECT a.restaurant_id, a.customer_id, 'Primary', TRIM(c.address), SHA2(TRIM(c.address), 256), 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM online_customer_accounts a
JOIN restaurant_customers c ON c.id=a.customer_id AND c.restaurant_id=a.restaurant_id
WHERE c.address IS NOT NULL AND TRIM(c.address)<>'';
