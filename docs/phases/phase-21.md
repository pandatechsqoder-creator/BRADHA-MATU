# Phase 21 — Final end-to-end browser acceptance

Date: 2026-11-15
Scope: master specification §24 Phase 21 — role-based browser acceptance, Playwright suite, API/OpenAPI
contract validation, navigation coverage, responsive layouts, production-data checks and the final
acceptance record.

## 1. Environment

| Component | Value |
| --- | --- |
| Backend | Laravel 13 / PHP 8.4 at `http://127.0.0.1:8000` (`php artisan serve`) |
| Frontend | Vite dev server at `http://127.0.0.1:5173` |
| Database | MySQL 8 at `127.0.0.1:3307`, database `panda_stocktake` (demo data per §23.3) |
| Browser | Chromium 149 (Playwright build 1228), headless |
| Driver | `@playwright/test` 1.61.0, one worker, desktop / 768×1024 / 390×844 viewports |
| Accounts | tenant owner `owner@demosupermarket.test`, platform `superadmin@brathamatu.test`, worker `worker@brathamatu.test` (local demo password only) |

## 2. Suite and result

Command: `cd frontend && npm run test:e2e` (`playwright test`, config `frontend/playwright.config.ts`).

**Result: 20 passed / 20, 25.2 minutes, 0 retries** (2026-11-15). HTML report:
`frontend/playwright-report/`. Evidence screenshots: `docs/evidence/phase-21/screens/`.

| Spec | Tests | What it proves |
| --- | --- | --- |
| `e2e/api-contract.spec.ts` | 3 | Health envelope + request id + security headers; JSON 404 envelope; unauthenticated tenant API returns 401 JSON without an `Accept` header. |
| `e2e/auth-roles.spec.ts` | 3 | Tenant owner is denied `/platform`; superadmin reaches platform overview/reports/support; worker reaches assignments/profile/earnings. |
| `e2e/evidence.spec.ts` | 2 | Screenshots for tenant, platform and workforce workspaces and a completed POS cash sale with receipt. |
| `e2e/navigation.spec.ts` | 4 | Every tenant (16), platform (12) and workforce (3) screen renders its heading with no error boundary; sidebar link clicks navigate correctly. |
| `e2e/pos.spec.ts` | 2 | Open shift → catalogue search → add Sugar 1kg → cash tender → completed sale with receipt; unknown barcode shows a clear error. |
| `e2e/responsive.spec.ts` | 3 | Mobile 390×844 dashboard/catalogue/POS; no horizontal overflow; tablet 768×1024 reports and support usable. |
| `e2e/tenant-operations.spec.ts` | 3 | Report generation + CSV download + notice; support ticket create → select → reply; notification centre + preferences. |

The suite drives a real Chromium browser against the running API; nothing is mocked. `settle()` waits
for in-flight fetches after each page so hard navigations do not queue behind aborted requests on the
single-threaded `php artisan serve` process (see §6).

## 3. Supporting verification (same day)

| Check | Result |
| --- | --- |
| `php artisan test` | **147 passed (1714 assertions)** in 144.8s, including the new `OpenApiContractTest` and API-envelope tests. |
| `npx playwright test` | 20 passed. |
| `npm test` (Vitest) | 6 files, 18 tests passed (E2E specs excluded from Vitest). |
| `npx tsc -b` / `npm run build` | Clean; bundle `dist/assets/index-Dirce1wH.js` 1,013 kB (260 kB gzip), CSS 29.8 kB. Chunk-size warning only. |
| `php artisan route:list` | No reflection errors. |

## 4. OpenAPI contract

`docs/api/openapi.yaml` is now `1.0.0` and documents **219 paths / 273 operations**, matching the
registered `api/v1` route table exactly. Previously it stopped at 110 paths (Phase 17), so Phases 9–20
domains (workforce operations, counts, QA, review, payouts, POS, integrations, reporting, support,
notifications) are now present. Routes not covered by hand-written entries are in a generated baseline
block near the end of `paths`.

`backend/tests/Feature/OpenApiContractTest.php` parses the YAML and fails if any registered API
operation is undocumented **or** any documented operation is not registered.

## 5. Defects found and fixed during acceptance

1. **Unauthenticated API request without `Accept: application/json` returned 500**
   (`Route [login] not defined`). Added `App\Http\Middleware\ForceJsonResponse` to the API group, which
   normalises the `Accept` header before auth runs. Now returns the standard 401 envelope.
2. **Unknown API routes had no request id** because `RequestId` only ran inside the API group, which
   never executes for unmatched routes. `RequestId` is now global (`bootstrap/app.php`), so every
   response — including 404s — carries `X-Request-Id` and `error.request_id`.
3. **OpenAPI drift** (110 of 219 paths documented). Generated the missing baseline and added the
   contract test above.
4. **Demo fixtures could be seeded in production.** `DatabaseSeeder` now runs RBAC only when
   `APP_ENV=production`; demo catalogue/POS fixtures are skipped with a warning.
5. **Accessible names were missing** on several form controls used by the acceptance suite
   (POS, support, reports, platform support). Added `name`/`id` attributes so labels are programmatically
   associated.
6. **Vitest collected the Playwright specs** after they were added, failing collection for 7 files.
   `vite.config.ts` now excludes `e2e/**`; Vitest is 6/6 files, 18/18 tests.

Regression coverage for defects 1–2 lives in `backend/tests/Feature/HealthTest.php`; defect 4 is
enforced by the seeder guard and documented in `docs/PRODUCTION_CHECKLIST.md`.

## 6. Known limitations (honest)

- **Staging:** the suite ran against the local staging-equivalent stack (localhost, seeded demo data),
  not a separate hosted staging environment. The config accepts `E2E_BASE_URL` and
  `E2E_API_URL` for a hosted target.
- **Dev-server latency:** `php artisan serve` is single-threaded on Windows. Each API request pays a
  framework boot (~0.8–1.2 s) and requests serialise, so full navigation passes take minutes. The suite
  is deterministic because it waits for in-flight requests, but it is not a performance test (see the
  Phase 20 load evidence).
- **CI:** the GitHub workflow runs backend tests, Vitest and the production build; it does not run
  Playwright because it needs a seeded MySQL database and two running servers. Run `npm run test:e2e`
  locally or on staging.
- **Release approval:** engineering acceptance is complete; the master-spec exit also requires explicit
  business approval and the external provider/legal actions in `docs/PRODUCTION_CHECKLIST.md`.

## 7. Acceptance criteria mapping (master spec Phase 21)

| Criterion | Evidence |
| --- | --- |
| Full role-based scenarios | `auth-roles`, `navigation`, `tenant-operations` specs; screenshots 01–08. |
| Playwright suite on staging | Local staging-equivalent run, 20/20 (§2); hosted run via env overrides. |
| Validate API domains and OpenAPI contract | `api-contract.spec.ts` + `OpenApiContractTest` (273/273 operations). |
| Confirm navigation links and actions | 31 screens across three workspaces + sidebar/action flows. |
| Desktop/tablet/mobile layouts | `responsive.spec.ts` at 1280, 768×1024 and 390×844. |
| No fake data in production paths | Demo fixtures are environment-guarded; browser flows use real API data; mocks exist only in unit tests. |
| Audit, permissions, tenant isolation, financial controls | Backend suites (tenant isolation, RBAC, audit, finance invariants) plus browser role-denial checks. |
| Record final acceptance report | This document + `docs/evidence/phase-21/screens/`. |

Bottom line: all engineering acceptance checks pass end-to-end in a real browser. The only remaining
items are owner/external actions (hosted staging, provider credentials, legal sign-off, explicit
release approval).
