# Phase 7 — Payments and financial ledger

Status: **passed** (backend tests, frontend tests/build, browser acceptance)
Date: 2026-11-11

## Objective

Deliver the money path for deposit and balance payments: a provider abstraction with sandbox
adapters, signed and idempotent callbacks, payment allocation, an immutable double-entry ledger,
reconciliation and refund foundations, and tenant-facing deposit/balance flows. A browser redirect
must never settle a payment; only a verified provider callback or an authorised finance decision can.

## Delivered — backend

- **Schema** (`2026_11_11_080000_create_finance_tables.php`):
  - `financial_accounts` with the chart of accounts: 1000 M-Pesa clearing, 1010 Card clearing,
    1020 Bank clearing, 1030 Cash/manual clearing, 1100 Tenant receivables, 2000 Tenant
    deposits/customer funds, 2100 Refund liability, 2200 Unearned job revenue, 4000 Platform
    service revenue, 4100 Catalogue revenue.
  - `financial_journals` / `financial_journal_lines` with a unique `(source_type, source_id)` key,
    tenant/job scoping, posting timestamps and `reversal_of_id`.
  - `payments` (`deposit|balance`; `mpesa|card|bank|manual`; `pending|processing|succeeded|failed|
    cancelled`; unique `internal_reference` and `provider_reference`; tenant-scoped
    `idempotency_key`; masked phone metadata).
  - `payment_attempts` (provider request/response trail), `payment_callbacks` (unique
    `(provider, callback_id)`, signature validity, accepted/rejected/ignored status, redacted
    payload), `payment_allocations`, `refunds`, `financial_reconciliations` and
    `financial_reconciliation_items`.
  - Legacy Phase 6 `stocktake_deposit_payments` rows are migrated into `payments` with allocations
    and balanced clearing/deposit journals before the old table is dropped.
- **Provider abstraction** (`Services/Payments`):
  - `PaymentProvider` / `ProviderInitiation` / `ProviderCallback` contracts and
    `PaymentProviderManager`, configured by `config/payments.php`.
  - `MpesaSandboxProvider` sends a simulated STK push (masked phone), returns a checkout reference
    and requires a signed provider callback before settlement.
  - `CardSandboxProvider` models a hosted checkout redirect that likewise requires the callback.
  - `ManualProvider` covers bank and manual transfers, which stay pending until platform finance
    confirms or fails them from the finance workspace.
- **Payment service** (`PaymentService`):
  - Initiation validates purpose, amount, method and outstanding balance; an `Idempotency-Key`
    header returns the existing payment instead of creating a duplicate.
  - Only one payment per purpose may be in flight; deposit and balance amounts are derived from the
    job's policy/quote, not trusted from the browser.
  - Settlement records the attempt, allocation and `succeeded_at`, clears the receivable/deposit and
    advances the job (`awaiting_balance_payment` → `approved_for_execution`) inside a transaction.
  - Failure returns the job to the payable state where appropriate; cancellation and manual
    verification (`confirm|fail` with reference/reason) are audited.
  - Callback pipeline verifies the `X-Brathamatu-Signature` HMAC, amount, currency, merchant/provider
    reference and status; duplicate callbacks are ignored idempotently and rejected callbacks are
    logged without settling. The sandbox `simulate` endpoint feeds the same signed pipeline rather
    than letting the browser force success.
- **Ledger** (`LedgerService`):
  - Balanced journal posting (rejects unbalanced or single-line entries) with unique source keys so
    repeated settlement cannot double-post.
  - Postings for deposit received (clearing → 2000), balance received (clearing → 1100), quote
    acceptance (2200 unearned revenue), and refunds. Corrections are made by reversal journals;
    posted journals are never edited.
  - Trial balance (per account totals, debit/credit totals, balanced flag), provider clearing totals
    and per-job ledger views.
- **Reconciliation** (`ReconciliationService`):
  - Compares an uploaded provider statement against settled payments and clearing-ledger totals
    per period.
  - Item statuses `matched`, `amount_mismatch`, `unmatched` (on statement, not in ledger) and
    `missing` (in ledger, not on statement); overall `balanced|variance` with statement, payment,
    ledger and difference totals.
- **Refunds** (`RefundService`):
  - Lifecycle `requested → approved → processing → completed|failed`, plus `cancelled`.
  - Request validates the payment belongs to the job and the amount does not exceed the settled
    amount; approval is recorded separately for audit and the maker cannot bypass it.
  - The ledger refund journal is posted only on completion (`1100 Tenant receivables` debit,
    clearing credit), so a requested or approved refund never moves balances.
- **Authorization**:
  - `finance.ledger.view`, `finance.payment.record` and `finance.refund.approve` gate the platform
    finance endpoints; tenant payment initiation still requires `finance.payment.record` and tenant
    ownership of the job.
  - Platform finance queries deliberately cross tenant scope for the operator console; every
    mutation resolves the record first and records the tenant and actor in the audit log.
