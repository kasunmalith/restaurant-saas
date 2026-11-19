# Installation and Access

## Requirements

- PHP 8.2+ with PDO MySQL and `mbstring`.
- MySQL/MariaDB compatible with the portal migrations; local verification used MySQL 8.4.7.
- The existing Restaurant SaaS database configuration and active `restaurants`/`users` records.

The portal uses the current Restaurant SaaS database connection settings without copying credentials into this directory. Do not add database credentials to portal source files.

## Local installation

1. Keep the portal at `public/inventory-portal/`. Do not modify the existing app, `.env`, or root rewrite configuration.
2. From a command prompt, run:

   `C:\wamp64\bin\php\php8.2.29\php.exe C:\wamp64\www\restaurant-saas\public\inventory-portal\database\migrate.php`

   The CLI migration runner applies SQL migrations in numeric order and records them in `inventory_schema_migrations`. Re-running it skips migrations already recorded.
3. Sign in to the SaaS Owner account, open the separate **Inventory & Profit** section, and select a restaurant.
4. Optionally enter your own Inventory Portal password (at least 6 characters and at most 72 bytes), then choose **Enable Portal**. Leave the field blank to generate a secure random password. The active Restaurant Manager receives the initial `OWNER` portal grant. The new password is shown once; copy it securely. The normal Manager SaaS password is not changed.
5. Open `http://localhost/restaurant-saas/inventory-portal/` and sign in with the Manager email shown in the Owner control and the newly set Inventory Portal password.
6. The restaurant-specific portal password is shared by accounts with active portal grants. To grant another verified user a portal role, use the CLI from the project root:

   `C:\wamp64\bin\php\php8.2.29\php.exe public\inventory-portal\bin\grant-access.php --restaurant-id=ID --user-id=ID --role=OWNER --confirm=GRANT`

   Supported roles are `OWNER`, `INVENTORY_MANAGER`, and `ACCOUNTANT`. Replace IDs and role only after verifying them. The selected restaurant must also be enabled by the SaaS Owner.
7. To change the tenant password, enter a new password of at least 6 characters and at most 72 bytes, then choose **Change / Generate Password**. Leave the field blank to generate a secure random replacement. The previous password stops working immediately. Use **Disable Portal** to block sign-ins and invalidate current portal sessions on their next request. Disabling does not delete grants or change the regular SaaS password.
8. To deactivate an individual grant, run:

   `C:\wamp64\bin\php\php8.2.29\php.exe public\inventory-portal\bin\revoke-access.php --restaurant-id=ID --user-id=ID --confirm=REVOKE`

## Production note

No production files or database were changed or tested. Before any later production rollout, deploy the listed SaaS Owner UI/API and Inventory Portal files, verify the host's PHP/database versions and database configuration, back up the database, apply migrations 001–007 with an authorized CLI/database operator, and enable each restaurant/set its portal password from the SaaS Owner account. Schedule the worker only after inventory setup. Never expose `.env`, migration SQL, CLI scripts, or error output publicly. Production readiness is not established by localhost tests.
