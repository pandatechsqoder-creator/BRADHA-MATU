# Phase verification audit and remediation task list

Date: 2026-11-15
Scope: Phases 0–21 of `BRATHAMATU_STOCKTAKE_MASTER_IMPLEMENTATION.md`, checked against code, test suites and a running browser.

## 1. Method

- Read the master spec (phase definitions §24, required routes/screens §18.4/§19, testing §22, seed scenarios §23.3, execution §29) and every phase report in `docs/phases/`.
- Ran the backend suite (`php artisan test`), frontend tests (`npm test`) and type-check/build (`tsc -b` / `npm run build`).
- Probed the API with real sanctum tokens for platform, tenant and workforce roles.
- Drove the running app (Laravel `127.0.0.1:8000`, Vite `127.0.0.1:5173`, MySQL `:3307`) with a browser for `/platform/*`, `/business/*`, `/workforce/*` and `/pos`.

## 2. Verification results (measured, not claimed)

> Historical audit snapshot. For the post-remediation state see §8 (all suites green, POS and
> Phases 10–17 browser-verified).

| Check | Result |
| --- | --- |
| `php artisan test` at HEAD | **Fails**: 112 failed / 2 passed. Root cause is the Phase 17 migration (`ArgumentCountError`), which aborts the whole suite. |
| `php artisan test` with Phase 16/17 POS artifacts temporarily excluded | **108 passed, 1322 assertions** — proves Phases 0–15 are intact. Artifacts were restored afterwards. |
| `php artisan route:list` | **Fails**: `ReflectionException: Class "PosStocktakeController" does not exist`. |
| POS API (`/api/v1/.../pos/*`) | **500** — PHP parse error in `PosService.php`. |
| Phase 17 tenant endpoints | **403** — route block is nested inside the platform group. |
| `npm test` | **18 passed** (Vitest does not type-check, so this passes despite a broken build). |
| `npm run build` / `tsc -b` | **Fails** — `src/features/pos/PosStocktakePage.tsx` is syntactically invalid (10 errors, first at line 26). |
| Browser, platform role | Shell, tenants, workforce, finance and payout tabs render (empty states; no domain seed data). |
| Browser, tenant owner | `/business/locations`, `/team`, `/catalogue`, `/inventory`, `/stocktaking`, `/schedules` render. |
| Browser, workforce | `/workforce/assignments`, `/profile`, `/earnings` render. |
| Browser, POS | `/pos` crashes: "input is a void element tag and must neither have children…" (React runtime error). |
| Browser, POS stock-taking | No `/pos/stocktake` route exists in the router; page file is unwired and malformed. |
| Browser evidence folder | Screenshots exist only for Phases 02–09. Nothing for 10–17. |

Headline: Phases 0–15 are real and backend-complete. **Phases 16 and 17 are broken and unverified. Phases 18–21 do not exist beyond scaffolding/stubs.** The frontend at HEAD does not build.

## 3. Phase-by-phase status

Status legend: **Verified** = tests + browser evidence present; **Code-complete, unverified** = tests pass but no browser evidence; **Broken** = does not run; **Not started**.

