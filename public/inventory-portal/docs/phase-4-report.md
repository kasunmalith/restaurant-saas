# Phase 4 Report — KOT Stock Consumption and Wastage

**Completed:** 2026-10-17 (Asia/Colombo)  
**Status:** Implemented and tested locally.

## Delivered

- Added a CLI-only KOT worker. It reads the existing `pos_kots`, `pos_kot_items`, `orders`, and `order_items` tables; it never writes them.
- The one-time bootstrap records the highest existing KOT item ID as a watermark so historical kitchen activity is not replayed. The worker has deliberately not been bootstrapped yet; materials and recipes should be ready first.
- New KOT `ADDED` quantity deltas resolve to the effective recipe at the KOT creation time and create idempotent, weighted-average-cost stock movements.
- KOT `CANCELLED` deltas enter a staff review queue. The portal allocates cancellation quantities only to tracked additions. Returning ingredients is blocked unless the entire cancellation is safely matched; preparation wastage is reclassified without deducting stock twice.
- Manual wastage records create stock-ledger deductions with a retry key.
- Added an inventory-owned label override and retry flow for historical KOT labels that are unknown or ambiguous. Overrides are limited to the same restaurant and menu item, and must target a currently existing menu mapping.

## New tables

- `inventory_kot_worker_state`
- `inventory_kot_events`
- `inventory_kot_cancellation_reviews`
- `inventory_kot_review_allocations`
- `inventory_wastage`
- `inventory_wastage_items`
- `inventory_menu_mapping_overrides` (added during final hardening)

Migrations `004_kot_wastage.sql` and `006_kot_mapping_overrides.sql` are applied locally.

## Tests

`php public/inventory-portal/tests/phase-4.php` passed bootstrap watermark, ADD consumption, idempotent replay, cancellation allocation, proportional stock return, wastage reclassification, manual-wastage retry, explicit mapping override/retry, and cross-tenant rejection. Test rows were rolled back. No original KOT/order/menu rows were changed.

## Limitations

- Stock is deducted when a KOT `ADDED` delta is observed, not merely when an online order is placed or accepted.
- KOT data lacks a dedicated item timestamp; the parent KOT `created_at` is used.
- Missing or ambiguous mappings/recipes remain visible for operator action. If a cancellation was reviewed before its original ADD was successfully mapped, its unmatched allocation is not silently credited back; reconcile it through review/count procedures.
- Historical KOT items at or below the bootstrap watermark are skipped by design.
