-- POS receipt and KOT paper width is shared across restaurant staff.
-- Run once on existing databases; new installations get this from schema.sql.
ALTER TABLE restaurant_pos_settings
    ADD COLUMN receipt_width_mm TINYINT UNSIGNED NOT NULL DEFAULT 80 AFTER manager_bill_edit_enabled;
