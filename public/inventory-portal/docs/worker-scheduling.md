# KOT Worker Scheduling

The worker reads existing KOT/order/menu data and writes only portal-owned stock events and movements. It runs only under CLI; HTTP requests are rejected. A MySQL named lock prevents overlapping invocations, and per-KOT idempotency prevents repeat deductions.

Before initialization, use the read-only portal Automation Readiness page and the CLI dry-run below. The preview does not create events, movements, balances, reviews, or worker state. If no bootstrap exists, it previews existing unprocessed KOT history only as a diagnostic; a later bootstrap will intentionally skip that history.

`C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\restaurant-saas\public\inventory-portal\jobs\process-kot-stock.php --dry-run`

Migration `008_worker_runs.sql` adds a portal-only successful-run history table. Normal worker runs record a completion timestamp there; dry-runs do not. The portal cannot verify Windows Task Scheduler or cron from its database, so the Automation status remains **Unknown** unless scheduling is checked externally.

## Safe initialization order

1. Create active units and raw materials.
2. Enter correct opening stock or receive real purchases.
3. Create and verify effective-dated recipes for every menu item, size, and option.
4. Resolve recipe/unit/costing gaps.
5. Initialize exactly once from a command prompt:

   `C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\restaurant-saas\public\inventory-portal\jobs\process-kot-stock.php --bootstrap`

   Bootstrap records the current maximum KOT item ID. All KOT history at or below that ID is intentionally skipped. Do not bootstrap early or repeat it to reset the worker.
6. Run a normal processing pass:

   `C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\restaurant-saas\public\inventory-portal\jobs\process-kot-stock.php`

7. Review the Wastage & KOT Review page after each run. Fix missing recipes/mappings and retry reviewed additions; resolve cancellations with staff judgment.

The current local worker has **not** been bootstrapped. No live or historical KOT rows have been consumed by this portal.

## Scheduling

On Windows, create a Task Scheduler job to run the normal command every minute (or at an interval appropriate for kitchen operations). Set the working directory to the Restaurant SaaS project root, run under a service account with access to PHP and the existing `.env`, and capture stdout/stderr in a protected log. The database lock safely skips a concurrent run.

On another host, use that host's PHP CLI and scheduler. Confirm system timezone, file permissions, database connectivity, log rotation, and backup procedures first. Do not schedule `--bootstrap`.

## Event semantics

- Stock is deducted for each KOT `ADDED` delta using the recipe effective at the parent KOT `created_at` timestamp.
- KOT `CANCELLED` does not automatically restore ingredients. It creates a review; return is allowed only when the cancelled amount is fully allocated to tracked consumption.
- Unmapped/missing-recipe events remain in `REVIEW`, are excluded from complete gross-profit figures, and can be corrected/retried from the portal.
- Orders that never produce KOT ADD entries have no automatic stock deduction.