- **API surface** (`routes/api.php`):
  - `POST /payments/mpesa/callback` (public, signed, throttled)
  - `POST /tenants/{tenant}/stocktake-jobs/{job}/payments`
  - `POST /tenants/{tenant}/stocktake-jobs/{job}/payments/{payment}/simulate`
  - `GET /platform/finance/overview`, `GET /platform/finance/options`
  - `GET /platform/finance/payments`, `POST /platform/finance/payments/{payment}/verify`
  - `GET /platform/finance/ledger`
  - `GET|POST /platform/finance/reconciliations`,
    `GET /platform/finance/reconciliations/{reconciliation}`
  - `GET|POST /platform/finance/refunds`,
    `POST /platform/finance/refunds/{refund}/decision`

## Delivered — frontend

- **Tenant job detail** (`/business/stocktaking/{jobId}`):
  - Balance card with amount due and settlement progress.
  - Payment form for deposit or balance with method, M-Pesa phone, bank/manual reference and
    notes; sandbox mode exposes a clearly labelled **Simulate provider callback** action.
  - Payment history with status, provider reference and masked phone. A redirect or reload never
    changes the status; only the callback result does.
- **Finance data layer** (`src/lib/api/finance.ts`, `src/lib/finance/hooks.ts`):
  - Zod schemas for overview, payments, journals/trial balance, reconciliations, refunds and
    provider options, with TanStack Query hooks and cache invalidation across finance and
    stock-taking job queries.
- **`/platform/finance`** — permission-gated tabbed workspace:
  - **Overview** — collected today/month, pending and failed counts, collected by method, recent
    payments, recent reconciliations and a live ledger-balanced indicator.
  - **Payments** — filters (status, method, purpose, search), sandbox/live label, provider
    reference, and Confirm/Fail verification with an optional reference or reason.
  - **Ledger** — date, tenant and job filters; trial balance for the view; journal cards with
    debit/credit lines, memos, reversal badges and immutable-entry notice.
  - **Reconciliations** — create from pasted statement lines, show matched/mismatch/unmatched/
    missing items, variance totals and history with drill-down.
  - **Refunds** — request from a settled payment (prefilled from the Payments tab), status
    filters, and Approve / Process / Fail / Cancel decisions with the correct permission gates.
- **Navigation**: platform shell gains a permission-gated Finance entry; the route is registered
  at `/platform/finance` behind `RequirePlatform`.

## Verification

- Backend: `php artisan test` — **55 passed (634 assertions)**.
  - `FinanceTest` (6 tests): signed callback required and idempotent sandbox settlement; wrong
    callback amount rejected without settling; balance payment clears the receivable and posts a
    balanced journal; reconciliation matches statement lines and flags variance; refund requires
    approval and posts a balanced reversal; finance endpoints are permission-guarded.
- Frontend: `npm run test` — **16 passed** (4 files); `npm run build` passes (TypeScript + Vite,
  chunk-size warning only).
- Browser acceptance (`http://127.0.0.1:5173`, in-app browser):
  1. `owner@demosupermarket.test` opened **STJ-20261111-GSILMC** with a balance due of
     **Ksh 2,964.12**, started an M-Pesa sandbox payment (**PAY-20261111-AKAQ0V**) and received the
     “awaiting provider callback” state; the payment was not successful on redirect.
  2. Simulation of the signed callback settled the payment, posted the balance journal and moved the
     job to **approved_for_execution**.
  3. `finance@brathamatu.test` opened `/platform/finance`: overview showed collections, the recent
     balance payment and **Ledger balanced**; Payments listed the provider reference and sandbox
     label; Ledger showed the deposit and balance journals with a balanced trial balance
     (**Ksh 9,833.12** debit and credit after the refund).
  4. A reconciliation for M-Pesa matched the statement lines and reported `missing` ledger entries,
     producing a variance summary as expected.
  5. A refund of **Ksh 2,964.12** was requested on the balance payment, approved by finance, then
     processed with provider reference **REF-SANDBOX-20261111-001**; the refund moved to
     **Completed** and the ledger posted **JRN-20261111-SVDH6B** (Dr 1100 / Cr 1000) with totals
     still balanced.
- Evidence: `docs/evidence/phase-07/01-tenant-balance-settled.png`,
  `02-finance-overview.png`, `03-finance-payments.png`, `04-finance-ledger.png`,
  `05-finance-reconciliation.png`, `06-finance-refund.png`, `07-ledger-refund-journal.png`.

## Notes / deferred

- Sandbox adapters and the local sandbox secret remain the default until live M-Pesa/card
  credentials are supplied; the UI labels sandbox mode and never treats a redirect as settlement.
- Refund payout execution against a real provider, maker-checker payout approval, worker payouts,
  credits/expenses and month-end finance statements belong to Phase 14.
- Raw callback payloads are stored redacted, are unique per provider/callback ID and are not
  exposed through the API; retention policy belongs to the Phase 20 privacy review.
- Quote-acceptance journals (2200) are posted for quotes accepted after this phase; quotes accepted
  during Phase 6 retain their migrated deposit-only journals and remain balanced.
- Demo accounts and credentials remain local/staging only and must never be enabled in production.
