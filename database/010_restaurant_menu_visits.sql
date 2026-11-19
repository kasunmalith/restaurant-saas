USE restaurant_saas;

CREATE TABLE IF NOT EXISTS restaurant_menu_visits (
  restaurant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  visit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_visited_at DATETIME NULL,
  CONSTRAINT fk_restaurant_menu_visit_restaurant
    FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE
) ENGINE=InnoDB;
