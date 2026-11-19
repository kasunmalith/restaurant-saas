-- Keep order numbers unique per restaurant while using the shorter 8-character suffix.
ALTER TABLE orders
    ADD UNIQUE KEY uq_orders_restaurant_order_number (restaurant_id, order_number);
