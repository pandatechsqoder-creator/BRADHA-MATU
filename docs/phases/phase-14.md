# Phase 14 — Completion, payout and finance operations

Status: **passed** (backend 101/101 tests, frontend build clean)
Date: 2026-11-12

## Objective

Deliver payout queue with maker-checker approval, sandbox payout processing, job close-out, and the finance payout queue panel. Exit: payout cannot occur before required completion and approval; ledger remains balanced; payout is idempotent.

## Delivered — backend

**Schema** (`2026_11_11_170000_create_payout_tables.php`):
- `payout_batches` — batch header (reference, status, total, payout count, preparer, approver, timestamps).
- `stock_taker_payouts` — per-worker payout (amount, method, recipient, status, provider reference).
- `payout_approvals` — audit trail of approve/reject decisions with notes.

**PayoutService** (`app/Services/Finance/PayoutService.php`):
- `prepareBatch()` — Maker step: queues payouts for all accrued earnings on a job, creates the batch in `pending_approval`, transitions job `completion_confirmed → payout_pending_authorisation`.
- `approveBatch()` — Checker step: enforces maker ≠ checker (ValidationException if same user), transitions batch to `approved`.
- `processBatch()` — sandbox adapter: marks all payouts `completed` immediately, marks earnings `paid`, transitions job `payout_pending_authorisation → payout_processing → payout_completed`.
- `closeJob()` — transitions job `payout_completed → closed`.
- `pendingBatches()` — finance dashboard query.

**Controller** (`PlatformPayoutController`) with 6 routes:
- `GET  /platform/payouts` — list pending batches.
- `GET  /platform/payouts/{batch}` — batch detail.
- `POST /platform/stocktake-jobs/{job}/payouts` — prepare batch (permission: `finance.payout.prepare`).
- `POST /platform/payouts/{batch}/approve` — approve (permission: `finance.payout.approve`).
- `POST /platform/payouts/{batch}/process` — process (permission: `finance.payout.approve`).
- `POST /platform/stocktake-jobs/{job}/close` — close job (permission: `finance.payout.approve`).

## Delivered — frontend

- `PayoutQueuePanel` on the Finance workspace **Payouts** tab:
  - Lists pending/approved/processing batches with batch reference, status, total amount, payout count.
  - Per-payout table: recipient phone/account, method, amount, status.
  - Approve button for `pending_approval` batches (only shown to `finance.payout.approve` holders).
  - Process button for `approved` batches.
- Finance page gains a "Payouts" tab, visible to `finance.payout.prepare` or `finance.payout.approve` holders.

## Verification

- Backend: **101/101 passed (1288 assertions)**, zero regressions.
  - `PayoutTest` (6 tests, 29 assertions): officer prepares batch, maker-checker blocks self-approval, manager approves+processes (sandbox), job closes, finance list visible, guard blocks before completion.
- Frontend: `npm run build` — 9.52s.
- Migration applied to live database.

## Notes / deferred

- Production M-Pesa/bank payout adapters (real API calls and signed callbacks) replace `sandboxProcess()` in Phase 20 when real credentials are supplied.
- Payout reconciliation (matching provider statements to payout records) is part of the existing finance reconciliation flow from Phase 7.
- Expense claims and approvals (`expense_claims`, `expense_approvals`) are deferred to Phase 19.
