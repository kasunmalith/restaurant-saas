![Restaurant Ordering project cover](docs/readme-banner.svg)

<div align="center">
  <h1>Energetic Edge Restaurant Ordering</h1>
  <p><strong>QR menus, ordering, point of sale and stock workflows for multiple restaurants.</strong></p>
  <p><img alt="PHP 8.2+" src="https://img.shields.io/badge/PHP%208.2%2B-243447?style=flat-square"> <img alt="MySQL" src="https://img.shields.io/badge/MySQL-243447?style=flat-square"> <img alt="Composer" src="https://img.shields.io/badge/Composer-243447?style=flat-square"> <img alt="Multi tenant" src="https://img.shields.io/badge/Multi%20tenant-243447?style=flat-square"> <img alt="QR ordering" src="https://img.shields.io/badge/QR%20ordering-243447?style=flat-square"></p>
</div>

---

## Overview

A lightweight multi-tenant restaurant application with separate SaaS owner, restaurant administrator, cashier and customer experiences. Its source includes table QR controls, a mobile menu and order flow, point-of-sale operations, reporting and an inventory portal.

> [!NOTE]
> This is a public source snapshot prepared from a local project. It includes implementation code and safe schema/sample data, without live records or credentials.

## What the software can do

| Area | Implemented scope |
| --- | --- |
| **SaaS ownership** | Restaurant creation, activation, subscription dates and manual payment records. |
| **Restaurant management** | Branding, menu categories, food items, staff, tables and ordering settings. |
| **Customer menu** | QR-authorized menu browsing, basket, special requests and server-priced order submission. |
| **Cashier and POS** | Pending orders, status history, bill editing, payment recording and receipt settings. |
| **QR operations** | Table token management, QR PNG and printable single/multi-table PDF cards. |
| **Inventory portal** | Stock, recipes, counts, KOT-linked use, wastage and related worker jobs. |
| **Reporting** | Date-range order summaries, popular items and tenant-scoped CSV exports. |

### Typical workflow

1. The owner provisions a restaurant and its administrator.
2. Staff configure menu items and tables, then distribute table-specific QR cards.
3. A guest orders from the menu; cashier/POS processes the order and the inventory portal can account for stock.

## Run locally

1. Use PHP 8.2+, MySQL/MariaDB, Apache and Composer.
2. Copy `.env.example` to `.env`, generate a new `APP_KEY` and configure a new local database.
3. Import `database/schema.sql`; review and apply the numbered migrations needed by this snapshot.
4. Run `composer install` and serve the `public/` entry point through Apache.
5. Provision initial owner/staff accounts securely. This public snapshot intentionally does not ship demo logins.

## Database and sample content

`database/schema.sql` contains table structure, with all demo inserts removed for publication. Numbered SQL files cover later additions. The inventory portal has its own migrations under `public/inventory-portal/database/migrations/`. No live orders, customers or restaurant uploads are published.

## Repository guide

| Path | Purpose |
| --- | --- |
| `app/` | Tenant-aware controls, endpoints, session and authorization logic |
| `public/` | Web entry points, menu/POS assets and inventory portal |
| `database/` | Base schema, migrations and maintenance helpers |
| `tests/` | Local integration and smoke tests |

## Security and privacy

- Tenant and role checks protect staff operations.
- Tokens are hashed/encrypted where the QR workflow requires it; submitted prices are checked server-side.
- Private `.env`, runtime uploads and logs stay outside this repository.

## Current boundaries

- Initial accounts must be provisioned before sign-in because credential seed rows were removed.
- Online card payments, SMS renewal reminders and external accounting integration are not implemented in this snapshot.
- The inventory portal's background jobs require a hosting-specific schedule.

## Verification

Run PHP lint, the local integration tests and the complete guest-to-cashier order flow against a disposable database.

---

<div align="center">

Software Development by <a href="https://www.energeticedge.lk/" target="_blank" rel="noopener noreferrer">Energetic Edge (Pvt) Ltd</a>

</div>
