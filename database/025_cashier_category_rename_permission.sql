ALTER TABLE users
  ADD COLUMN category_rename_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER category_delete_enabled;
