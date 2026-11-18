# Phase 5 — Inventory balances, movements, cut-offs and snapshots

Status: **passed** (backend tests, frontend tests/build, browser acceptance)
Date: 2026-11-11

## Objective

Give every tenant a trustworthy, auditable inventory position before stock-taking starts:
stock balances per site, an append-only movement ledger, a cut-off process that freezes the
boundary between "before" and "after" the count, and an immutable expected-stock snapshot that
later count results are measured against. Batch, expiry and serial tracking and an external
movement-import path complete the Phase 5 foundation.

## Delivered — backend

- **Schema** (`2026_11_11_060000_create_inventory_tables.php`):
  - `inventory_balances` — one row per tenant, stock location, variant and batch; quantity,
    reserved quantity, unit cost and last movement timestamp.
  - `inventory_movements` + `inventory_movement_lines` — append-only ledger with type, source,
    reference, external id, occurred-at, actor, cut-off link, line quantity and optional batch,
    serial, expiry and unit cost.
  - `inventory_batches` (batch number, expiry, manufactured date, status) and
    `inventory_serials` (serial number, status, location, batch).
  - `inventory_cutoffs` and `inventory_cutoff_movements` (tenant-scoped pivot) — a cut-off marks
    the stock boundary for a location or branch.
  - `inventory_snapshots` + `inventory_snapshot_items` — frozen expected stock with SKU, batch,
    expiry, expected quantity, unit cost/value and a SHA-256 checksum.
- **Movement engine** (`InventoryService`):
  - `opening`, `purchase`, `sale`, `return`, `transfer_in`, `transfer_out`, `adjustment`,
    `production`, `damage`, `expiry`, `consumption`, `other` with inbound/outbound sign rules.
  - Balances update through `applyBalance`; negative non-adjustment movements are rejected.
  - Batch-tracked products require `batch_number`; serial-tracked products require
    `serial_number`; expiry-tracked products require `expires_on`; quantity-tracked products
    reject batch/serial context.
  - Serial register transitions to `in_stock|sold|scrapped|missing|returned` per movement type.
  - Movements occurring after an open cut-off are linked to it automatically
    (`inventory_cutoff_movements.tenant_id` is written on every sync).
  - Every movement and cut-off writes an audit record.
- **Expected-stock snapshots**:
  - Uses the ledger up to the cut-off time (post-cut-off sales do not leak into expected stock).
  - Snapshot time is immutable once a cut-off exists.
  - Repeating the same scope + catalogue version + cut-off returns the existing frozen snapshot
    (idempotent); a newer snapshot supersedes the previous one in the same scope.
  - Items include zero-expected catalogue products so count variance can be recorded later.
  - Snapshot checksum is a 64-character SHA-256 over SKU, batch and expected quantity.
  - Snapshots created from a cut-off inherit the cut-off's stock location and branch.
- **External movement import** (`InventoryMovementController@import`):
  - CSV/XLSX aliases: `external_id`, `type`, `stock_location`, `occurred_at`, `reference`,
    `sku`, `quantity`, `batch_number`, `expires_on`, `manufactured_on`, `serial_number`,
    `unit_cost`, `notes`.
  - Required columns: type, stock location, SKU, quantity (`422 IMPORT_HEADERS_MISSING`).
  - Rows sharing an external id become one movement with multiple lines; conflicting type,
    location or date within a group is rejected with row-level errors.
  - Re-uploading the same tenant + source + external id skips existing movements (idempotent).
  - Returns `created_movements`, `skipped_movements`, `error_rows`, `errors` (first 100) and a
    sample of created movements; one audit record `inventory.movements.imported`.
- **Authorization and isolation**:
  - `inventory.view`, `inventory.movement.create`, `inventory.adjust`,
    `inventory.snapshot.create`.
  - Every query is tenant-scoped; cross-tenant movement/snapshot/cut-off routes return 404.
  - Cost and value fields require `catalogue.update`; other roles receive `null` values and the
    UI hides cost columns and the stock-value card.
- **API surface** (`routes/api.php`, 16 operations):
  - `GET /tenants/{tenant}/inventory`
  - `GET /inventory/balances`, `/inventory/batches`, `/inventory/serials`
  - `GET|POST /inventory/movements`, `GET /inventory/movements/{movement}`,
    `POST /inventory/movements/import` (throttled 20/min)
  - `GET|POST /inventory/cutoffs`, `GET /inventory/cutoffs/{cutoff}`,
    `POST /inventory/cutoffs/{cutoff}/close`
  - `GET|POST /inventory/snapshots`, `GET /inventory/snapshots/{snapshot}`,
    `GET /inventory/snapshots/{snapshot}/items`

## Delivered — frontend

