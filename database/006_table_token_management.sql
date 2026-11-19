USE restaurant_saas;
ALTER TABLE restaurant_tables ADD COLUMN token_cipher TEXT NULL AFTER token_hash;
ALTER TABLE restaurant_tables ADD UNIQUE KEY uq_restaurant_table_number (restaurant_id,table_number);
