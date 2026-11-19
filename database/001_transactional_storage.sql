-- Required for transactional order creation and concurrent cashier updates.
ALTER TABLE restaurants ENGINE=InnoDB;
ALTER TABLE users ENGINE=InnoDB;
ALTER TABLE subscriptions ENGINE=InnoDB;
ALTER TABLE menu_categories ENGINE=InnoDB;
ALTER TABLE menu_items ENGINE=InnoDB;
ALTER TABLE restaurant_tables ENGINE=InnoDB;
ALTER TABLE orders ENGINE=InnoDB;
ALTER TABLE order_items ENGINE=InnoDB;
