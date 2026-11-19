ALTER TABLE online_customer_accounts
  ADD COLUMN email VARCHAR(190) NULL AFTER customer_id,
  ADD KEY idx_online_account_email (restaurant_id, email);

CREATE TABLE online_customer_password_resets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  temp_password_hash VARCHAR(255) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  requested_ip_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  KEY idx_customer_reset_account (restaurant_id, account_id, created_at),
  KEY idx_customer_reset_ip (requested_ip_hash, created_at),
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES online_customer_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;
