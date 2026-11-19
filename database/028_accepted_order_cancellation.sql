-- Manager-controlled cancellation for accepted orders. Existing restaurants remain disabled by default.
ALTER TABLE restaurant_pos_settings
    ADD COLUMN accepted_order_cancellation_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER manager_bill_edit_enabled;
