CREATE TABLE IF NOT EXISTS dining_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  table_id BIGINT UNSIGNED NOT NULL,
  session_number VARCHAR(40) NOT NULL,
  client_token_hash CHAR(64) NOT NULL,
  status ENUM('ACTIVE','EXPIRED','CLOSED') NOT NULL DEFAULT 'ACTIVE',
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  closed_at DATETIME NULL,
  UNIQUE KEY uq_dining_session_number (session_number),
  KEY idx_dining_session_lookup (table_id,client_token_hash,status,expires_at),
  KEY idx_dining_session_expiry (status,expires_at),
  CONSTRAINT fk_dining_session_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  CONSTRAINT fk_dining_session_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
ALTER TABLE orders ADD COLUMN dining_session_id BIGINT UNSIGNED NULL AFTER table_id, ADD KEY idx_orders_dining_session (dining_session_id,created_at), ADD CONSTRAINT fk_order_dining_session FOREIGN KEY (dining_session_id) REFERENCES dining_sessions(id) ON DELETE RESTRICT;
