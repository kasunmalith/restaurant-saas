-- One-time repair for the original MyISAM installation.
-- Preserve identified integration-test leftovers before removing them.
CREATE TABLE repair_archive_users_20260923 LIKE users;
INSERT INTO repair_archive_users_20260923 SELECT * FROM users WHERE id IN (5,6) AND email IN ('qa-owner-94da32eba2827b29@example.test','qa-owner-7060e1e6c431268b@example.test');
CREATE TABLE repair_archive_subscriptions_20260923 LIKE subscriptions;
INSERT INTO repair_archive_subscriptions_20260923 SELECT * FROM subscriptions WHERE (id=3 AND restaurant_id=3) OR (id=4 AND restaurant_id=5);
CREATE TABLE repair_archive_order_items_20260923 LIKE order_items;
INSERT INTO repair_archive_order_items_20260923 SELECT * FROM order_items WHERE order_id=1 AND item_name='French Fries' AND NOT EXISTS(SELECT 1 FROM orders WHERE id=1);
DELETE FROM users WHERE id IN (SELECT id FROM repair_archive_users_20260923);
DELETE FROM subscriptions WHERE id IN (SELECT id FROM repair_archive_subscriptions_20260923);
DELETE FROM order_items WHERE id IN (SELECT id FROM repair_archive_order_items_20260923);
ALTER TABLE users ADD CONSTRAINT fk_users_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;
ALTER TABLE subscriptions ADD CONSTRAINT fk_subscription_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;
ALTER TABLE menu_categories ADD CONSTRAINT fk_category_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;
ALTER TABLE menu_items ADD CONSTRAINT fk_item_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_item_category FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON DELETE RESTRICT;
ALTER TABLE restaurant_tables ADD CONSTRAINT fk_table_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE;
ALTER TABLE orders ADD CONSTRAINT fk_order_restaurant FOREIGN KEY (restaurant_id) REFERENCES restaurants(id) ON DELETE CASCADE, ADD CONSTRAINT fk_order_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id) ON DELETE RESTRICT;
ALTER TABLE order_items ADD CONSTRAINT fk_line_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE, ADD CONSTRAINT fk_line_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE RESTRICT;
