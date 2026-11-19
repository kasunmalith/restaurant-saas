USE restaurant_saas;
ALTER TABLE menu_items ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_available;
CREATE INDEX idx_menu_restaurant_active ON menu_items(restaurant_id,is_active,category_id);
