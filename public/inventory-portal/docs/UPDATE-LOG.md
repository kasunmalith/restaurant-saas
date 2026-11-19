# Inventory Portal Change Log

All times are Asia/Colombo. This log tracks only files inside the new Inventory Portal. No live-server deployment has been performed.

## 2026-10-19 12:06 AM +05:30 — Inventory safety hardening (localhost only)

- `app/recipes-service.php`, `app/kot-service.php` — Divide base-unit ingredient quantity by recipe yield before applying each KOT quantity. Reject unknown-cost ingredients into review before writing any stock movement.
- `app/inventory-service.php`, `app/report-service.php`, `pages/profit-reports.php` — Require priced opening/purchase history and positive average cost for known ingredient cost; withhold gross profit when worker/cost coverage is incomplete.
- `app/stock-count-service.php` — Lock and compare current authoritative balances with count snapshots; block stale posting and require a fresh count.
- `database/migrations/009_stock_count_movement_watermark.sql` — Add a portal-only per-line movement watermark so a count is blocked even if intervening movements return quantity to its original value. Migration 009 applied locally; no base table altered.
- `app/automation-service.php`, `pages/automation-readiness.php`, `index.php` — Add tenant-scoped read-only readiness and automation status page. External scheduler status is explicitly Unknown.
- `jobs/process-kot-stock.php`, `database/migrations/008_worker_runs.sql` — Add no-write `--dry-run` preview and record successful normal worker runs in a portal-owned table. Migration 008 applied to localhost; no Restaurant SaaS table was altered. Worker remains uninitialized and unscheduled.
- `tests/hardening.php`, `tests/phase-5.php`, `tests/worker-lock.php` — Rollback-only yield, option, conversion, costing, idempotency, dry-run, negative-stock, and stale-count/round-trip-movement coverage; two-connection named-lock concurrency check. No test business rows remain.
- `docs/worker-scheduling.md`, `docs/UPDATE-LOG.md` — Document the safe dry-run command and local-only migration. No parent project log or original SaaS file was changed because this task's boundary prohibited it.

## 2026-10-18 08:46 PM +05:30 — Owner-selected portal password

- `public/inventory-portal/docs/README.md`, `public/inventory-portal/docs/installation.md`, `public/inventory-portal/docs/security.md`, `public/inventory-portal/docs/final-report.md`, `public/inventory-portal/docs/final-report.txt`, `public/inventory-portal/docs/UPDATE-LOG.md` — Document SaaS Owner-selected tenant passwords with a six-character minimum and optional random generation; no portal source, database, or migration changes in this docs-only portal update. Localhost only; no live deployment.

## 2026-10-18 08:13 PM +05:30 — SaaS Owner-controlled tenant access

- `public/inventory-portal/app/auth.php`, `public/inventory-portal/index.php`, `public/inventory-portal/database/migrations/007_tenant_portal_settings.sql`, `public/inventory-portal/docs/README.md`, `public/inventory-portal/docs/installation.md`, `public/inventory-portal/docs/security.md`, `public/inventory-portal/docs/database-design.md`, `public/inventory-portal/docs/final-report.md`, `public/inventory-portal/docs/final-report.txt`, `public/inventory-portal/docs/UPDATE-LOG.md` — Require the selected restaurant's enabled state and separate password hash at portal login, while retaining active per-user role grants. The SaaS Owner enables/disables each restaurant and generates/rotates its password in the main dashboard; enabling grants the active Manager initial portal Owner access. Normal SaaS passwords are unchanged. Migration 007 applied locally; focused enable/login/rotate/disable checks passed. Main SaaS integration files are tracked in the parent project change log; no live deployment.

## 2026-10-17 01:43 +05:30 — Phase 1 and Phase 2

### Phase 1 — Portal foundation and isolated authentication

Created:
- public/inventory-portal/.htaccess
- public/inventory-portal/app/auth.php
- public/inventory-portal/app/bootstrap.php
- public/inventory-portal/app/database.php
- public/inventory-portal/app/helpers.php
- public/inventory-portal/app/permissions.php
- public/inventory-portal/assets/css/portal.css
- public/inventory-portal/assets/js/portal.js
- public/inventory-portal/bin/grant-access.php
- public/inventory-portal/database/migrate.php
- public/inventory-portal/database/migrations/001_portal_access.sql
- public/inventory-portal/docs/database-audit.md
- public/inventory-portal/docs/phase-1-report.md
- public/inventory-portal/index.php
- public/inventory-portal/login.php
- public/inventory-portal/logout.php
- public/inventory-portal/pages/coming-soon.php
- public/inventory-portal/pages/dashboard.php

