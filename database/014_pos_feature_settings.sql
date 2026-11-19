-- POS is an operational restaurant feature, not a subscription plan.
-- Safe to run again; existing restaurants remain OFF unless explicitly enabled.
CREATE TABLE IF NOT EXISTS restaurant_pos_settings (
    restaurant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    pos_enabled TINYINT(1) NOT NULL DEFAULT 0,
    manager_bill_edit_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pos_setting_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
    CONSTRAINT fk_pos_setting_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
