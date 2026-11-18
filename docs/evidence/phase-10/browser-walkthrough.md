# Phase 10 — count engine, browser walkthrough

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `worker@brathamatu.test` (verified stock taker, assignment `01M7FK84BXQ7CCBR9W49WTB3HE`)

## Steps

1. Signed in through the login form (no token injection).
2. Opened **Assignments** → accepted job `STJ-20261115-DLQBDO` ("Cycle count — fast movers aisle").
3. Opened **Start counting** → `/workforce/assignments/01M7FK84BXQ7CCBR9W49WTB3HE/count`.
4. Recorded a count for `SUG-1KG` (Sugar 1kg, 24 Piece) through the product search form.
5. Reviewed the session summary and the assigned scope disclosure.

## Captured state

- Count workspace heading: `STJ-20261115-DLQBDO · Cycle count — fast movers aisle · Aisle 1 — Dry Goods`.
- Banner: `✓ Offline package cached — entries will queue locally if you lose connection.`
- Mode badge: `Blind count` with `You will not see expected stock quantities.`
- Session summary: **7 entries · 1 exceptions · 1 unknown**, status **Active**.
- Entries rendered with product, SKU, quantity, unit, condition and `Draft` status:
  `BRE-400 40`, `OIL-1L 52`, `MAI-2KG 64`, `MIL-500 76`, `RIC-5KG 88`, `SOA-800 100`, `SUG-1KG 24`.
- `MAI-2KG` shows the condition note "Two units had damaged packaging; counted as sellable."
- Catalogue exceptions section shows an `open` exception; unknown products section shows barcode
  `6009999999999` / "Unlisted spice sachet" as `open`.
- Assigned scope disclosure lists 8 products.
- No expected stock, cost or valuation is shown anywhere in the workspace (blind count contract).

## Result

Pass. Worker-scoped capture, unit selection, blind counting, exception/unknown reporting and the
submission lock notice all render and operate against real seeded data.
