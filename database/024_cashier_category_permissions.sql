ALTER TABLE users
  ADD COLUMN category_add_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER menu_item_delete_enabled,
  ADD COLUMN category_delete_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER category_add_enabled;
