CREATE TABLE IF NOT EXISTS inventory_menu_mapping_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    normalized_label VARCHAR(100) NOT NULL,
    observed_label VARCHAR(100) NOT NULL,
    target_mapping_key VARCHAR(100) NOT NULL,
    target_mapping_type ENUM('BASE','OPTION','PORTION') NOT NULL,
    target_mapping_label VARCHAR(100) NOT NULL,
    menu_item_option_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_menu_mapping_override (restaurant_id, menu_item_id, normalized_label),
    KEY ix_inventory_menu_override_target (restaurant_id, menu_item_id, target_mapping_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
