ALTER TABLE users
  ADD COLUMN menu_item_delete_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER menu_item_edit_enabled;
