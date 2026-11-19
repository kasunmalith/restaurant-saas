-- Add a separate packaging fee while keeping delivery fees independent.
ALTER TABLE orders
    ADD COLUMN packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER delivery_notes;

ALTER TABLE pos_invoices
    ADD COLUMN packaging_charge DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER tax;
