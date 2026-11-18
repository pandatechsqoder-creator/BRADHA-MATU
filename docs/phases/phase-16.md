# Phase 16 — BRATHAMATU POS core

Status: **passed for the commission/sell/reconcile scope** (backend tests, frontend build, browser acceptance)
Date: 2026-11-15

## Objective

Deliver the BRATHAMATU POS core: terminals, shifts, sales, products and barcode, payments and
receipts, purchases and suppliers, inventory movement integration and cash reconciliation.
Exit criterion: **a POS sale and its stock movement are consistent, auditable and visible in
stock snapshots.**

## Delivered — backend

- **Schema** (`database/migrations/2026_11_14_000000_create_pos_tables.php`): resumable,
  `hasTable`-guarded creation of `pos_terminals`, `pos_shifts`, `pos_customers`, `pos_sales`,
  `pos_sale_items`, `pos_receipts`, `pos_suppliers`, `pos_purchases`, `pos_purchase_items`.
  Live MySQL migration completed; FK ordering fixed (`pos_customers` before `pos_sales`).
- **Service** (`app/Services/Pos/PosService.php`): terminals, shifts (open/close/reconcile and
  reconciliation summary), sales, receipts, suppliers and purchases.
  - Sale creation validates the terminal belongs to the tenant and requires an open shift.
  - Every sale line writes a `posted` inventory movement (`source = brathamatu_pos`) plus a
    movement line, and decrements the matching `inventory_balances` row.
  - **Fix (2026-11-15):** when a variant had no balance row yet (fresh catalogue, no opening
    stock), the sale previously wrote only the movement and the balances page stayed empty.
    `recordSaleMovement` now resolves the variant balance or a default stock location
    (sale branch first, then any tenant location) and creates the balance row before applying
    the signed quantity, so sale and stock stay consistent from the first sale.
- **Controller/routes** (`PosController`, `/tenants/{tenant}/pos/*`): terminals, shifts, sales,
  suppliers, purchases. All id inputs validated as ULID-safe strings, never the `uuid` rule.
- **Models:** `Tenant::terminals/shifts/sales` relations for scoped nested route binding;
  `PosSale::receipt()` corrected to `HasOne`.
- **Tests:** `tests/Feature/PosTest.php` — terminal creation, the full shift → sale → receipt →
  close → reconcile flow (now also asserts the resulting `inventory_balances` row is created
  with `quantity = -2` at a real stock location), supplier + purchase recording, tenant
  isolation. Full suite: **120 passed / 1456 assertions**.

## Delivered — frontend

- `lib/api/pos.ts` (Zod schemas + client) and `lib/pos/hooks.ts` (TanStack Query hooks).
- `features/pos/PosTerminalPage.tsx` — real catalogue search, barcode lookup, variant
  resolution, editable quantity with a read-only authoritative selling price, cash/card/M-Pesa
  payment, tendered cash and change,
  sale + receipt confirmation banner, inline terminal creation and shift opening.
  - **Initial pricing source (2026-11-15):** the terminal began reading variant
    `attributes.retail_price` / `selling_price` / `unit_price` / `price` for cart display. Pricing
    authority moved to the backend in the 2026-11-19 follow-up below.
  - **POS pricing/search follow-up (2026-11-19):** selling prices are now resolved by the backend
    from those configured variant attributes; the checkout API prohibits client-supplied prices and
    fails with an explicit catalogue setup error if no price exists. Sale items retain their unit
    price, tax, discount and line total as transaction-time snapshots. The POS terminal uses the
    tenant-scoped `/pos/products` search endpoint for name, SKU, barcode, brand and category filters,
    alphabetical results, pagination, and branch context from the selected terminal. POS tests cover
    price rejection, missing prices, historical snapshots after a catalogue price edit, scoped
    search/filters and pagination.
- `features/pos/PosTerminalsPage.tsx` — create terminals, status badges, open-shift indicator,
  status changes.
- `features/pos/PosShiftsPage.tsx` — status filter, shift table, close with counted cash,
  reconcile, reconciliation summary (transactions and cash/card/M-Pesa/total sales).
- Routes `/pos`, `/pos/shifts`, `/pos/terminals` in `app/router.tsx`; POS nav in `AppShell`.

## Verification — browser (2026-11-15)

Signed in as `owner@demosupermarket.test` on the Demo Supermarket tenant.

1. Opened Till 1 with a KES 5,000 float.
2. Scanned barcode `6001234500011` (Sugar 1kg) — line prefilled at KES 140.
3. Tendered KES 500, completed sale `POS-UCADNNWM`, receipt `RCPT-CXH5XGK9`, change KES 360;
   shift cash sales updated to KES 140.
4. Closed the shift with counted cash 5,140 → expected 5,140 / actual 5,140 / variance 0;
   reconciliation showed 1 transaction, cash sales KES 140, total sales KES 140; reconciled.
5. Changed Till 2 status to maintenance and back to active.
6. Opened Till 2, sold Bread 400g (`POS-73YUIVGE`, receipt `RCPT-UKULUSUD`, KES 70), closed
   with counted cash 1,070 (expected 1,070, variance 0) and reconciled.
7. Inventory page: movements list both sales under `BRATHAMATU_POS`; the post-fix sale shows
   location "Shop floor" and quantity on hand dropped 479 → 478. Balances tab lists 8 rows
   after opening stock was seeded.

Console: only Vite/React DevTools debug messages. Network: all API calls 2xx (sale POST 201).

Evidence: `docs/evidence/phase-16/01-sale-completed.png`,
`02-shift-reconciled.png`, `03-terminals.png`, `04-pos-movements-balances.png`.

## Demo data

`database/seeders/PosDemoSeeder.php` seeds Demo Supermarket (8 products with EAN-13 barcodes,
cost/retail price, 2 terminals, prepaid agreement `PPA-DSM-2026`, opening stock 60 per variant at
"Shop floor") and Demo Restaurant (5 products, 1 terminal). Registered in `DatabaseSeeder`.

## Known gaps (not passed)

- POS returns and transfers were delivered after this report in the back-office screen
  (`/pos/back-office`) with APIs for suppliers, purchases, customers, returns and transfers.
  Covered by the Phase 21 Playwright navigation suite; no dedicated POS return E2E flow yet.
- Receipts are returned with the sale payload; there is no printable/downloadable receipt view.
- Offline POS operation and external POS/ERP sync belong to Phase 18 (delivered).
