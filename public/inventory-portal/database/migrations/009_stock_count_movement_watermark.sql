ALTER TABLE inventory_stock_count_items
    ADD COLUMN snapshot_movement_id BIGINT UNSIGNED NULL AFTER book_average_cost;