| Phase | Report claim | Actual status | Notes |
| --- | --- | --- | --- |
| 0–1 | README says done | Code-complete, report missing | No `phase-00.md` / `phase-01.md`. Foundation proven indirectly by later phases. |
| 2–9 | passed, browser-verified | **Verified** | Reports + screenshots in `docs/evidence/phase-0X/`. |
| 10 | passed (manual browser) | **Verified** | Browser walkthrough with a 7-entry live session; see `docs/evidence/phase-10/browser-walkthrough.md`. |
| 11 | passed (tests/build) | **Verified** | Offline package + heartbeat exercised; UI shows cached-package banner and blind count. See `docs/evidence/phase-11/browser-walkthrough.md`. |
| 12 | passed (tests/build) | **Verified** | QA review with 1 finding, 100% accuracy, verification-task form; see `docs/evidence/phase-12/browser-walkthrough.md`. |
| 13 | passed (95/95) | **Verified** | Disputed review, reconciliation and dispute thread browser-verified; see `docs/evidence/phase-13/browser-walkthrough.md`. |
| 14 | passed (101 tests) | **Verified** | Finance overview balanced; payout batch pending checker approval with real earnings; see `docs/evidence/phase-14/browser-walkthrough.md`. |
| 15 | passed (108 tests, 18 FE) | **Verified** | Schedules list + detail with skipped/pending occurrences; schema/Table defects fixed; see `docs/evidence/phase-15/browser-walkthrough.md`. |
| 16 | passed for sell/reconcile scope | **Verified** | Migrations + service repaired; POS sell, receipt, shift close/reconcile and inventory movement browser-verified. Returns/transfers and supplier/purchase UI remain gaps. See `docs/phases/phase-16.md`. |
| 17 | **passed** | **Verified** | Routing, controller, service and page repaired; readiness, commission, authorise, decline and cancel browser-verified. See `docs/phases/phase-17.md`. |
| 18 | spec only | **Not started** | No external POS/ERP integration code. |
| 19 | spec only | **Not started** | Reporting/support/notifications are placeholders/deferred items. |
| 20 | spec only | **Not started** | Real payout adapters, security/perf hardening outstanding. |
| 21 | spec only | **Stubs only** | Playwright specs exist but target `localhost:3000`, use non-existent routes, and Playwright is not installed. |

## 4. Defect detail

### Phase 16 — POS core (blocking)

1. `backend/database/migrations/2026_11_14_000001_add_pos_stocktake_integration.php:26`
   `$table->enum('mode')->default('proposed');` — enum without allowed values throws `ArgumentCountError` and aborts every test run.
2. Same migration creates `pos_stocktake_requests` with `->constrained('pos_vendors')` and `->constrained('prepaid_agreements')`; `pos_vendors` does not exist and `prepaid_agreements` is created after the referencing table.
3. Stray duplicate `backend/database/migrations/2026_11_14_105635_2026_11_14_000001_add_pos_stocktake_integration.php.php` with an empty `up()`.
4. `backend/app/Services/Pos/PosService.php` — `closeShift()` (line 81) is not closed before `createSale()` (line 101): `syntax error, unexpected token "public"`. All POS endpoints 500.
5. `frontend/src/features/pos/PosTerminalPage.tsx:174` passes children to `<Field>`, whose `ui.tsx` implementation spreads props onto `<input>` — React throws on every `/pos` load.
6. Barcode "scanning" is fake (`PosTerminalPage.tsx:66`): any input becomes `Scanned Item (…)` at price 0, no catalogue lookup.
7. No screens/APIs yet for purchases, suppliers, returns, transfers, customers (spec Phase 16 scope).
8. `frontend/src/lib/api/pos.ts` was structurally corrupted (interleaved schemas, unclosed objects, duplicate types) and also used Zod v3 `.size()` under Zod v4. Repaired during this audit (see §6).

### Phase 17 — POS stock-taking integration (blocking)

1. `backend/routes/api.php:450–463` — the block is inside the platform-prefixed group, so tenants get 403; `PosStocktakeController` is never imported; permission strings are malformed (`'pos.stocktake commission'`, `'pos.sales create'`, comma lists).
2. `backend/app/Http/Controllers/Api/V1/Tenant/PosStocktakeController.php` — no imports for `User`/`PosSale` despite method type-hints; calls `$user->can('pos.stocktake commission')` style strings.
3. `backend/app/Services/Pos/PosStocktakeService.php:11` imports non-existent `App\Models\PosVendor`; line 27 uses `$tenant->prepaid_agreement_id`; line 36 writes a dozen `stocktake_jobs` columns that do not exist (`pos_commissioned`, `stocktake_type`, `authorised_by`, `scope_config`, `recurrence_config`, `mode`, `frequency`, `starts_at`, `ends_at`, `prepaid_agreement_id`, …).
4. `frontend/src/features/pos/PosStocktakePage.tsx` — hooks called conditionally before declaration, `error` used before `useState`, unclosed JSX. Not imported by the router, but it breaks `tsc -b`, so `npm run build` fails.
5. `backend/tests/Feature/PosStocktakeTest.php` never runs because the migration aborts the suite first.

