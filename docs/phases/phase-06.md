# Phase 6 — Stock-taking request, review and quotation

Status: **passed** (backend tests, frontend tests/build, browser acceptance)
Date: 2026-11-11

## Objective

Deliver the complete request-to-quote flow: a tenant defines scope, schedule and site
requirements; BRATHAMATU reviews the request; the tenant pays a configurable deposit; BRATHAMATU
issues a quote that separates the tenant charge from confidential worker compensation; and the
tenant accepts or declines the quote. Tenant-visible figures and internal platform economics must
never cross.

## Delivered — backend

- **Schema** (`2026_11_11_070000_create_stocktake_job_tables.php`):
  `stocktake_jobs`, `stocktake_job_quotes`, `stocktake_deposits`, `stocktake_deposit_payments`,
  `stocktake_job_amendments`, `stocktake_job_status_history` and `stocktake_policies`.
- **Request service** (`StocktakeRequestService`):
  - Catalogue-readiness gate (confirmed catalogue version, products, SKUs, locations).
  - Provisional estimate from the active policy: base, per-SKU, per-location, per-branch and
    priority multiplier, plus tax and the derived deposit.
  - Scope by branch, stock location, product scope/category, team size and count method.
  - Versioned tenant-scoped requests with requirements, evidence and special instructions.
  - Draft and `needs_tenant_information` edits; clarification submissions return to `submitted`.
- **Workflow** (`StocktakeWorkflow`): explicit transitions and actor types
  (`tenant|platform|worker`), with status history and audit logging. No direct status writes.
- **Deposit service** (`StocktakeDepositService`):
  - Platform policy defaults plus tenant `stocktake.deposit_policy` overrides and job-type
    overrides; percentage, fixed and waived deposits with minimum/maximum and due dates.
  - Tenant payment records (M-Pesa, card, bank, manual) stay `pending` until finance confirms or
    fails them; only confirmed payments advance the request to `submitted`.
  - Waiver path for authorised platform finance.
- **Quote service** (`StocktakeQuoteService`):
  - Tenant charge lines: base service, scope complexity, travel, other expenses, discount, tax,
    deposit and balance; quote numbers, versions and validity.
  - Confidential internal economics: worker compensation budget, breakdown lines, platform fee,
    margin and internal notes.
  - Issuing supersedes earlier issued quotes; acceptance requires confirmed deposit and moves the
    request to `awaiting_balance_payment`; decline returns it to `under_review`.
- **Amendments**: tenant amendment requests with versioned changes, platform approve/reject with
  financial impact, superseding outstanding quotes and resetting acceptance.
- **Cancellation**: reason, fee percentage and refund calculation recorded in `cancellation`.
- **Presenter** (`StocktakePresenter`):
  - Tenant payload omits worker budget, breakdown, platform fee, margin and internal notes
    (`internal` is always `null`); only tenant charge, deposit and timeline are exposed.
  - Platform payload nests confidential economics under `internal`.
  - Permission flags (`can_edit`, `can_accept_quote`, `can_verify_deposit`, `can_issue_quote`,
    `can_review`, `can_decide_amendments`, …) drive the UI.
- **Platform queue** (`PlatformStocktakeJobController`):
  - Drafts are excluded from the queue and cannot be opened by platform users.
  - Buckets: new requests, awaiting deposit, under review, quotes pending (issued, awaiting
    acceptance, awaiting balance, balance in verification), ready to execute, active jobs.
- **Authorization**:
  - Tenant: `stocktake.request.create|submit|cancel`, `stocktake.quote.view|accept`,
    `finance.payment.record`, results/report permissions for read access.
  - Platform: `stocktake.job.review`, `stocktake.quote.issue`, `finance.payment.record`,
    `pricing.manage`.
  - Job queue/detail reads are available to review, quoting and finance roles through an OR
    permission check so finance can verify deposits from the UI.
  - Every query is tenant-scoped; nested binding is scoped through `Tenant::jobs()`.
- **API surface** (`routes/api.php`, 21 operations):
  - `GET|POST /tenants/{tenant}/stocktake-jobs`
  - `POST /tenants/{tenant}/stocktake-jobs/estimate`
  - `GET|PATCH /tenants/{tenant}/stocktake-jobs/{job}`
  - `POST /tenants/{tenant}/stocktake-jobs/{job}/{submit|deposits|quote/accept|quote/decline|cancel|amendments}`
  - `GET /platform/stocktake-jobs`, `GET /platform/stocktake-jobs/{job}`
  - `POST /platform/stocktake-jobs/{job}/{review|request-information|quotes|deposit/waive}`
  - `POST /platform/stocktake-jobs/{job}/deposits/{payment}/verify`
  - `POST /platform/stocktake-jobs/{job}/amendments/{amendment}/decision`
  - `GET|PATCH /platform/stocktake-policy`

