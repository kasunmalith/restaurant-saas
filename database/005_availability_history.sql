CREATE TABLE IF NOT EXISTS menu_availability_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 restaurant_id BIGINT UNSIGNED NOT NULL,
 menu_item_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NULL,
 previous_available TINYINT NOT NULL,
 new_available TINYINT NOT NULL,
 created_at DATETIME NOT NULL,
 INDEX(restaurant_id,menu_item_id,id),
 FOREIGN KEY(restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE,
 FOREIGN KEY(menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
