CREATE TABLE IF NOT EXISTS inventory_units (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    symbol VARCHAR(24) NOT NULL,
    dimension VARCHAR(32) NOT NULL,
    base_unit_id BIGINT UNSIGNED NULL,
    conversion_to_base DECIMAL(18,9) NOT NULL DEFAULT 1,
    is_base TINYINT(1) NOT NULL DEFAULT 0,
    decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 3,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_unit_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_unit_symbol (restaurant_id, symbol),
    KEY ix_inventory_unit_dimension (restaurant_id, dimension, is_active),
    CONSTRAINT fk_inventory_unit_base FOREIGN KEY (base_unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_materials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(80) NULL,
    base_unit_id BIGINT UNSIGNED NOT NULL,
    minimum_quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
    notes VARCHAR(1000) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_material_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_material_sku (restaurant_id, sku),
    KEY ix_inventory_material_name (restaurant_id, name, is_active),
    CONSTRAINT fk_inventory_material_base_unit FOREIGN KEY (base_unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_suppliers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(700) NULL,
    notes VARCHAR(1000) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_supplier_tenant (id, restaurant_id),
    KEY ix_inventory_supplier_name (restaurant_id, name, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_purchases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL,
    reference_no VARCHAR(120) NULL,
    purchase_date DATE NOT NULL,
    received_at DATETIME NULL,
    status ENUM('DRAFT','RECEIVED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
    notes VARCHAR(1000) NULL,
    created_by BIGINT UNSIGNED NULL,
    received_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_purchase_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_purchase_reference (restaurant_id, reference_no),
    KEY ix_inventory_purchase_date (restaurant_id, purchase_date, status),
    CONSTRAINT fk_inventory_purchase_supplier FOREIGN KEY (supplier_id, restaurant_id)
      REFERENCES inventory_suppliers (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_purchase_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    purchase_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    quantity DECIMAL(18,6) NOT NULL,
    quantity_base DECIMAL(18,6) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL,
    line_total DECIMAL(18,6) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_purchase_item_tenant (id, restaurant_id),
    KEY ix_inventory_purchase_item (restaurant_id, purchase_id),
    CONSTRAINT fk_inventory_purchase_item_purchase FOREIGN KEY (purchase_id, restaurant_id)
      REFERENCES inventory_purchases (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_purchase_item_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT,
    CONSTRAINT fk_inventory_purchase_item_unit FOREIGN KEY (unit_id, restaurant_id)
      REFERENCES inventory_units (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_stock_balances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    quantity_base DECIMAL(18,6) NOT NULL DEFAULT 0,
    inventory_value DECIMAL(18,6) NOT NULL DEFAULT 0,
    average_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_balance_material (restaurant_id, material_id),
    KEY ix_inventory_balance_tenant (restaurant_id, quantity_base),
    CONSTRAINT fk_inventory_balance_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inventory_stock_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restaurant_id BIGINT UNSIGNED NOT NULL,
    material_id BIGINT UNSIGNED NOT NULL,
    movement_type ENUM('OPENING_STOCK','PURCHASE','CONSUMPTION','WASTAGE','COUNT_ADJUSTMENT','REVERSAL','RETURN') NOT NULL,
    quantity_base DECIMAL(18,6) NOT NULL,
    unit_cost DECIMAL(18,6) NOT NULL DEFAULT 0,
    cost_amount DECIMAL(18,6) NOT NULL DEFAULT 0,
    inventory_value_delta DECIMAL(18,6) NOT NULL DEFAULT 0,
    source_type VARCHAR(40) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    source_line_id BIGINT UNSIGNED NULL,
    idempotency_key CHAR(64) NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    effective_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inventory_movement_tenant (id, restaurant_id),
    UNIQUE KEY uq_inventory_movement_idempotency (restaurant_id, idempotency_key),
    KEY ix_inventory_movement_material_date (restaurant_id, material_id, effective_at, id),
    KEY ix_inventory_movement_source (restaurant_id, source_type, source_id, source_line_id),
    CONSTRAINT fk_inventory_movement_material FOREIGN KEY (material_id, restaurant_id)
      REFERENCES inventory_materials (id, restaurant_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