## Delivered — frontend

- **`/business/stocktaking`** — request list with five buckets (drafts, awaiting deposit, with
  BRATHAMATU, active, closed), search, status/priority filters, quoted or provisional value,
  deposit progress and pagination.
- **`/business/stocktaking/new` and `/{jobId}/edit`** — three-step wizard:
  1. Request details, job type, priority, schedule and branch/location scope.
  2. Counting plan, team size, product scope, site requirements, qualifications, evidence and
     instructions.
  3. Summary, catalogue readiness and live provisional estimate with deposit.
  - Save draft or submit; submission is disabled until the catalogue is ready.
- **`/business/stocktaking/{jobId}`** — tenant detail:
  - Clarification banner with edit/resubmit when BRATHAMATU requests information.
  - Quote breakdown with accept/decline (accept disabled while the deposit is outstanding).
  - Deposit panel with payment form and payment history.
  - Amendments list and request form; draft edit link; cancellation with refund notice.
  - Request details and full status timeline. No worker compensation or margin anywhere.
- **`/platform/stocktaking-jobs`** — queue with six buckets, filters, tenant/requester columns,
  status badges and policy link.
- **`/platform/stocktaking-jobs/{jobId}`** — review actions (start review, request information),
  issued quote with the amber **Internal — confidential** panel (worker budget, fee, margin,
  compensation breakdown, internal notes), quote builder, deposit verification (confirm/fail with
  note), waiver and amendment decisions.
- **Quote builder** — tenant charge fields with live subtotal/tax/total and a clearly separated
  internal economics block. Internal values are never sent to the tenant endpoint.
- **`/platform/stocktake-policy`** — estimate charges, priority multipliers, deposit
  type/value/minimum/maximum, due days, balance days, cancellation fee and live example.
- **Navigation**: platform gains “Stock-taking jobs” and “Stocktake policy”, both permission-gated;
  business gains “Stock-taking jobs” plus a dashboard quick action and readiness checklist item.

## Verification

- Backend: `php artisan test` — **49 passed (498 assertions)**.
  - `StocktakeJobTest` (5 tests): full request → deposit → review → quote → acceptance flow;
    tenant deposit override vs fixed platform policy; tenant isolation, workflow guards and
    amendments; clarification edit/resubmit with audit; cancellation fee/refund and permissions.
- Frontend: `npm run test` — **14 passed** (3 files); `npm run build` passes (TypeScript + Vite,
  chunk-size warning only).
- Browser acceptance (`http://127.0.0.1:5173`, in-app browser, Demo Supermarket):
  1. `owner@demosupermarket.test` list showed 4 requests: 1 draft, 3 with BRATHAMATU, quoted and
     provisional values, deposit progress.
  2. Wizard steps 1–3 rendered scope, count plan and estimate; catalogue ready; critical priority
     produced a **Ksh 6,007.50** provisional total and **Ksh 1,802.25** deposit.
  3. Request **STJ-20261111-GSILMC** was submitted, deposit **Ksh 1,501.88** recorded by M-Pesa,
     confirmed by `finance@brathamatu.test`, reviewed by `ops@brathamatu.test`, quoted
     **STQ-20261111-VKDDIX** (tenant total **Ksh 4,466.00**, internal worker budget
     **Ksh 2,100.00**, fee **Ksh 900.00**, margin **Ksh 1,750.00**) and accepted by the owner.
  4. Tenant detail showed only tenant charge lines, deposit and timeline — no internal figures.
  5. Platform detail showed the confidential internal panel and the same tenant quote.
  6. Finance could open the queue and verified a bank deposit from the UI (Confirm/Fail + note);
     review actions were absent for finance.
  7. `branch@demosupermarket.test` saw the issued quote for **STJ-20261111-N3I9CU** with no
     accept/decline controls and no internal figures.
  8. The owner accepted **STQ-20261111-UFGCFC** through the UI; status moved to
     **Awaiting balance payment** and the timeline recorded the acceptance.
  9. Policy page loaded values and saved successfully (“Stocktake policy updated…”).
- Evidence: `docs/evidence/phase-06/01-…13-…png` (list, detail, queues, policy, verification,
  branch-manager view, acceptance, wizard steps, quote builder, policy save).

## Notes / deferred

- Balance payment, execution approval, assignment and payout belong to Phases 7–8 and 14; Phase 6
  ends at `awaiting_balance_payment` after quote acceptance.
- Deposits recorded through the tenant UI remain pending until platform finance confirms them;
  browser redirects cannot mark a payment successful.
- Quote issuance keeps the existing deposit amount by default; the quote’s balance uses the
  confirmed paid amount, not a re-derived deposit.
- Platform and tenant payloads are built by separate presenter branches so confidential economics
  cannot leak through a shared serializer.
- Demo accounts and credentials remain local/staging only and must never be enabled in production.
