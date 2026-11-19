USE restaurant_saas;

ALTER TABLE menu_items
  ADD COLUMN is_portioned TINYINT(1) NOT NULL DEFAULT 0 AFTER price,
  ADD COLUMN half_price DECIMAL(12,2) NULL AFTER is_portioned,
  ADD COLUMN full_price DECIMAL(12,2) NULL AFTER half_price;

ALTER TABLE order_items
  ADD COLUMN portion ENUM('HALF','FULL') NULL AFTER item_name;