### Cross-cutting

- No implementation of master-spec §23.3 seed scenarios. `DemoDataSeeder.php` creates tenants/branches/users only: no products, jobs, payments, disputes, payouts, counts, schedules or offline-conflict data. This is why most UI states are untestable and why Phase 10–15 browser evidence is missing.
- Documentation drift: `README.md:96` still says the latest report is phase-08; `docs/api/openapi.yaml:4` is `1.0.0-phase8`; `docs/BACKLOG.md` leaves 16–21 unchecked and phase-15 sub-items partially unchecked; phase-16 report does not exist.
- Tenant-owner UI login did not fire a POST during browser testing (token had to be injected via API). Needs confirmation/fix before browser acceptance can be repeated reliably.

## 5. Task list

### P0 — unblock and finish Phases 16–17

| ID | Task | Acceptance |
| --- | --- | --- |
| T-01 | Fix Phase 16 migrations: give `mode` its allowed values, correct FK creation order, create `prepaid_agreements` before referencing it, add missing `pos_vendors` or drop the FK, delete the stray duplicate migration file. | `php artisan migrate` on a clean DB and on the live DB; `php artisan test` no longer aborts. |
| T-02 | Fix `PosService.php` brace error; add/extend tests for shift close and sale creation. | POS endpoints return 200/201; `PosTest` green. |
| T-03 | Fix POS UI: `Field` usage (or make `Field` a wrapper), replace fake barcode entry with catalogue-backed lookup, verify sale + close-shift in browser. | `/pos` renders, scan/add/pay flow works, no console errors, screenshots saved. |
| T-04 | Repair Phase 17 routing: import the controller, move the block to the tenant group, use real permission names, register `PosStocktakeController` methods. | `route:list` has no errors; tenant token gets 2xx; platform token gets 403. |
| T-05 | Reconcile `PosStocktakeService` with the real `stocktake_jobs` schema and create `PosVendor`/`prepaid_agreements` support (or add a proper migration). | Commission/authorise/decline/fetch-eligibility succeed; `PosStocktakeTest` green. |
| T-06 | Rewrite `PosStocktakePage.tsx` correctly and wire `/pos/stocktake` into `router.tsx`. | `tsc -b` clean; page loads; readiness + commission form work end-to-end. |
| T-07 | Apply migrations to the live DB, run the full backend suite and frontend build. | `php artisan test` all green; `npm run build` clean. |
| T-08 | Browser-verify POS and POS stock-taking, capture evidence, write `phase-16.md` and update `phase-17.md`. | Screenshots + walkthrough in `docs/evidence/phase-16/`, `phase-17/`; reports marked passed with commands/outputs. |

### P1 — close verification gaps in Phases 0–15

| ID | Task | Acceptance |
| --- | --- | --- |
| T-09 | Implement master-spec §23.3 seed scenarios (valid/invalid catalogue, duplicates, jobs across lifecycle, payments, disputes, payouts, schedules, offline conflict) as a dedicated seeder. | One command seeds a demo tenant that exercises every phase UI with realistic data. |
| T-10 | Browser-verify Phases 10–15 with seeded data; fix any defects found; add screenshots. | `docs/evidence/phase-10…15/` populated; statuses updated honestly. |
| T-11 | Finish Phase 15 deferred work: review-window notifications, demand forecast hand-off, month-end/leap-year occurrence tests. | New tests pass; notification delivered for upcoming occurrence. |
| T-12 | Confirm and fix tenant-owner login form (no POST observed). | Browser login works without token injection. |
| T-13 | Add `phase-00.md` / `phase-01.md` and backfill missing exit-criteria evidence. | Reports exist and match implementation. |

