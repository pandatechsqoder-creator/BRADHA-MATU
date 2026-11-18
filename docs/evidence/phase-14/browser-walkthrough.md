# Phase 14 — completion, payout and finance, browser walkthrough

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `finance@brathamatu.test` (platform finance manager)
URL: `/platform/finance`

## Steps

1. Signed in through the login form as the finance manager.
2. Opened the **Overview** tab and checked totals, recent payments and the trial balance.
3. Switched to the **Payouts** tab and inspected the pending batch and maker-checker action.

## Captured state — Overview

- Collected today/month `Ksh 70,000.00`; 10 MPESA payments.
- Recent payments list includes deposit/balance pairs for the demo jobs, all `Succeeded`.
- Trial balance month-to-date: debits `Ksh 147,575.00` = credits `Ksh 147,575.00`, marked **Balanced**.
- Copy confirms the ledger is immutable and corrections post as reversals.

## Captured state — Payouts

- Batch `PAY-01M7FSC24V2MJDR6S667AP2PM4`, status **pending approval**, total `Ksh 3,000.00`, 1 payout.
- Payout row: recipient `+254700000102`, method `MPESA`, amount `Ksh 3,000.00`, status `pending`.
- **Approve batch** action rendered for the checker (finance account); the preparing account is a
  different user, so maker ≠ checker is respected.
- Clicked **Approve batch** → batch status `approved`, action changed to **Process payouts**.
- Clicked **Process payouts** → queue now reads **No pending payout batches**; the sandbox adapter
  completed the payout and the job advanced past `payout_pending_authorisation`.
- Job `STJ-20261115-2J5SJX` history ends at **Payout completed**
  (`Payout batch PAY-01M7FSC24V2MJDR6S667AP2PM4 completed.`) with no review actions remaining.

## Result

Pass. The full maker-checker payout lifecycle (pending approval → approved → processed) works in the
browser with real accrued earnings, and the finance overview reconciles to a balanced ledger.
