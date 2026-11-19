# Phase 2 Report — Materials, Units, Suppliers, Purchasing, and Opening Stock

**Completed:** 2026-10-17 01:43 Asia/Colombo  
**Status:** Implemented and focused tests passed.

## Delivered

- Tenant-owned units with one base unit per measurement dimension and explicit conversion factors.
- Raw-material create/edit/activate/deactivate controls, SKU, reorder level, notes, and immutable base-unit choice.
- Supplier create/edit/activate/deactivate controls.
- Draft purchases with lines, unit conversion, validated supplier/material/unit ownership, and draft cancellation.
- Stock changes only when a draft purchase is received; received purchases cannot be edited or received again.
- Opening stock entry with a one-use idempotency key.
- Append-only stock movements and a transactionally updated balance/value/weighted-average cache.
- Current stock and recent movement screens, with search and responsive forms/tables.

## New database tables

- inventory_units
- inventory_materials
- inventory_suppliers
- inventory_purchases
- inventory_purchase_items
- inventory_stock_balances
- inventory_stock_movements

Migration 002_inventory_core.sql was applied to the local database. All new tables are prefixed inventory_. No existing Restaurant SaaS table was altered.

## Costing rules

- Weighted average is calculated from stock value divided by remaining quantity.
- Receipts add purchase value; stock issues use the current average cost and reduce both quantity and inventory value.
- The signed movement quantity and inventory value delta remain the authoritative audit trail; the balance row is a transactionally maintained cache.
- Negative stock is not fabricated or silently blocked; it remains visible and is reported as negative stock. The most recent weighted-average cost is retained at zero/negative quantity and used for shortage issues until receipts restore a positive balance.

## Focused tests

Command: php public/inventory-portal/tests/phase-2.php

Passed:
- Unit conversion.
- Duplicate opening-stock request was idempotent.
- A draft purchase left quantity and weighted-average cost unchanged.
- Receiving a purchase increased stock once.
- Weighted average after a second-cost receipt was correct.
- A received purchase could not be posted again.
- Consumption used the current weighted-average cost.
- Last known weighted-average cost remains available when stock reaches or falls below zero.
- Cross-restaurant unit use was rejected by application validation and inventory-side foreign keys.
- All test rows were rolled back.
- PHP lint and JavaScript syntax checks passed.
- Authenticated HTTP smoke checks rendered Dashboard, Raw Materials, Suppliers, Purchases, and Stock successfully; assets returned HTTP 200.

## Boundary confirmation

All implementation files remain inside public/inventory-portal/. Existing Restaurant SaaS source/configuration files were not modified. Existing Restaurant SaaS tables were not changed.