### P2 — deliver remaining phases

| ID | Task | Acceptance |
| --- | --- | --- |
| T-14 | Phase 16 remaining scope: purchases, suppliers, customers, returns, transfers, reconciliation screens/APIs. | Spec §16 flows usable in browser; tests green. |
| T-15 | Phase 18 external POS/ERP integrations (adapters, mapping, webhooks, retries). | Integration sandbox round-trip verified. |
| T-16 | Phase 19 reporting, demand forecast, support and notification centre. | Reports/support screens replace placeholders; notifications delivered. |
| T-17 | Phase 20 security/perf/prod readiness: real M-Pesa/bank payout adapters, signed callbacks, expense claims, hardening, load checks. | Adapters tested against sandbox; security checklist signed off. |
| T-18 | Phase 21 E2E: install/configure Playwright, replace stubs with real specs against the running app, run full lifecycle acceptance. | `npx playwright test` green; final acceptance report. |

### P3 — documentation and process

| ID | Task | Acceptance |
| --- | --- | --- |
| T-19 | Sync docs: `README.md` phase status, `BACKLOG.md` 15–21, `openapi.yaml` (version + POS/stock-taking paths), ERD, phase index. | No stale phase references remain. |
| T-20 | Review and keep the `frontend/src/lib/api/pos.ts` repair made during this audit (schema order, `.length(3)` for Zod v4). | File reviewed; change preserved in version control. |
| T-21 | Enforce phase exit gate per spec §24/§29: backend tests + type-check build + browser evidence recorded before a phase is marked passed. | Checklist in phase template; CI runs tests and build. |

## 6. Changes made during this audit

- Repaired `frontend/src/lib/api/pos.ts` only: restored schema ordering, removed duplicated/interleaved blocks, fixed Zod v4 incompatibility (`.size(3)` → `.length(3)`). No backend source was modified.
- Phase 16/17 artifacts were temporarily moved aside to prove Phases 0–15 pass (108 tests) and then restored.
- Background services used for verification: Laravel `:8000`, Vite `:5173`, MySQL `:3307`.

## 7. Bottom line

The project is genuinely complete through Phase 15 at the backend, but claim-vs-reality diverges from Phase 10 onward: browser evidence stops at Phase 09, Phase 16 has no report, Phase 17 is explicitly unfinished, and the repository at HEAD does not pass its own test suite or frontend build. Phases 18–21 have not been started. T-01 through T-08 are prerequisites for any claim that the system works end-to-end; T-09 unblocks credible verification of everything after Phase 09.

The historical defects above were remediated in the follow-up work recorded in §8.

## 8. Remediation update (2026-11-15, post-audit)

### Suites

| Check | Result (2026-11-15) |
| --- | --- |
| `php artisan test` | **147 passed (1714 assertions)**, 144.8s — includes Phase 16 `PosTest`, Phase 17 `PosStocktakeTest`, Phase 18 `IntegrationTest`, Phase 19 `ReportingSupportTest`, Phase 20 `ReadinessTest` and Phase 21 `OpenApiContractTest`. |
| `npx tsc -b` | Clean (no output). |
| `npm run build` | Clean — `dist/assets/index-Dirce1wH.js` 1,013.18 kB / 260.26 kB gzip, CSS 29.76 kB. (Chunk-size warning only.) |
| Vitest | 6 files, 18 passed (Playwright specs excluded via `vite.config.ts`). |
| Playwright E2E | **20 passed / 20**, 25.2 minutes (`npm run test:e2e`). |
| `php artisan route:list` | No reflection errors; 219 `api/v1` paths / 273 operations match `docs/api/openapi.yaml`. |

### Fixed

- POS migrations: enum values, FK order, duplicate migration removed; `PosService` brace error fixed.
- POS UI: `Field` now supports select/textarea children (also fixed the QA page crash); barcode entry
  replaced with catalogue-backed lookup; verified sale + receipt + close/reconcile.
