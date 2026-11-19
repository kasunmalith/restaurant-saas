# Phase 1 — Existing Database Audit

**Inspection date:** 2026-10-17 (Asia/Colombo)  
**Method:** Read-only PDO metadata queries against the database configured by the existing Restaurant SaaS .env, using SELECT VERSION(), SHOW TABLES, INFORMATION_SCHEMA.COLUMNS, and INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS. No existing row values were queried for this audit.  
**Database server:** MySQL 8.4.7  
**Database:** restaurant_saas

## Existing tables found before inventory migrations

The database contained 31 tables:

- dining_sessions
- login_attempts
- menu_availability_history
- menu_categories
- menu_item_options
- menu_items
- online_customer_accounts
- online_customer_addresses
- online_customer_password_resets
- order_items
- order_status_history
- orders
- pos_bill_edit_audit
- pos_delivery_status_history
- pos_invoice_items
- pos_invoice_tips
- pos_invoices
- pos_kot_items
- pos_kots
- pos_payments
- repair_archive_order_items_20260923
- repair_archive_subscriptions_20260923
- repair_archive_users_20260923
- restaurant_customers
- restaurant_menu_visits
- restaurant_pos_settings
- restaurant_tables
- restaurants
- subscription_payments
- subscriptions
- users

No existing inventory, recipe, supplier, stock, food-cost, or profit tables were present.

## Relevant live schema

The following are the actual column names/types verified in the live local database. Monetary values use DECIMAL(12,2) unless noted.

### Restaurant and users

- restaurants: id, name, slug, description, contact_email, contact_phone, address, logo_path, banner_path, brand_color, timezone, service_charge_percent, delivery_minimum, is_accepting_orders, online_ordering_enabled, is_active, created_at, menu_layout, menu_background_path.
- users: id, nullable restaurant_id, role (SUPER_ADMIN, RESTAURANT_ADMIN, CASHIER), name, unique email, password_hash, is_active, menu/category permission columns, cashier_dashboard_menu_permissions, created_at.

### Menu

- menu_categories: id, restaurant_id, name, sort_order, image_path, bottom_image_path.
- menu_items: id, restaurant_id, category_id, name, description, price, is_portioned, half_price, full_price, image_path, is_available, is_active.
- menu_item_options: id, restaurant_id, menu_item_id, name, price, sort_order, is_default.

### Tables, orders, and kitchen

- restaurant_tables: id, restaurant_id, table_number, token_hash, token_cipher, is_active.
- orders: id, restaurant_id, table_id, dining_session_id, customer_id, order_number, special_request, total, status, idempotency_key, source, order_type, customer/delivery fields, online_payment_method, packaging_charge, delivery_charge, guest_count, created_by, created_at, updated_at, delivery_status.
- order_items: id, order_id, nullable menu_item_id, item_name, portion, unit_price, quantity, subtotal, special_instruction.
- order_status_history: id, restaurant_id, order_id, nullable user_id, previous_status, new_status, created_at.
- pos_kots: id, restaurant_id, order_id, kot_number, created_by, created_at.
- pos_kot_items: id, kot_id, nullable order_item_id, item_name, portion, quantity, action (ADDED/CANCELLED), special_instruction. There is no KOT-item timestamp column; use the parent KOT created_at.

### Customers and online accounts

- restaurant_customers: id, restaurant_id, name, phone, address, origin, created_at, updated_at.
- online_customer_accounts: id, restaurant_id, customer_id, email, password_hash, created_at, updated_at.
- online_customer_addresses: id, restaurant_id, customer_id, label, address, address_hash, is_default, created_at, updated_at.
- online_customer_password_resets: id, restaurant_id, account_id, temp_password_hash, attempts, requested_ip_hash, created_at, sent_at, expires_at, used_at.

### POS invoices and payments

- pos_invoices: id, restaurant_id, order_id, invoice_number, subtotal, discount, service_charge, tax, packaging_charge, delivery_charge, total, paid_total, payment_status, generated_by, created_at, updated_at.
- pos_invoice_items: id, invoice_id, nullable order_item_id, nullable menu_item_id, item_name, portion, quantity, unit_price, discount, line_total, special_instruction.
- pos_payments: id, restaurant_id, invoice_id, method, amount, amount_received, reference, notes, nullable idempotency_key, recorded_by, created_at.
- pos_invoice_tips: tenant, invoice, amount, actor, and timestamps.

## Relationships and tenant safety observations

Foreign keys exist from the principal operational tables to their restaurant/order/menu/user parents. Examples include orders.restaurant_id to restaurants.id, order_items.order_id to orders.id, pos_kots.order_id to orders.id, pos_invoices.order_id to orders.id, and pos_payments.invoice_id to pos_invoices.id.

Important limits:

- Many foreign keys are single-column IDs, not composite (restaurant_id, id) keys. Every new portal query must therefore apply an explicit authenticated restaurant_id predicate and validate referenced SaaS IDs against that restaurant.
- order_items has no restaurant_id; tenant ownership must be reached through orders.
- KOT lines are quantity/action snapshots, not a complete kitchen-state timeline. Cancellation does not prove ingredients were unused.
- Historical order lines preserve item/portion labels and price snapshots; exact option identity is not always stored.

## Drift from the repository's consolidated schema

1. Live orders.delivery_status exists as ENUM('NONE','READY_FOR_DELIVERY','OUT_FOR_DELIVERY','DELIVERED') NOT NULL; it is absent from the CREATE TABLE orders statement in database/schema.sql. database/migrate-pos-billing.php adds it.
2. Live order_items.menu_item_id is nullable and its fk_line_menu uses ON DELETE SET NULL. The consolidated database/schema.sql defines it non-null with a default restrictive reference. database/006_menu_item_delete.sql changes this behavior.
3. Live schema includes the three repair_archive_*_20260923 tables, which are not part of the normal application schema.

The live metadata query completed successfully after the Phase 1 portal directory was created. The audit was captured before applying portal migrations. The portal's only database changes in Phase 1 are new inventory_* objects; no pre-existing table was altered.
