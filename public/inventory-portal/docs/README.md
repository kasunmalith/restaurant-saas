# Inventory & Profit Management Portal

A separate, tenant-scoped inventory portal for Restaurant SaaS. It reuses the existing restaurant, users, menu, order, KOT, invoice, and payment data as read-only sources. Inventory-owned records live in new `inventory_*` tables.

## Local URL

`http://localhost/restaurant-saas/inventory-portal/`

## Main modules

- Dashboard
- Raw Materials and Units
- Suppliers and Purchases
- Stock Ledger and Stock Count
- Recipes and Food Costing
- Wastage and KOT Review
- Profit Reports

## Roles

- `OWNER`: view, operate, manage access/settings, and view reports.
- `INVENTORY_MANAGER`: view, operate, manage settings, and view reports; cannot grant portal access.
- `ACCOUNTANT`: read-only inventory and reports.

The SaaS Owner enables each restaurant in the separate **Inventory & Profit** section and can choose a separate portal password of at least 6 characters (maximum 72 bytes), or leave the field blank to generate a random one. This grants the active Restaurant Manager the initial `OWNER` role. Other users require an individual active portal grant; all authorized accounts for that restaurant use its tenant-level portal password. The normal SaaS password is unchanged. See [installation.md](installation.md) for setup.

## Local setup

1. Keep the existing Restaurant SaaS `.env` and source files unchanged.
2. Run the portal migrations from CLI using `database/migrate.php`.
3. From the SaaS Owner dashboard, select a restaurant, optionally enter a password (minimum 6 characters), and choose **Enable Portal**. Leave the field blank to generate a random password.
4. Sign in at the local URL with the displayed Manager email and the password shown after enabling.
5. Create units/materials, enter opening stock or receive purchases, then configure recipes.
6. Only after setup, initialize and schedule the KOT worker. See [worker-scheduling.md](worker-scheduling.md).

No inventory sample data is seeded. The local portal is disabled for all restaurants until the SaaS Owner enables one. The KOT worker has not been initialized.

## Documentation

- [Database audit](database-audit.md)
- [Database design](database-design.md)
- [Installation and access](installation.md)
- [Worker scheduling](worker-scheduling.md)
- [Security review](security.md)
- [Final report](final-report.md)
- Phase reports: `phase-1-report.md` through `phase-6-report.md`

All times stored by the portal are UTC unless a report filter is explicitly shown in Asia/Colombo. Software credit in the UI links to Energetic Edge (Pvt) Ltd.
