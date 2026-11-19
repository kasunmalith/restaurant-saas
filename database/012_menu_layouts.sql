-- Additive migration: existing menus remain Theme 1. Run once on the existing database.
ALTER TABLE restaurants ADD COLUMN menu_layout TINYINT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE menu_categories ADD COLUMN image_path VARCHAR(255) NULL,
    ADD COLUMN bottom_image_path VARCHAR(255) NULL;
