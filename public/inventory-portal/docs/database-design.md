# Database Design

## Reused read-only Restaurant SaaS tables

The portal reads tenant/account identity from `restaurants` and `users`; menu definitions from `menu_categories`, `menu_items`, and `menu_item_options`; table/order data as needed from `restaurant_tables`, `orders`, and `order_items`; kitchen deltas from `pos_kots` and `pos_kot_items`; and sales/payment summaries from `pos_invoices`, `pos_invoice_items`, and `pos_payments`. It does not duplicate those business records.

All new inventory records are tenant scoped by `restaurant_id`. Cross-system references to SaaS IDs are validated with that tenant. Existing SaaS tables are not altered and are not written by portal code.

## New tables (22)

### Portal access and migrations

- `inventory_schema_migrations` — applied migration tracking.
- `inventory_portal_access` — explicit tenant/user role grants.
- `inventory_portal_settings` — per-restaurant enable state, separate password hash, and updating SaaS Owner ID.
- `inventory_login_attempts` — portal login throttling state.

### Inventory and purchasing

- `inventory_units` — base units, dimensions, conversions, precision.
- `inventory_materials` — raw materials, reorder levels, active state.
- `inventory_suppliers` — tenant suppliers.
- `inventory_purchases`, `inventory_purchase_items` — draft/received purchase records.
- `inventory_stock_balances` — transactionally updated quantity/value/WAC cache.
- `inventory_stock_movements` — signed append-only audit ledger and idempotency keys.

### Recipes and menu mapping

- `inventory_recipes` — effective-dated recipe versions for menu mappings.
- `inventory_recipe_ingredients` — tenant-validated recipe material lines.
- `inventory_menu_mapping_overrides` — explicit mapping from ambiguous historical option text to a current menu mapping.

### KOT, wastage, and counts

- `inventory_kot_worker_state` — one-time KOT history watermark.
- `inventory_kot_events` — immutable-source snapshots plus portal processing/review state.
- `inventory_kot_cancellation_reviews`, `inventory_kot_review_allocations` — staff decisions and tracked cancellation allocation.
- `inventory_wastage`, `inventory_wastage_items` — manual waste and KOT cancellation classification.
- `inventory_stock_counts`, `inventory_stock_count_items` — count snapshots, physical values, variances, posting state.

## Stock and cost model

`inventory_stock_movements` is the ledger. Quantity is signed and every posted movement has an idempotency key. `inventory_stock_balances` is a performance/cache row updated in the same transaction. Receipts update weighted-average cost; issues use the current weighted average and retain the last known average through zero/negative stock. Recipe food cost is quantity per yield multiplied by current average cost.

## Migration order

1. `001_portal_access.sql`
2. `002_inventory_core.sql`
3. `003_recipes.sql`
4. `004_kot_wastage.sql`
5. `005_stock_counts.sql`
6. `006_kot_mapping_overrides.sql`
7. `007_tenant_portal_settings.sql`

The CLI runner applies these in sorted order. All current migrations create only new `inventory_*` tables. No existing table alterations are required.