- **`/business/inventory`** — permission-aware inventory workspace with five tabs:
  - **Overview**: quantity on hand, stock value (hidden without cost permission), movements in
    the last 30 days, expiry watch, open cut-off alert, recent movements, latest snapshot with
    checksum and scope, tracking mix.
  - **Balances**: search, location filter, positive-only toggle, pagination and conditional
    value column.
  - **Movements**: post movement with dynamic lines (SKU, quantity, batch, serial, expiry, unit
    cost), external CSV/XLSX import with row-error display and source tag, ledger filters
    (type, source, location, search, date range) and expandable movement lines.
  - **Cut-offs & snapshots**: open and close cut-offs, freeze snapshots from a cut-off or a
    stock location, list frozen snapshots, snapshot detail with checksum, frozen time and
    conditional cost/value columns.
  - **Batches & serials**: batch search and expiry filters with days-to-expiry badges, serial
    search and status filter with pagination.
- **Navigation**: “Inventory” in the business sidebar, "Manage inventory →" quick action on the
  dashboard.
- New API/query modules: `src/lib/api/inventory.ts` (Zod schemas) and
  `src/lib/inventory/hooks.ts` (queries, mutations, cache invalidation).

## Verification

- Backend: `php artisan test` — **44 passed (369 assertions)**. Phase 5 coverage
  (`InventoryTest`, 6 tests / 131 assertions):
  - opening, sale and adjustment update balances, ledger lines, summary totals and audit
  - stock-controller receives null costs/values; tenant-read-only gets 403
  - batch/serial products reject missing context; batch expiry and serial sold transitions work
  - cut-off links post-cut-off movements; snapshot expected 95 (opening 100 − pre-cut-off sale 5)
    excludes the post-cut-off sale of 10; duplicate snapshot is idempotent; close works; new
    cut-off opens; snapshot inherits the cut-off's location and branch
  - external import groups `EXT-1` into one 2-line movement, reports unknown location/SKU rows,
    skips the re-uploaded external id, and rejects a file missing required headers
  - tenant isolation: cross-tenant summary is 403 and movement routes are 404
- Frontend: `npm run build` passes (TypeScript + Vite; chunk-size warning only);
  `npm test` — **13 passed**.
- Browser acceptance (`http://127.0.0.1:5173`, Chrome in-app browser, Bright Mart owner):
  1. Inventory page loaded with 4 tracked variants and “Ledger up to date”.
  2. Posted opening stock **OIL-5L × 100** at Aisle 1 → balances showed 100 units and
     **Ksh 120,000.00**; movement ledger listed the entry as MANUAL.
  3. Created a batch-tracked product **YOG-500** and posted **40 units, batch B-2026-11,
     expiry 2027-01-31** → Batches tab showed **81 days left, 40 active**.
  4. Uploaded `movements-import.csv` (2 lines under `EXT-UI-1`, 1 unknown SKU). UI reported
     **1 movement created, 0 duplicates, 1 row error**, displayed “Row 4: … product does not
     belong to this business”, and the ledger showed the purchase with **2 lines / 30 units /
     IMPORT**.
  5. Opened a cut-off for Aisle 1, froze snapshot **COUNT-002** → 5 SKUs, **140 expected
     units**, catalogue v5, SHA-256 checksum, scope **Aisle 1**; the detail table rendered zero
     expected lines, the 100-unit oil line and the 40-unit batch line with costs.
  6. Closed a cut-off; the header reverted to “Ledger up to date”. Opened a new cut-off and
     confirmed the open-cut-off banner.
  7. Registered serial **SN-UI-0001** against **BLD-1500** → Serials tab showed **in stock** at
     Aisle 1.
  8. Overview recalculated to **170 units on hand, Ksh 129,300.00 stock value, 3 movements in
     30 days, 4 balance rows, 5 tracked variants** (quantity ×4, batch ×1).
  9. Signed in as `branch@demosupermarket.test` (branch-manager): inventory view was allowed,
     but the stock-value card showed **“Hidden — Your role cannot view cost values”**, the
     balances table omitted the VALUE column, and the post/import/cut-off/snapshot forms were
     absent. Returned to the Bright Mart owner afterwards.
- Evidence: `docs/evidence/phase-05/movements-import.csv` (import fixture used in the browser).

## Notes / deferred

- Adjustment movements are the only type allowed to go negative and require
  `inventory.adjust`; all other negatives are rejected with row-level validation errors.
- Expected-stock snapshots intentionally include zero-quantity catalogue products so later
  count results can record variances against every product in scope.
- Snapshot items are immutable; a later snapshot supersedes the previous one in the same scope
  rather than editing it.
- Cost visibility remains gated by `catalogue.update` in every inventory endpoint, including
  snapshot items and import results.
- Demo and browser-test accounts remain local/staging only and must never be enabled in
  production.
