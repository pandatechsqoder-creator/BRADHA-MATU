# Phase 10 — Count engine

Status: **passed** (backend tests, frontend build, manual browser verification)
Date: 2026-11-12

## Objective

Deliver the full count capture layer: blind/guided count sessions pinned to the job catalogue version, idempotent entry upsert with unit conversion, revision history, exception and unknown-product reporting, session submission locks, and read access for platform supervisors and tenant reviewers. Exit criterion: **a worker can count assigned products but cannot modify the catalogue or expected quantities; all count submissions are traceable.**

## Delivered — backend

- **Schema** (`2026_11_11_130000_create_count_engine_tables.php`):
  - `count_sessions` — tied to job + assignment + catalogue version; pins the frozen scope and count mode (blind/guided).
  - `count_session_locations` — optional location breakdown within a session.
  - `count_entries` — quantity, raw unit, normalised quantity, condition observations, notes, idempotency key, offline capture time vs sync time.
  - `count_entry_revisions` — append-only trail of previous values with reason and actor; original entry is never deleted.
  - `count_exceptions` — mismatched barcode / wrong label / unexpected variant / packaging / other reports with status.
  - `unknown_product_reports` — unlisted product reports with barcode, name guess, evidence JSON.
  - `count_evidence` — polymorphic evidence file reference table.
  - `count_submission_locks` — locks a session at submission; prevents any further mutation.

- **Models**: `CountSession`, `CountEntry`, `CountEntryRevision`, `CountException`, `UnknownProductReport`, `CountEvidence`, `CountSubmissionLock`, `CountSessionLocation` — all with `HasPublicId` (uuid route-binding, ULID generation).

- **CountService** (`app/Services/Count/CountService.php`):
  - `workspace()` — non-throwing readiness summary (gates from `JobOperationsService`), assigned scope, and open session for the worker dashboard.
  - `startSession()` — idempotent; pins `catalogue_version_id` and a frozen scope JSON snapshot at session start; reuses an open session if one exists for the assignment.
  - `upsertEntry()` — validates variant is in scope, unit is catalogue-approved; normalises quantity via `factor_to_base`; reuses existing row on idempotency key match. Never writes expected stock, valuation or cost.
  - `reviseEntry()` — saves the current row to `count_entry_revisions` before applying the new value; requires a reason.
  - `reportException()` / `reportUnknownProduct()` — catalogue anomaly reporting; workers cannot create or edit catalogue products.
  - `submit()` — acquires a `CountSubmissionLock`, marks all entries `submitted`, transitions job `in_progress → count_submitted` via `StocktakeWorkflow`. Idempotent: re-submitting a submitted session returns without error.
  - `platformCounts()` / `tenantCounts()` — read views for platform and tenant roles; no compensation, quote or margin in any payload.

- **Controllers**:
  - `WorkforceCountController` — workspace, start session, session detail, upsert/revise entry, exception, unknown product, submit — all ownership-scoped via `WorkforceProfileService`.
  - `PlatformCountController` — read-only job counts for platform roles.
  - `TenantCountController` — read-only job counts for tenant roles, using `ChecksTenantAccess` for isolation.

- **Routes** (added to `routes/api.php`):
  - `GET  /workforce/assignments/{assignment}/count` — workspace
  - `POST /workforce/assignments/{assignment}/count/sessions` — start session
  - `GET  /workforce/assignments/{assignment}/count/sessions/{session}` — session detail
  - `POST /workforce/assignments/{assignment}/count/sessions/{session}/entries` — upsert entry
  - `PATCH /workforce/assignments/{assignment}/count/sessions/{session}/entries/{entry}` — revise entry
  - `POST /workforce/assignments/{assignment}/count/sessions/{session}/exceptions`
  - `POST /workforce/assignments/{assignment}/count/sessions/{session}/unknown-products`
  - `POST /workforce/assignments/{assignment}/count/sessions/{session}/submit`
  - `GET  /platform/stocktake-jobs/{job}/counts`
  - `GET  /tenants/{tenant}/stocktake-jobs/{job}/counts`

