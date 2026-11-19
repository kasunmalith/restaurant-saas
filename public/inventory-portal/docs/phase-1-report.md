# Phase 1 Report — Foundation, Database Verification, and Portal Authentication

**Date:** 2026-10-17 (Asia/Colombo)  
**Status:** Implemented; focused tests recorded below.

## Delivered

- Created the separate web portal at public/inventory-portal/ so the existing root rewrite serves /restaurant-saas/inventory-portal/ without changing any existing Apache or application file.
- Added a portal-only PDO connection that reads the existing .env without copying credentials or exposing them.
- Added the ENERGETIC_EDGE_INVENTORY session namespace, scoped cookie path, HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode, CSRF, session ID regeneration, and active user/restaurant/access checks.
- Added explicit roles: OWNER, INVENTORY_MANAGER, and ACCOUNTANT. No user was granted access automatically.
- Added an explicit CLI provisioning command for authorized system operators.
- Added the mobile-responsive shell and truthful placeholder dashboard; no fake metrics are shown.
- Captured actual local schema metadata and drift in docs/database-audit.md.

## New tables created

- inventory_schema_migrations
- inventory_portal_access
- inventory_login_attempts

No existing table was altered. A temporary test-only access row was created, used to verify the positive sign-in/session/logout path, and deleted immediately afterward; the access table has zero rows and portal access remains opt-in.

## Focused tests

- PHP syntax check: passed for all Phase 1 PHP files.
- Portal migration: 001_portal_access.sql applied successfully; rerun-safe migration runner confirmed.
- Live URL http://localhost/restaurant-saas/inventory-portal/login.php: returned HTTP 200.
- Login page included a CSRF token and set the expected portal-specific cookie path /restaurant-saas/inventory-portal/.
- Invalid credentials without an access grant were rejected with the same generic message.
- Positive sign-in with a temporary test grant, session ID regeneration, protected dashboard access, and logout: passed. The temporary grant was removed and the table returned to zero rows.
- The read-only live database audit confirmed MySQL 8.4.7 and documented the schema differences described in database-audit.md.

## Access provisioning

Access is intentionally not granted automatically. After the existing Restaurant SaaS user ID and restaurant ID have been verified, an authorized local operator can run:

    php public/inventory-portal/bin/grant-access.php --restaurant-id=ID --user-id=ID --role=OWNER --confirm=GRANT

The command accepts only an active user belonging to the specified active restaurant. Replace the role with INVENTORY_MANAGER or ACCOUNTANT as appropriate.
