# Security Review

## Controls implemented

- Separate `ENERGETIC_EDGE_INVENTORY` session namespace and path; HttpOnly, SameSite=Lax, Secure on HTTPS, strict session mode, and ID regeneration on login.
- Login identifies an active existing SaaS user by email, then checks the active tenant, an enabled `inventory_portal_settings` row, the restaurant-specific portal password hash, and that user's active `inventory_portal_access` grant. It does not use or change the user's regular SaaS password. Login attempts are throttled in a portal table.
- A CSRF-protected SaaS Owner-only control enables/disables each restaurant and lets the Owner set a tenant password of at least 6 characters and at most 72 bytes, or generate a 128-bit random password. Only the password hash is stored; the plaintext is returned once. Password changes replace the previous tenant password. Enabling grants the active Restaurant Manager the initial `OWNER` role. Other users still need individual active grants and share that restaurant's portal password.
- Disabling a restaurant portal blocks new sign-ins and causes active sessions to fail their next tenant-context check. Existing SaaS account passwords and restaurant business tables are not modified.
- Tenant ID and role are taken from the authenticated portal session; browser-submitted restaurant IDs are not trusted for actions.
- Server-side role checks: `OWNER`, `INVENTORY_MANAGER`, and read-only `ACCOUNTANT`.
- CSRF tokens on mutating forms, prepared PDO statements, output escaping, and validation of referenced menu/material/unit/supplier records against the current tenant.
- CLI-only additional access grant/revoke, migration, and KOT-worker scripts. The web portal has no user self-provisioning.
- Stock movements and KOT processing use transaction boundaries, unique idempotency keys, row locks, and a worker-level MySQL lock.
- Portal SQL migrations and business queries do not alter or write existing Restaurant SaaS business tables.

## Remaining operational risks

- The portal shares the existing database connection configuration and credential scope. Production should use least-privilege database permissions where compatible with existing app setup, HTTPS, protected filesystem permissions, backups, and restricted CLI access.
- Existing SaaS refund/bill-edit audit events are not connected to automatic inventory reversal. Paid invoices with order status `CANCELLED` or `REJECTED` are excluded from revenue, but a refund that leaves a paid status is not independently detected.
- Inventory actions have actor columns where applicable, but no separate general-purpose immutable security event stream has been added.
- KOT-based deductions depend on a scheduled worker and complete recipes. Monitor CLI logs and the review queue; an unbootstrapped or stopped worker does not process stock.
- Security checks here are local code/database review and focused tests, not a third-party penetration test or production-host configuration audit.

## Boundary confirmation

Inventory features and data remain isolated in `public/inventory-portal/` and `inventory_*` tables. SaaS Owner-only UI/API integration in the main app manages the separate portal setting and initial Manager grant; it does not alter existing menu/order/payment business data or Manager passwords. No production server was accessed.
