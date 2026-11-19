CREATE TABLE IF NOT EXISTS inventory_recipes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    mapping_key VARCHAR(100) NOT NULL,
    mapping_type ENUM('BASE','OPTION','PORTION') NOT NULL,
    menu_item_option_id BIGINT UNSIGNED NULL,
    mapping_label VARCHAR(100) NOT NULL,
    version INT UNSIGNED NOT NULL,
    effective_from DATETIME NOT NULL,
    effective_to DATETIME NULL,
    yield_quantity DECIMAL(18,6) NOT NULL DEFAULT 1,
    yield_unit_label VARCHAR(40) NOT NULL DEFAULT 'servings',
    notes VARCHAR(1000) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_recipe_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_recipe_version (restaurant_id, menu_item_id, mapping_key, version),
    KEY ix_inventory_recipe_lookup (restaurant_id, menu_item_id, mapping_key, effective_from, effective_to),
    KEY ix_inventory_recipe_option (restaurant_id, menu_item_option_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_recipe_ingredients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,6) NOT NULL,
    quantity_base DECIMAL(18,6) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_recipe_ingredient_tenant (id, restaurant_id),
    KEY ix_inventory_recipe_ingredients (restaurant_id, recipe_id, id),
    CONSTRAINT fk_inventory_recipe_line_recipe FOREIGN KEY (recipe_id, restaurant_id)
      REFERENCES inventory_recipes (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_recipe_line_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_recipe_line_unit FOREIGN KEY (unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
