ALTER TABLE users
  ADD COLUMN menu_item_add_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
  ADD COLUMN menu_item_edit_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER menu_item_add_enabled;
