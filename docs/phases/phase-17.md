# Phase 17 — POS Stock-Taking Service Integration

Status: **passed** (backend tests, frontend build, browser acceptance)
Date: 2026-11-15

## Objective

Deliver the tenant-facing flow for commissioning a stock-taking job from BRATHAMATU POS:
catalogue readiness, explicit authorisation or a valid prepaid agreement, and job progress.
Exit criterion: **a POS tenant can commission a job only with explicit authorisation or a valid
prepaid agreement.**

## Delivered — backend

- **Schema** (`2026_11_14_000001_add_pos_stocktake_integration.php`): POS commissioning columns
  on `stocktake_jobs` (`pos_commissioned`, `pos_vendor_reference`, `prepaid_agreement_id`,
  `authorised_by`, `authorised_at`, `authorisation_notes`, schedule fields, cancellation).
- **Service** (`app/Services/Pos/PosStocktakeService.php`):
  - `checkCatalogueReadiness()` — counts tenant products against `stocktake.min_products_for_commissioning`
    (default 5) and reports prepaid eligibility.
  - `commissionStocktake()` — with no prepaid agreement the catalogue threshold must be met;
    creates the job as `commissioned_pending_authorisation`. With a resolved prepaid agreement it
    is created `commissioned_authorised` and `authorised_by/authorised_at` stay null because the
    agreement is the authority.
  - Only `commissioned_pending_authorisation` and `in_progress` commissions block a new one, so
    an authorised job does not deadlock the tenant.
  - `authorizeStocktakeJob()` / `declineStocktakeJob()` — explicit tenant decisions with state
    guards; decline records reason, actor and time.
  - `fetchPosStocktakeEligibility()` — readiness, product count, minimum, active shift, open
    commissions, prepaid flag, `can_commission` and block codes.
  - `generateQuoteFromPos()` — quote derived from a POS sale (subtotal, tax, discount, totals,
    line items).
  - `jobPayload()` returns public ids and resolved names (`prepaid_agreement_id` as agreement
    ULID, `authorised_by` as the user name) instead of raw internal foreign keys.
- **Controller/routes** (`PosStocktakeController`, `/tenants/{tenant}/pos/stocktake/*`):
  readiness, eligibility, jobs, commission, authorise, decline, quote-from-sale.
- **Models:** `StocktakeJob::prepaidAgreement()`, `StocktakeJob::authorisedBy()`.
- **Tests:** `tests/Feature/PosStocktakeTest.php` — 8 tests covering readiness, commission
  authorisation, prepaid path, decline, blocking rules, tenant isolation and quote generation.

## Delivered — frontend

- `lib/api/posStocktake.ts` — Zod schemas and typed client for all endpoints.
- `lib/posStocktake/hooks.ts` — TanStack Query hooks with shared cache invalidation.
- `features/pos/PosStocktakePage.tsx` — readiness stat cards (product count/minimum, readiness,
  open commissions, prepaid), block warnings, commission form (title, mode, frequency, team size,
  duration, POS reference, notes), job list with status badges, authorise/decline (reason ≥ 3
  characters) and cancel for authorised jobs.
- Route `/pos/stocktake` (`app/router.tsx`) and "Stock-taking" nav item gated by `pos.manage`
  (`AppShell.tsx`).

## Verification — browser (2026-11-15)

Tenant Demo Supermarket, user `owner@demosupermarket.test`.

1. Readiness showed 8 catalogue products (minimum 5), catalogue ready, 0 open commissions,
   active prepaid agreement `PPA-DSM-2026`.
2. Commissioned "Browser verification cycle count" with POS reference `POS-VERIFY-001` →
   job `POS-ZTCLJYA2`, status `commissioned pending authorisation`; eligibility immediately
   switched to "Not eligible" with block `existing_commissioned_jobs`.
3. Authorised the job → status `commissioned authorised`, "Authorised by Demo Supermarket Owner",
   open commissions back to 0, eligibility restored.
4. Commissioned "Decline verification run" → `POS-A1W0IUNU`; declined with reason
   "Duplicate request during verification" → status `cancelled` with the reason shown.
5. Cancelled the authorised job with reason "Housekeeping during verification" → `cancelled`.

Console: no errors. Network: readiness, eligibility, jobs, commission, authorise, decline calls
all 2xx.

Evidence: `docs/evidence/phase-17/01-commission-authorise-decline.png`.

## Notes / deferred

- Quote/deposit handoff is exposed as an API (`generate-quote/{sale}`) and covered by tests, but
  the POS UI does not yet surface quote creation from a sale.
- Job progress and results inside the POS UI are read from `stocktake_jobs` status; the richer
  progress view lives in the stock-taking job pages.
- Real M-Pesa/card collection, offline commissioning and plan-specific pricing policies remain
  future work.
