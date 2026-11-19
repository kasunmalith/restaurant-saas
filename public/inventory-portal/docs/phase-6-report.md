# Phase 6 Report — Reports and Final Hardening

**Completed:** 2026-10-17 (Asia/Colombo)  
**Status:** Implemented and smoke-tested locally.

## Delivered

- Dashboard metrics use actual inventory and POS data: stock value, purchases, tracked food cost, gross profit when complete, wastage, low-stock items, and KOT worker state.
- Profit Reports support today, yesterday, current week, current month, and custom ranges up to 366 days, interpreted in Asia/Colombo and queried in UTC.
- Sales revenue is fully paid POS invoice subtotal less discount. Rejected/cancelled orders, unpaid/partially paid invoices, and subscription payments are excluded from sales revenue.
- Gross profit is withheld if invoice items are absent, KOT deltas are unprocessed or unresolved, order quantities do not reconcile to KOT deltas, or there is no successfully tracked ADD event for a non-empty invoice. This avoids presenting incomplete material cost as profit.
- Tracked cost comes from KOT stock movements valued at the inventory weighted-average cost, less recorded KOT returns. Food Costing and menu rankings are current-recipe/current-cost estimates, not historical invoice costs.
- Payment-method totals, supplier/material purchase history, cost trends, stock valuation, wastage by reason/material, and current recipe gross-profit/margin rankings are shown separately.
- Revenue and cost labels distinguish service charge, tax, delivery, packaging, and tips from food revenue. Wastage is shown as a breakdown of material cost, not subtracted a second time. No operating expenses are collected; figures are not net profit.
- Added rate-limited portal login state, authorization/CSRF/session protections, CLI grant/revoke commands, review/retry for KOT mapping issues, and separate installation/security/worker/schema documentation.

## Tests and local checks

- Phases 2–5 transactional tests passed; Phase 1 positive/negative login and session checks are recorded in `phase-1-report.md`.
- PHP lint passed for every portal PHP file; JavaScript `node --check` passed.
- Local HTTP smoke checks returned 200 for the login, protected route's login response, and stylesheet.
- Migrations 001–006 are recorded. There are 21 `inventory_*` tables, zero active portal access grants, and the KOT worker remains unbootstrapped.

## Limitations

- POS invoice/order editing and refunds are not integrated as inventory reversal events. Cancelled/rejected order statuses are excluded from sales, but a refund that leaves the order/invoice marked paid is not independently detected.
- Online orders without corresponding KOT ADD records do not deduct stock.
- Production deployment, production migration, scheduled worker, SMTP/payment-provider behavior, and live hosting have not been tested or performed.
