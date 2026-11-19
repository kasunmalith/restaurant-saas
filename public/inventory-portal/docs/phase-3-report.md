# Phase 3 Report — Recipes, Menu Mapping, and Food Costing

**Completed:** 2026-10-17, Asia/Colombo  
**Status:** Implemented; focused tests passed.

## Delivered

- Versioned recipes mapped to existing restaurant menu items, legacy Half/Full portions, and arbitrary menu options. Existing SaaS menu records remain read-only.
- Tenant-scoped recipe ingredients with validated unit conversions and active-material checks.
- Effective-dated versions preserve earlier recipes instead of overwriting them.
- Responsive recipe builder supports multiple ingredients, yields, effective times, and a current-cost preview.
- Food Costing shows selling price, current estimated ingredient cost, gross profit, and gross margin. It explicitly excludes operating expenses and is not net profit.
- Historical option/portion resolution rejects duplicate textual matches instead of guessing.
- Weighted-average cost is retained when quantity reaches or falls below zero so shortage issues continue using the last known average until new stock arrives.

## New database tables

- inventory_recipes
- inventory_recipe_ingredients

Migration `003_recipes.sql` was applied locally. No existing SaaS table was altered.

## Focused tests

Command: `php public/inventory-portal/tests/phase-3.php`

Passed base and option mapping, recipe version history, unit conversion and weighted-average ingredient cost, zero-selling-price handling, ambiguous-label rejection, inactive-material rejection, and cross-tenant checks. Test inventory rows and recipes were rolled back. Phase 2 tests, PHP lint, JavaScript syntax, and rerun-safe migrations also passed.

## Limitations

- Existing historical order lines may not contain a stable option ID. When their saved label is absent or ambiguous, automatic costing/consumption must remain flagged for review.
- Food Costing is current-recipe/current-material-cost estimation, not historical invoice COGS and not net profit.

## Boundary confirmation

All changes remain inside `public/inventory-portal/`. Existing Restaurant SaaS source files and business tables were not modified.
