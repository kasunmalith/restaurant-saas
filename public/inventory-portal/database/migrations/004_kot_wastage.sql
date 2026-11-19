CREATE TABLE IF NOT EXISTS inventory_kot_worker_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    bootstrap_max_kot_item_id BIGINT UNSIGNED NOT NULL,
    bootstrapped_at DATETIME NOT NULL,
    bootstrapped_by VARCHAR(80) NOT NULL DEFAULT 'CLI',
    PRIMARY KEY (singleton_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_kot_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    kot_item_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NULL,
    menu_item_id BIGINT UNSIGNED NULL,
    event_action ENUM('ADDED','CANCELLED') NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    portion_label VARCHAR(100) NULL,
    quantity DECIMAL(18,6) NOT NULL,
    recipe_id BIGINT UNSIGNED NULL,
    event_at DATETIME NOT NULL,
    processing_status ENUM('PROCESSED','REVIEW','IGNORED') NOT NULL,
    review_reason VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_kot_event_source (restaurant_id, kot_item_id),
    KEY ix_inventory_kot_event_order_line (restaurant_id, order_id, order_item_id, event_action, kot_item_id),
    KEY ix_inventory_kot_event_review (restaurant_id, processing_status, event_action, event_at),
    CONSTRAINT fk_inventory_kot_event_recipe FOREIGN KEY (recipe_id, restaurant_id)
      REFERENCES inventory_recipes (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_kot_cancellation_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    cancel_kot_item_id BIGINT UNSIGNED NOT NULL,
    order_id BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NULL,
    cancelled_quantity DECIMAL(18,6) NOT NULL,
    unmatched_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
    status ENUM('PENDING','RESOLVED') NOT NULL DEFAULT 'PENDING',
    resolution ENUM('RETURN_TO_STOCK','WASTAGE') NULL,
    resolution_reason VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_kot_cancel_review (restaurant_id, cancel_kot_item_id),
    UNIQUE KEY uq_inventory_kot_review_tenant (id, restaurant_id),
    KEY ix_inventory_kot_review_status (restaurant_id, status, created_at),
    CONSTRAINT fk_inventory_kot_review_event FOREIGN KEY (restaurant_id, cancel_kot_item_id)
      REFERENCES inventory_kot_events (restaurant_id, kot_item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_kot_review_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    review_id BIGINT UNSIGNED NOT NULL,
    source_kot_item_id BIGINT UNSIGNED NOT NULL,
    recipe_id BIGINT UNSIGNED NOT NULL,
    portion_quantity DECIMAL(18,6) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_kot_review_source (restaurant_id, review_id, source_kot_item_id),
    KEY ix_inventory_kot_review_allocation_source (restaurant_id, source_kot_item_id),
    CONSTRAINT fk_inventory_kot_alloc_review FOREIGN KEY (review_id, restaurant_id)
      REFERENCES inventory_kot_cancellation_reviews (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_kot_alloc_added_event FOREIGN KEY (restaurant_id, source_kot_item_id)
      REFERENCES inventory_kot_events (restaurant_id, kot_item_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_kot_alloc_recipe FOREIGN KEY (recipe_id, restaurant_id)
      REFERENCES inventory_recipes (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_wastage (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    record_type ENUM('MANUAL','KOT_CANCELLATION') NOT NULL,
    reason_code ENUM('PREPARATION_WASTE','EXPIRED','DAMAGED','SPOILED','KOT_CANCELLATION','OTHER') NOT NULL,
    source_kot_item_id BIGINT UNSIGNED NULL,
    unmatched_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
    notes VARCHAR(1000) NULL,
    total_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_wastage_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_wastage_kot_cancel (restaurant_id, source_kot_item_id),
    KEY ix_inventory_wastage_date (restaurant_id, created_at, reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_wastage_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    wastage_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,6) NOT NULL,
    quantity_base DECIMAL(18,6) NOT NULL,
    cost_amount DECIMAL(18,6) NOT NULL,
    movement_id BIGINT UNSIGNED NULL,
    source_movement_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_inventory_wastage_item (restaurant_id, wastage_id, id),
    CONSTRAINT fk_inventory_wastage_line_header FOREIGN KEY (wastage_id, restaurant_id)
      REFERENCES inventory_wastage (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_wastage_line_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_wastage_line_unit FOREIGN KEY (unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_wastage_line_movement FOREIGN KEY (movement_id, restaurant_id)
      REFERENCES inventory_stock_movements (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_wastage_line_source_movement FOREIGN KEY (source_movement_id, restaurant_id)
      REFERENCES inventory_stock_movements (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
