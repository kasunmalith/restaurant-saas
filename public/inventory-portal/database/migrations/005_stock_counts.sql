CREATE TABLE IF NOT EXISTS inventory_stock_counts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    count_at DATETIME NOT NULL,
    status ENUM('DRAFT','POSTED') NOT NULL DEFAULT 'DRAFT',
    notes VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    posted_by BIGINT UNSIGNED NULL,
    posted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_stock_count_tenant (id, restaurant_id),
    KEY ix_inventory_stock_count_status (restaurant_id, status, count_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_stock_count_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    count_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    book_quantity_base DECIMAL(18,6) NOT NULL,
    book_average_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    physical_quantity_base DECIMAL(18,6) NULL,
    variance_base DECIMAL(18,6) NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_stock_count_item (restaurant_id, count_id, material_id),
    KEY ix_inventory_stock_count_line (restaurant_id, count_id, id),
    CONSTRAINT fk_inventory_stock_count_line_header FOREIGN KEY (count_id, restaurant_id)
      REFERENCES inventory_stock_counts (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_count_line_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_stock_count_line_unit FOREIGN KEY (unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
