# Energetic Edge Restaurant Ordering

> A multi-tenant QR ordering, point of sale and inventory platform for restaurants.

## What it does

- Restaurant owner, admin and cashier workspaces
- QR menus, table sessions and customer ordering
- Point of sale, order history and receipt settings
- Menu, portion, category and staff controls
- Inventory portal with recipes, stock counts and wastage

## Technology

PHP 8.2+, MySQL/MariaDB, Apache, Composer

## Local setup

1. Copy `.env.example` to `.env` and enter new local secrets.
2. Run `composer install`.
3. Import `database/schema.sql`, then apply migrations in version order as required.
4. Open the project through a local Apache virtual host or WAMP folder.

## Database and sample data

Schema and numbered migrations in `database/`; inventory portal migrations in `public/inventory-portal/database/migrations/`.

Only schema and fictional starter data are intended for this public repository. Live customer, patient, order and enquiry records are never part of this snapshot.

## Publication note

This repository is a source snapshot. It does not include deployment credentials, live database records, customer uploads or server backups. Create fresh secrets and review the application before production use.

## Development credit

Software Development by [Energetic Edge (Pvt) Ltd](https://www.energeticedge.lk/)
