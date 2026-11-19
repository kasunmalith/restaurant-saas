-- Restaurant-level POS bill layout. Existing restaurants retain the current layout.
ALTER TABLE restaurant_pos_settings
    ADD COLUMN receipt_template TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER receipt_width_mm;