Local database migration applied: inventory_schema_migrations, inventory_portal_access, inventory_login_attempts created. No access rows remain; portal access is opt-in. No existing SaaS tables or files were modified. No production deployment.

### Phase 2 — Materials and purchasing foundation

Created:
- public/inventory-portal/app/actions.php
- public/inventory-portal/app/inventory-service.php
- public/inventory-portal/assets/css/modules.css
- public/inventory-portal/database/migrations/002_inventory_core.sql
- public/inventory-portal/pages/materials.php
- public/inventory-portal/pages/purchases.php
- public/inventory-portal/pages/stock.php
- public/inventory-portal/pages/suppliers.php
- public/inventory-portal/tests/phase-2.php
- public/inventory-portal/docs/phase-2-report.md

Updated inside the new portal only:
- public/inventory-portal/app/database.php
- public/inventory-portal/app/actions.php
- public/inventory-portal/app/auth.php
- public/inventory-portal/assets/js/portal.js
- public/inventory-portal/index.php
- public/inventory-portal/pages/dashboard.php
- public/inventory-portal/pages/materials.php
- public/inventory-portal/pages/purchases.php
- public/inventory-portal/pages/suppliers.php

Local database migration applied: inventory_units, inventory_materials, inventory_suppliers, inventory_purchases, inventory_purchase_items, inventory_stock_balances, inventory_stock_movements. Transactional tests passed and rolled back their test rows. No existing SaaS table/file was altered. No production deployment.

## 2026-10-17 01:45 +05:30 — Phase 3

Created or updated inside the portal:
- public/inventory-portal/app/actions.php — save effective-dated recipe versions with tenant-scoped menu/material/unit validation.
- public/inventory-portal/app/inventory-service.php — retain last weighted-average cost at zero/negative stock.
- public/inventory-portal/app/recipes-service.php — safe menu mapping, recipe versioning, ingredient-cost and gross-profit calculations.
- public/inventory-portal/assets/css/recipes.css — responsive recipe-builder and cost-preview styling.
- public/inventory-portal/assets/js/portal.js — dynamic ingredient rows, compatible-unit filtering, and estimated-cost preview.
- public/inventory-portal/database/migrations/003_recipes.sql — recipe and recipe ingredient tables.
- public/inventory-portal/index.php — activate recipe and food-costing pages.
- public/inventory-portal/pages/food-costing.php — item/option food-cost, gross-profit, and margin listing.
- public/inventory-portal/pages/recipes.php — recipe builder and version history.
- public/inventory-portal/tests/phase-3.php — transactional recipe and costing tests.
- public/inventory-portal/docs/phase-2-report.md — clarify shortage costing behavior.
- public/inventory-portal/docs/phase-3-report.md — phase findings, tests, and limitations.

Local migration applied: `003_recipes.sql` created `inventory_recipes` and `inventory_recipe_ingredients`. Phase 2 and Phase 3 test data rolled back. No existing Restaurant SaaS source file/table was altered. No production deployment.

## 2026-10-17 02:09 +05:30 — Phase 4 through Phase 6 hardening and final documentation

Created or updated inside `public/inventory-portal/`:
- `app/actions.php` — controlled KOT review retry and explicit menu-label mapping actions.
- `app/kot-service.php` — safe, idempotent retry of reviewed KOT ADD events; tenant-scoped mapping override persistence.
- `app/recipes-service.php` — exact normalized-label override resolution, validated against current menu mappings.
- `app/report-service.php` — do not treat paid invoices without invoice item rows as complete gross-profit data.
- `pages/wastage.php` — visible review/retry UI for unmapped or recipe-incomplete KOT additions.
- `tests/phase-4.php` — test mapping override, pending retry, and successful stock processing.
- `database/migrations/006_kot_mapping_overrides.sql` — new inventory-side mapping table.
- `docs/phase-4-report.md`, `docs/phase-5-report.md`, `docs/phase-6-report.md`.
- `docs/README.md`, `docs/installation.md`, `docs/worker-scheduling.md`, `docs/database-design.md`, `docs/security.md`, `docs/final-report.md`, `docs/final-report.txt`.
- `docs/UPDATE-LOG.md` — this entry.

Local migration 006 applied. Full PHP lint and JavaScript syntax passed; phase 2–5 tests passed with temporary test data rolled back; local HTTP smoke checks returned 200. Database state: 21 `inventory_*` tables, zero active portal grants, KOT worker unbootstrapped. Existing Restaurant SaaS source files and tables remain unchanged. No live deployment or live database operation was performed.
