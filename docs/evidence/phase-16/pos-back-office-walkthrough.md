# Phase 16 — POS back office browser walkthrough (2026-11-15)

Environment: backend `http://127.0.0.1:8000/api/v1`, frontend `http://127.0.0.1:5173`,
MySQL 8 (`panda_stocktake`, port 3307). Signed in through the UI as
`owner@demosupermarket.test` (tenant **Demo Supermarket**).

## Scope exercised

All five back-office tabs on `/pos/back-office` were exercised through the browser after the
Phase 16 remainder was implemented:

| Tab | Action | Result |
| --- | --- | --- |
| Customers | Created `Browser Customer` / `+254711222333` | Row listed with status `active` |
| Suppliers | Created `Browser Wholesalers` / `+254733444555` | Row listed with status `active` |
| Purchases | Received 5 × Sugar 1kg @ Ksh 95 into Westlands Branch | `PUR-ZWV2P3QC`, total Ksh 475.00, status `received` |
| Returns | Returned 1 × Sugar 1kg from sale `POS-BK0661ZV`, cash refund, reason "Customer changed mind" | `RET-KMNVHFJO`, status `completed`; sale disappears from the eligible-sales picker |
| Transfers | Moved 2 × Sugar 1kg from Westlands Branch to Main Branch — Mombasa Road, receive immediately | `TRF-S4TASKDT`, status `received`, route rendered correctly |

## Inventory and audit verification

Verified directly against MySQL after the browser actions:

- `inventory_movements` for the demo tenant contains POS-sourced movements with
  `source = brathamatu_pos`:
  - `purchase` — `PUR-ZWV2P3QC`, 1 line, `+5`
  - `return` — `RET-KMNVHFJO`, 1 line, `+1`, metadata links sale and return ids
  - `transfer_out` — `TRF-S4TASKDT`, 1 line, `-2`
  - `transfer_in` — `TRF-S4TASKDT`, 1 line, `+2`, resolved to Main Branch stock location
  - pre-existing seeded `sale` movements remain linked with `metadata.sale_id` / `terminal_id`
- `inventory_balances` reflects the movements per `(stock_location_id, variant_id, batch_key)`.
- Stock locations auto-resolve per branch: when a branch had no location, POS now creates
  `"<Branch> stock"` (type `branch`, status `active`) so movements stay attributable.

## Defects found and fixed during this walkthrough

1. **Empty product pickers in the back office.** `GET /products` did not return `variants`, so the
   purchase/transfer selects rendered no options. Added an opt-in `include=variants` query to
   `ProductController@index` and used it from `VariantSelect` / `PurchasesPanel`.
   Regression test: `test_product_index_can_include_variants_for_pos_pickers`.
2. **Location-less branch movements.** Purchases/transfers into a branch without a stock location
   posted balances with `stock_location_id = null`. `PosService::recordMovement` now falls back to
   a branch-specific location, creating it on first use. Regression coverage extends
   `test_supplier_and_purchase_recording` with branch/location/balance assertions.

## Test evidence

```
php artisan test --filter=PosTest
PASS  Tests\Feature\PosTest
  ✓ product index can include variants for pos pickers
  ✓ terminals can be created and listed
  ✓ shift sale receipt close and reconcile flow
  ✓ supplier and purchase recording
  ✓ customers can be created and listed
  ✓ sale return restores stock and adjusts cash reconciliation
  ✓ branch transfer moves stock between locations
  ✓ pos sales are isolated per tenant
Tests: 8 passed (90 assertions)
```

Screenshots: the in-app browser still reports `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`, so the
structural accessibility snapshots above are the evidence for this pass. The earlier Phase 16
PNGs (`01-sale-completed.png` … `04-pos-movements-balances.png`) remain valid.
