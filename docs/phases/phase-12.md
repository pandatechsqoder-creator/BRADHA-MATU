# Phase 12 — QA, independent verification and recount

Status: **passed** (backend tests, frontend build)
Date: 2026-11-12

## Objective

Deliver quality assurance for submitted counts: supervisor QA review, independent sampling/verification, discrepancy classification, recount workflows with cost attribution, and worker accuracy metrics. Exit criterion: **expected stock variance is not treated as worker error; verified count accuracy and recount attribution work correctly.**

## Delivered — backend

- **Schema** (`2026_11_11_150000_create_qa_and_recount_tables.php`):
  - `qa_reviews` — supervisor review of a submitted count session (in_progress → passed/failed/recount_required).
  - `qa_findings` — individual discrepancies (type, severity, classification: worker_error/system_error/expected_variance/unknown, variance).
  - `verification_tasks` — independent sampling task assigned to a second worker.
  - `verification_samples` — individual items selected for re-verification (match/mismatch/not_counted).
  - `recount_requests` — formal recount with cost responsibility (platform/worker_error/tenant_request/split/undetermined).
  - `discrepancies` — aggregated discrepancies with type classification and resolution status.
  - `discrepancy_evidence` — evidence attached to discrepancies.

- **QAService** (`app/Services/QA/QAService.php`):
  - `startReview()` — transitions job `count_submitted → quality_review`, creates a QA review.
  - `recordFinding()` — records a discrepancy, auto-creates a `Discrepancy` record, classifies as worker_error/expected_variance/system_error/unknown.
  - `completeReview()` — decision: pass (→ awaiting_tenant_review), fail (→ awaiting_tenant_review with findings), or recount (creates recount_request, → recount_required).
  - `createVerificationTask()` — selects random entries from the submitted session for independent re-verification.
  - `recordVerificationSample()` — records a re-count result, computes variance, marks match/mismatch.
  - `completeVerificationTask()` — summarizes matched/mismatched counts.
  - `requestRecount()` / `approveRecount()` — approve transitions `recount_required → in_progress` (re-opens counting for workers).
  - `completeRecount()` — transitions back to `count_submitted`.
  - `resolveDiscrepancy()` — classifies and resolves a discrepancy (e.g. expected_variance, not worker error).
  - `workerAccuracyForJob()` — computes accuracy rate from total entries vs worker_error discrepancies; expected variance does NOT reduce accuracy.

- **Controller** (`PlatformQAController`):
  - 11 endpoints under `/platform/stocktake-jobs/{job}/qa/*` and `/platform/stocktake-jobs/{job}/verification-tasks/*` and `/platform/stocktake-jobs/{job}/recount-requests/*` and `/platform/discrepancies/{discrepancy}/resolve`.
  - Permissions: `qa.review` for review/findings/recount; `verification.manage` for verification tasks; `stocktake.count.recount.request` for recount requests.

- **Workflow transitions used** (already defined in Phase 6):
  - `count_submitted → quality_review` (platform)
  - `quality_review → awaiting_tenant_review` (platform)
  - `quality_review → recount_required` (platform)
  - `recount_required → in_progress` (platform) — re-opens counting
  - `recount_required → quality_review` (platform)

## Delivered — frontend

- **`/platform/stocktaking-jobs/$jobId/qa`** (`QAReviewPage`):
  - Review status card with findings count and entries reviewed.
  - Worker accuracy card — accuracy rate, worker errors, expected variance, system errors. Note that expected variance is not treated as worker error.
  - Count entries table with per-entry finding indicators.
  - Finding form — type, severity, classification (worker_error/expected_variance/system_error/unknown), counted vs verified quantity, notes.
  - Complete review form — decision (pass/fail/recount) with recount reason and cost responsibility.
  - Independent verification task creation — sample size selection, per-sample match/mismatch display.
  - Recount requests with approve/complete actions.
  - All findings listed with classification badges.

- **Platform job page integration**: QA review card shown for `count_submitted`, `quality_review`, and `recount_required` statuses with "Open QA review →" link.

- **API + hooks**: `lib/api/qa.ts` (typed API calls), `lib/qa/hooks.ts` (TanStack Query hooks with cache invalidation).

## Verification

- Backend: `php artisan test` — **86/86 passed (1110 assertions)**, zero regressions.
  - `QATest` (9 tests, 157 assertions):
    - QA review starts and transitions `count_submitted → quality_review`.
    - Finding creates a discrepancy and tracks classification (worker_error with variance -2).
    - Completing with pass → `awaiting_tenant_review`.
    - Completing with recount → creates recount request with cost_responsibility, transitions to `recount_required`.
    - Verification task samples entries, records matches and mismatches, completes with summary.
    - Approving recount reopens job for counting (`recount_required → in_progress`).
    - Resolving discrepancy classifies type (expected_variance, not worker error).
    - Worker accuracy distinguishes worker_error from expected_variance (50% accuracy with 1 worker error out of 2 entries).
    - Non-supervisor cannot access QA endpoints (403).
- Frontend: `npx tsc -b` clean; `npm run build` — built in 9.24s.
- Migration applied to live database.

## Notes / deferred

- Reconciliation reports, reconciliation items, reconciliation approvals and reconciliation adjustments (spec §15.10) are Phase 13 (tenant review, reconciliation and disputes).
- Independent counts and independent count entries tables (spec §15.10) are covered functionally by the verification task system which IS an independent count; a separate table pair was not needed.
- Recount assignments and recount results tables (spec §15.10) are handled by the existing assignment + count session infrastructure — a recount re-opens the job for counting via the same flow.