- Phase 17 routing/controller/service reconciled with the real schema; readiness, commission,
  authorise, decline and cancel worked in the browser.
- Supervisor platform job list/show allow `stocktake.job.monitor`.
- Schedules: relaxed Zod schemas to match the lean API; `Table` no longer renders empty rows under
  populated data.
- Demo data rebuilt to master-spec §23.3-style coverage via `backend/scripts/demo_cleanup.php` +
  `demo_lifecycle.php` (catalogue, jobs at every lifecycle stage, payments, disputes, payout,
  schedules, counts, exceptions, unknowns, offline package). The scenario-by-scenario map is in
  `docs/evidence/seed-scenarios.md`.
- Tenant-owner login confirmed working through the UI form (password `Password1!`).
- Phase 14 re-verified after the rebuild: batch `PAY-01M7FSC24V2MJDR6S667AP2PM4` moved
  pending approval → approved → processed, the payout queue emptied, and job `STJ-20261115-2J5SJX`
  reached **Payout completed**.
- Phase 18 external integrations delivered: encrypted connections, CSV/API pull, field mapping,
  conflicts, scheduled sync, HMAC webhook receiver with SSRF guard, business UI (see phase-18 evidence).
- Phase 19 reporting/support/notifications delivered: report catalogue + CSV export with run history
  and audit, tenant/platform analytics with AI interface contract, support ticket lifecycle with SLA
  timers and platform queue, notification centre with preferences (see phase-19 evidence).
- Phase 20 hardened: security headers + API CSP, API and login rate limiters, expanded readiness probes
  (`health:check`), privacy retention purge with audit, portable backup/restore with a verified
  146-table drill, CI pipeline, deployment/backup/monitoring runbooks, privacy review and production
  checklist (see `docs/evidence/phase-20/readiness.md`; external legal/accounting sign-off remains).
- Phase 21 delivered: Playwright acceptance suite (20 tests) covering role access, 31 screens,
  POS sale, reports/CSV, support lifecycle, notifications, responsive layouts and the API envelope;
  OpenAPI brought to 219 paths / 273 operations with an enforcing contract test; API requests without
  an `Accept` header now return JSON 401/404 envelopes with request ids; production seeding restricted
  to RBAC (see `docs/phases/phase-21.md` and `docs/evidence/phase-21/screens/`).

### Browser evidence added

`docs/evidence/phase-10/browser-walkthrough.md` … `phase-15/browser-walkthrough.md`,
`phase-16/pos-walkthrough-2026-11-15.md`, `phase-17/pos-stocktake-walkthrough-2026-11-15.md`,
`phase-18/integrations-walkthrough.md`, `phase-19/reporting-support-walkthrough.md`,
`phase-20/readiness.md` (+ load-test/benchmark JSON), `phase-21/screens/` (8 PNGs captured by the
Playwright suite) and `docs/phases/phase-21.md`.
Screenshots from the in-app browser were limited earlier by `NATIVE_BROWSER_VIEWPORT_UNAVAILABLE`;
the Phase 21 PNGs were captured by headless Chromium instead, and the Phase 13 PNGs are retained.

### Still outstanding (honest scope)

- Production external actions: legal/accounting retention sign-off, live provider credentials, hosted
  staging topology/load validation and a staging penetration test (`docs/PRODUCTION_CHECKLIST.md`).
  Engineering acceptance (Phase 21) is complete; release approval remains an owner decision.
- Phase 15 deferred items: review-window notifications, demand-forecast hand-off, month-end/leap-year
  occurrence tests.
- Seed-scenario gaps (`docs/evidence/seed-scenarios.md`): payout failure/retry has no implementation
  or test, annual schedules are accepted but untested, and the payment-failure status has no
  dedicated test.
- Phase 16 POS receipt has no printable/downloadable view; POS returns/transfers are covered by
  navigation but not by a dedicated E2E flow.
