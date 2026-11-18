# Phase 16 — POS, end-to-end browser walkthrough (re-verification)

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `owner@demosupermarket.test` (tenant owner, `brathamatu_pos` operating mode)

## Steps and captured state

1. **Shift open** — `/pos` showed `Till 1 / Shift closed`; opened a shift with float `0`.
   Result: `Shift open`, opened `15/11/2026, 16:58:02`, float `KES 0.00`.
2. **Barcode lookup** — entered catalogue barcode `6001234500011` and pressed *Look up barcode*.
   Result: `Sugar 1kg / SUG-1KG · 6001234500011` added to the cart at `KES 140.00` (unit price 140,
   quantity 1), subtotal/total `KES 140.00`, tender pre-filled `140.00`, **Complete sale** enabled.
   This confirms barcode input resolves through the catalogue (the earlier fake-scan defect is gone).
3. **Sale** — clicked *Complete sale*. Result banner:
   `Sale POS-BK0661ZV completed — receipt RCPT-ZLFKXQY5. Total KES 140.00 · change KES 0.00`.
   Cash sales counter updated to `KES 140.00`; cart cleared.
4. **Shifts** — `/pos/shifts` listed 3 shifts. Selected the open Till 1 shift, entered counted cash
   `140`, clicked *Close shift* → status `closed`, expected `KES 140.00`, actual `KES 140.00`,
   variance `KES 0.00`, transactions `1`, cash sales `KES 140.00`.
5. **Reconcile** — clicked *Reconcile shift* → status `reconciled` (locked).
6. **Terminals** — `/pos/terminals` lists `Till 1 (DSM-POS-01)` and `Till 2 (DSM-POS-02)` with
   status controls and the new-terminal form.

## Result

Pass. POS terminal, barcode lookup, cash sale, receipt, shift close with cash count and shift
reconciliation all work in the browser with no console errors.
