# Phase 5 Report — Stock Counts

**Completed:** 2026-10-17 (Asia/Colombo)  
**Status:** Implemented and tested locally.

## Delivered

- A stock count creates a locked snapshot of book quantity and weighted-average cost for each active material.
- Staff can save physical quantities and review positive/negative variances before posting.
- Posting creates append-only `COUNT_ADJUSTMENT` ledger entries and is atomic/idempotent; a count cannot be posted twice.
- Movements recorded after the snapshot are preserved. The count reconciles the snapshot quantity rather than overwriting later purchases, consumption, or wastage.
- Stock view includes searchable movement history and running ledger quantity.

## New tables

- `inventory_stock_counts`
- `inventory_stock_count_items`

Migration `005_stock_counts.sql` is applied locally.

## Tests

`php public/inventory-portal/tests/phase-5.php` passed snapshot capture, positive and negative variances, preservation of later movements, one-time posting, low-stock detection, and tenant isolation. All temporary records were rolled back.

## Limitation

Counts are per active material and use the material's base unit. A posted count is an audited ledger event; corrections should be made through a new count or explicit adjustment, not by editing the posted record.
