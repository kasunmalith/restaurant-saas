-- Preserve historical order lines when a menu item is permanently deleted.
ALTER TABLE order_items
    DROP FOREIGN KEY fk_line_menu;

ALTER TABLE order_items
    MODIFY menu_item_id BIGINT UNSIGNED NULL;

ALTER TABLE order_items
    ADD CONSTRAINT fk_line_menu
        FOREIGN KEY (menu_item_id) REFERENCES menu_items(id)
        ON DELETE SET NULL;
