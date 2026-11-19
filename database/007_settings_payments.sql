USE restaurant_saas;
ALTER TABLE restaurants
  ADD COLUMN contact_email VARCHAR(190) NULL AFTER description,
  ADD COLUMN contact_phone VARCHAR(40) NULL AFTER contact_email,
  ADD COLUMN address TEXT NULL AFTER contact_phone,
  ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Colombo' AFTER brand_color,
  ADD COLUMN is_accepting_orders TINYINT(1) NOT NULL DEFAULT 1 AFTER timezone;
CREATE TABLE subscription_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  restaurant_id BIGINT UNSIGNED NOT NULL,
  subscription_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'LKR',
  paid_on DATE NOT NULL,
  method VARCHAR(60) NULL,
  reference VARCHAR(120) NULL,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
  FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_payment_restaurant_date (restaurant_id,paid_on)
);
