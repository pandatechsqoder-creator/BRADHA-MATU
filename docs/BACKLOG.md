# Implementation Backlog — mapped to master specification phases

Source of truth: `BRATHAMATU_STOCKTAKE_MASTER_IMPLEMENTATION.md` (v1.0, 2026-11-11).
Status legend: [ ] not started · [~] in progress · [x] passed (evidence in docs/evidence/)

## Phase 0 — Repository discovery and technical baseline [x]
- [x] Inspect workspace (empty; only master spec present — nothing to preserve)
- [x] Confirm versions: PHP 8.4.24, Composer 2.10.2, Node 24.11.1, MySQL 8.0.46, Redis 6379
- [x] ADR 0001 (technology baseline, ports, auth model)
- [x] Backlog (this file)
- [x] ERD v1 + OpenAPI baseline + threat model (initial versions; expanded per phase)

## Phase 1 — Project foundation and local browser launch [x]
- [x] Laravel 13 setup, MySQL connection, migrations
- [x] `/api/v1` skeleton, response/error envelope, request IDs, logging, health endpoint
- [x] Redis cache/queue foundation
- [x] TanStack React + TS, Router, Query, typed API client, design system primitives
- [x] App shell, error boundaries, login/register backed by real API
- [x] Exit: both apps run locally; browser login works; health + API smoke tests pass

## Phase 2 — Identity, roles and tenant isolation [x]
- [x] Auth hardening, email verification adapter (test mode)
- [x] Users, roles, permissions, memberships; tenant registration + organisation setup
- [x] Role-specific dashboards/navigation foundations
- [x] Tenant-scoped policies, audit logs, cross-tenant security tests (backend + browser)

## Phase 3 — Organisation, branches and locations [x]
- [x] Branches, departments, warehouses, zones, bins; invitations and access scopes
- [x] Organisation settings/timezone; browser management screens

## Phase 4 — Catalogue onboarding and pricing [x]
- [x] Catalogue/product/variant/unit/barcode schema
- [x] CSV/XLSX queued import, mapping, preview, row errors, duplicate resolution
- [x] Manual catalogue management, versioning, declaration
- [x] Configurable catalogue pricing policy + invoice (KSh 100/100 → KSh 2,000 cap points preserved)
- [x] Request creation blocked until catalogue readiness met (readiness gate exposed; request phase consumes it)

## Phase 5 — Inventory snapshots and movement controls [x]
- [x] Balances, movements, cut-off process, expected-stock snapshots
- [x] Batch/serial/expiry; external movement upload foundation; inventory dashboards

## Phase 6 — Stock-taking request, review and quotation [x]
- [x] Request wizard, scope selection, workflow states
- [x] Deposit policy configuration; quote builder (tenant charge vs worker compensation separated)
- [x] Amendments, approval history, tenant quote acceptance

## Phase 7 — Payments and financial ledger [x]
- [x] Provider abstraction; M-Pesa sandbox adapter + idempotent callbacks
- [x] Card/bank/manual adapters; payment allocation
- [x] Double-entry ledger (balanced journals, reversal-not-edit); reconciliation/refund foundations

## Phase 8 — Workforce onboarding and assignment — done (2026-11-11)
- [x] Registration, verification, skills, service areas, availability, training
- [x] Assignment recommendations/offers/acceptance/reassignment; compensation visibility controls

## Phase 9 — SOP and job operations
- [x] Procedure templates/versions, job SOP, acknowledgements, checklists
- [x] Check-in/out, attendance, progress tracking, operations dashboard

## Phase 10 — Count engine
- [x] Blind/guided modes, sessions, entries, barcode/search, quantity-only capture
- [x] Unit conversion, exceptions, unknown-product reports, evidence schema
- [x] Revision history, submission locks

## Phase 11 — Offline capability and field reliability
- [x] Offline job package, local queue, idempotent sync, conflict resolution, device audit

## Phase 12 — QA, independent verification and recount
- [x] QA rules, supervisor review, independent sampling, double counts
- [x] Recount workflows + cost investigation policy; accuracy vs verified counts (not expected-stock variance)

## Phase 13 — Tenant review, reconciliation and disputes
- [x] Results publishing, review window, accept/correction/recount/dispute
- [x] Reconciliation report, adjustment approval, dispute evidence/resolution

## Phase 14 — Completion, payout and finance operations
- [x] Completion confirmation, payout queue, maker-checker, payout adapters
- [x] Refunds/credits/expenses, finance dashboards/statements

## Phase 15 — Recurring and on-demand schedules
- [x] All frequencies, multiple schedules/scopes, three commercial modes
- [x] Occurrence generation (no duplicates), reschedule/skip/pause/resume
- [ ] Notifications, demand forecast, month-end/leap-year tests

## Phase 16 — BRATHAMATU POS core
- [x] Terminals, shifts, sales, barcode, payments, receipts
- [x] Inventory movement integration; cash reconciliation (shift close + reconcile)
- [x] Purchases, suppliers, customers, returns, transfers (back office screen + APIs)

