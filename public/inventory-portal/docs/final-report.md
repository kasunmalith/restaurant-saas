# Inventory & Profit Management Portal — Final Report

**Status:** Phases 1–6 implemented and locally verified on 2026-10-17 (Asia/Colombo).  
**Local URL:** `http://localhost/restaurant-saas/inventory-portal/`

## Access-control update — 2026-10-18

The SaaS Owner now has a separate **Inventory & Profit** section to select a restaurant, enable/disable its portal, and set a tenant-specific portal password of at least 6 characters (maximum 72 bytes) or generate a random password. Enabling grants the active Restaurant Manager initial `OWNER` access. The manager email remains the username; this password is separate from the normal SaaS password, which is not changed. Only the password hash is stored and the plaintext is shown once. All users with an active grant for a restaurant share that restaurant's portal password.

Migration `007_tenant_portal_settings.sql` was applied to the localhost database; there are now 22 `inventory_*` tables. The new access flow passed a focused localhost integration test. No production files or database were changed.

## Delivered

- Separate mobile-responsive portal and login/session using Restaurant SaaS users with explicit tenant-scoped portal access roles.
- Units/conversions, raw materials, suppliers, purchases, opening stock, ledger-based stock, and weighted-average costing.
- Effective-dated recipes for menu items and options, current food-cost estimates, gross profit/margin calculations.
- CLI KOT worker with bootstrap watermark, idempotent ADD consumption, cancellation review, controlled option mapping, and manual wastage.
- Stock counts, low-stock detection, inventory valuation, and reports for paid POS sales, tracked ingredient cost, gross profit when complete, payment methods, purchases, costs, and wastage.
- Installation, worker, schema, security, database-audit, and phase documentation.

## New database objects

The initial six migrations created 21 tables; migration 007 adds `inventory_portal_settings`, for 22 total. Migrations 001–007 are applied in the local database. Portal access is disabled by default until configured by the SaaS Owner. The KOT worker has not been bootstrapped.

## Access and worker setup

Enable a restaurant and choose its separate password (or generate one) from the SaaS Owner dashboard; sign in with the active Manager email and that portal password. Grant other verified users individually as described in [installation.md](installation.md). After units, materials, opening stock/purchases, and recipes are fully configured, follow [worker-scheduling.md](worker-scheduling.md) to bootstrap once and schedule the worker.

## Verification

- Phase 2–5 transactional test scripts passed and rolled back all test rows.
- PHP syntax passed for every portal PHP file; JavaScript syntax check passed.
- Local HTTP smoke checks returned HTTP 200 for login, protected-route login response, and portal CSS.
- Migrations 001–007 are recorded; the database contains 22 inventory tables.
- SaaS Owner UI/API integration was added for portal settings and the initial Manager grant. No existing restaurant business tables or Manager passwords were altered; all SQL migrations create new `inventory_*` tables.
- No files or database changes were deployed to production.

## Known limitations

- KOT is the stock trigger; accepted/placed online orders without a KOT ADD event do not deduct stock.
- Existing KOT item timestamps are represented by parent KOT creation times; labels may be ambiguous and require the inventory-side review/mapping UI.
- Cancellations are not automatically treated as unused stock; staff must review and only fully matched tracked consumption can be returned.
- Invoice edits/refunds are not wired to inventory reversal. A paid status with a separate refund not reflected in status can make sales reports overstated.
- Profit is gross profit only, not net profit. Operating expenses, payroll, rent, utilities, and payment settlement reconciliation are not tracked.
- Production deployment, production database migration, hosting configuration, and scheduled worker remain untested and unperformed.