- **Permissions**: routes use existing `stocktake.count.view`, `stocktake.count.submit`, `count.exception.create` permissions already seeded and granted to all worker roles in Phase 2 RBAC.

## Delivered — frontend

- **`/workforce/assignments/:assignmentId/count`** (`WorkerCountPage`):
  - Gate card shown when `can_start_count` is false — lists blockers, links to operations workspace.
  - Blind/guided mode badge (default blind — no expected quantity, valuation or cost shown).
  - Start session button (idempotency key based on assignment + timestamp).
  - Inline record-count form: product search by SKU/name/barcode, quantity + unit selection from catalogue-approved units only, condition flags (good/damaged/expired/quarantined/missing_label/inaccessible/unknown), notes.
  - Inline revision form: requires a reason; shown per entry in the entry list.
  - Inline exception report form (mismatched barcode / wrong label / unexpected variant / packaging / other).
  - Inline unknown product form.
  - Entry list: quantity + unit, condition flags, revision count, draft/submitted badge, revise action.
  - Exceptions and unknown products summary cards.
  - Submit button: warning, error display, locks the session.
  - Assigned scope table (collapsed by default, SKU/product/approved units).

- **`WorkerAssignmentOperationsPage`**: added "Open count workspace →" button when `readiness.can_start_count` is true.

- **`WorkforceAssignmentsPage`**: "Start counting →" link on accepted cards when `job.status === 'in_progress'`.

- **Platform job page** (`PlatformStocktakeJobPage`): `JobCountsPanel` embedded below the operations panel for jobs in execution/review/closed statuses. Shows session summary stats, per-session entries/exceptions/unknown products.

- **Tenant job page** (`TenantStocktakeJobPage`): same `JobCountsPanel` for `count_submitted` → `closed` statuses. No compensation, quote or margin in any tenant payload.

- **API types and hooks** (`lib/api/count.ts`, `lib/count/hooks.ts`): typed wrappers for all ten count endpoints, TanStack Query queries and mutations with cache invalidation.

- **Router**: `workforceAssignmentCountRoute` added at `/workforce/assignments/$assignmentId/count`.

## Verification

- Backend: `php artisan test` — **71/71 passed (891 assertions)**, zero regressions across all phases.
  - `CountEngineTest` (7 tests, 103 assertions):
    - Gate: counting requires check-in; session start is blocked before gate is met.
    - Blind scope: no expected_quantity, cost_price or valuation in any worker payload.
    - Idempotency: same idempotency key updates the same row; negative quantity rejected; unapproved unit rejected; foreign-tenant variant rejected.
    - Revisions: original quantity saved to `count_entry_revisions` before update.
    - Exceptions and unknown products: 201 created, status open.
    - Submit: locks entries, marks session submitted, transitions job to `count_submitted`, re-submit is idempotent.
    - Isolation: platform and tenant see session summary; compensation and margin absent; foreign tenant gets 404; worker cannot access platform endpoint.
- Frontend: `npm run build` — **333 modules, build succeeded** (TypeScript clean, Vite bundle produced).
- Live: `brathamatu-stocktake-api` health → `db=ok, cache=ok`; Phase 10 migration applied to live DB.

## Notes / deferred

- Offline support (local queue, sync state, conflict resolution, device audit) is Phase 11.
- QA review, independent verification, supervisor sampling and recount logic are Phase 12.
- Tenant result review, reconciliation and disputes are Phase 13.
- Completion confirmation, payouts and finance operations are Phase 14.
- Evidence file upload (photos, barcode images) infrastructure is scaffolded in `count_evidence` but upload endpoints and storage are deferred to Phase 19 (reporting/evidence) or Phase 20 (security hardening).
- GPS geofencing for check-in is optional; the `latitude`/`longitude` capture on session scope location is in the schema for future use.
