# Phase 13 — Tenant review, reconciliation and disputes

Status: **passed** (backend 95/95 tests, frontend build clean)
Date: 2026-11-12

## Objective

Deliver the tenant-facing review flow after QA passes: publish results to the tenant, provide a time-boxed review window, allow accept/correction/recount/dispute actions, generate a reconciliation report, enable per-item flagging, and give platform a full dispute resolution thread. Exit: tenant can review, accept or dispute; adjustments require authorised approval; disputes have complete audit history.

## Delivered — backend

**Schema** (`2026_11_11_160000_create_tenant_review_and_dispute_tables.php`) — 9 tables:
- `tenant_result_reviews` — the review record per job (status, 7-day window, accepted_at).
- `tenant_result_review_items` — per-product counted quantities with pending/accepted/flagged/disputed status.
- `reconciliation_reports` — generated report comparing counted vs expected (blind count → all unknown).
- `reconciliation_items` — per-line match/variance/unknown classification.
- `reconciliation_adjustments` — write-off/write-in/reclassify with approval workflow.
- `stocktaking_disputes` — formal dispute (category, priority, reason).
- `dispute_messages` — threaded communication (tenant/platform roles).
- `dispute_evidence` — photos, documents, notes.
- `dispute_decisions` — platform decision (uphold/overturn/partial/negotiate).

**TenantReviewService** (`app/Services/Review/TenantReviewService.php`):
- `publishResults()` — auto-creates the review + per-product items from submitted count session when the job enters `awaiting_tenant_review`.
- `acceptResults()` → `completion_confirmed`.
- `requestCorrection()` → `tenant_correction_requested`.
- `requestRecount()` → `recount_required`.
- `raiseDispute()` → `disputed`; creates dispute + opening message.
- `flagItem()` — per-item tenant comment.
- `generateReconciliation()` — builds reconciliation report from review items; blind counts result in `unknown` type (no expected quantity in scope).
- `addDisputeMessage()` / `addDisputeEvidence()` — dispute thread.
- `decideDispute()` — platform resolves dispute, transitions job back to `awaiting_tenant_review`.

**Controllers**: `TenantReviewController` (8 tenant endpoints) + `DisputeController` (4 platform endpoints).

**Routes added** — 8 tenant + 4 platform.

## Delivered — frontend

- **`/business/stocktaking/$jobId/review`** (`TenantResultsReviewPage`):
  - Review status card (total/accepted/flagged, decision notes).
  - Action buttons: Accept / Request correction / Request recount / Raise dispute / Generate reconciliation.
  - Accept form with optional notes.
  - Correction form, recount form, dispute form (category/priority/reason/opening message).
  - Per-item flag: inline comment input per row in the results table.
  - Count results table: SKU, product, counted qty, status badge, flag action.
  - Reconciliation report: match/variance/unknown summary + per-line table.
  - Disputes panel: thread messages with tenant/platform role styling, decisions.
- **Tenant job page**: "Review results" / "Open review →" card for `awaiting_tenant_review`, `disputed`, `tenant_correction_requested`, `completion_confirmed` statuses.
- Route auto-publishes results on first tenant access (no separate publish step).

## Verification

- Backend: **95/95 passed (1259 assertions)**, zero regressions.
  - `TenantReviewTest` (9 tests, 149 assertions): view, accept, correction, recount, dispute+message, flag item, reconciliation, dispute resolution, isolation (foreign tenant → 404).
- Frontend: `npx tsc -b` clean; `npm run build` — 9.01s.
- Migration applied to live database.

## Notes / deferred

- Reconciliation adjustment approval (`decideAdjustment`) is exposed as a service method but no route is registered — deferred to Phase 14 (finance operations) where adjustments feed into the ledger.
- Dispute evidence file upload (actual file storage + signed URLs) is Phase 20 (security hardening).
- Review window reminders/notifications are Phase 19.