## Phase 17 — POS stock-taking service integration
- [x] Readiness, request-from-POS, quote/deposit handoff, plan policies; explicit tenant authorisation
- [x] Commission, authorise, decline/cancel and commission history (browser-verified 2026-11-15)

## Phase 18 — External POS/ERP integrations [x]
- [x] Provider framework, CSV/API sync, credentials, mapping, logs, retries, webhooks
- Evidence: `docs/evidence/phase-18/integrations-walkthrough.md`

## Phase 19 — Reporting, support and platform intelligence [x]
- [x] Tenant/platform reports, exports, support tickets/SLAs, notifications/preferences
- [x] Analytics overview and AI interface contract (`enabled: false` until provider supplied)
- Evidence: `docs/evidence/phase-19/reporting-support-walkthrough.md`

## Phase 20 — Security hardening, performance and production readiness [x]
- [x] Security headers, API/login rate limits, readiness probes and `health:check`
- [x] Backup/restore commands with verified non-destructive drill; load evidence
- [x] Privacy/retention config + daily purge, CI pipeline, deployment/backup/monitoring runbooks
- [x] Production checklist (external legal/accounting and provider cut-over items remain owner actions)
- Evidence: `docs/evidence/phase-20/readiness.md`

## Phase 21 — Final end-to-end browser acceptance [x]
- [x] Role-based Playwright suite (20/20) across tenant, platform and workforce workspaces
- [x] API/OpenAPI contract validation (219 paths / 273 operations, enforced by test)
- [x] Navigation, responsive layouts, POS sale, reports, support and notifications browser-verified
- Evidence: `docs/phases/phase-21.md`, `docs/evidence/phase-21/screens/`

## Cross-cutting standing rules (from spec §0.2)
- Laravel is authoritative for all business rules; frontend never touches DB.
- Tenant isolation on every record/query/file/export/job; UUID public IDs.
- Financial confidentiality: worker never sees tenant charge/margin; tenant never sees compensation.
- Immutable/revisioned financial, count, approval and audit records.
- Sandbox adapters only until real credentials supplied; clearly labelled test mode.
- Every phase: backend + frontend + permissions + tests + real browser evidence.

## Follow-up — tenant onboarding, inventory and POS UX (2026-11-18)

Audit of the existing implementation found that the requested modules already exist and must be
extended in place:

- Product create/list/import and catalogue versioning use `products`, `product_variants`,
  `catalogues` and `catalogue_versions`. The create query already invalidates catalogue queries.
- Tenant setup currently has organisation creation, tenant settings, branches, departments,
  warehouses, stock locations, invitations, catalogue, inventory and POS screens. There was no
  persistent multi-step tenant setup checklist.
- Catalogue declarations are attached to a catalogue version, not a stocktaking request.
- `stock_locations` are tenant/branch/warehouse scoped. Default Head Office provisioning now uses
  explicit tenant-scoped `is_default` fields on branches and stock locations.
- Warehouse and department update APIs exist, while the tenant structure screen did not expose
  matching edit controls.
- Inventory balance search is paginated and supports product name/SKU, but lacks the requested
  letter, branch, warehouse, category and stock-state filters.
- Product and variant schemas have cost price and variant attributes, but no authoritative product
  selling-price field. POS checkout currently accepts the submitted unit price, so server-side
  pricing and immutable transaction snapshots need implementation before treating POS prices as
  production-authoritative.

Progress:

- [x] Product creation now resets every form field only after a successful API response; failures
  preserve input and show field-specific validation errors. The submit button remains disabled
  during mutation, and the existing catalogue invalidation refreshes the product list.
- [~] Added a tenant-scoped onboarding checklist endpoint and page backed by existing
  `tenant_settings`; completion is derived from real tenant records, optional steps can be skipped
  and resumed, and required steps cannot be skipped or bypassed at final review. This is a checklist
  integration, not yet the full profile/settings wizard.
- [ ] Relocate catalogue declaration acceptance to each stocktaking request without deleting
  historical declarations.
- [x] New and existing tenants receive one idempotent default Head Office branch and a default
  stock location. Default status is explicitly managed in the schema and APIs; tenant owners can
  change defaults safely, and defaults cannot be deactivated before replacement.
- [~] Warehouse and department edit controls are now exposed in the tenant structure screen and
  their update APIs are covered by persistence assertions.
- [x] Inventory now supports server-side product-name-first search, SKU/barcode/brand/category
  search, A-Z/# filtering, branch, warehouse, category, brand and stock-state filters with
  pagination and tenant-isolation coverage.
- [ ] Add backend-authoritative selling prices and POS name-first/alphabetical search while
  preserving cart contents and historical sale prices.
- [ ] Run end-to-end browser acceptance for all workflows and update evidence after the features
  above are complete.

The onboarding API routes are documented in `docs/api/openapi.yaml`; contract parity is enforced
by `OpenApiContractTest`.