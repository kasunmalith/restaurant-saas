ALTER TABLE restaurants
  ADD COLUMN online_ordering_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_accepting_orders;
