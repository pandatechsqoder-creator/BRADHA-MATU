# Phase 21 acceptance evidence

- Report: `docs/phases/phase-21.md`
- Command: `cd frontend && npm run test:e2e` (`playwright test`, one Chromium worker)
- Result: **20 passed / 20** in 25.2 minutes (2026-11-15), 0 retries
- HTML report: `frontend/playwright-report/`
- Backend suite: 147 passed / 1714 assertions
- Vitest: 6 files / 18 tests passed
- Build: `npx tsc -b` clean, `npm run build` clean
- OpenAPI: 219 paths / 273 operations, enforced by `backend/tests/Feature/OpenApiContractTest.php`

Screenshots (headless Chromium, full page):

| File | View |
| --- | --- |
| `screens/01-tenant-dashboard.png` | Tenant owner dashboard |
| `screens/02-tenant-reports.png` | Tenant reports & analytics |
| `screens/03-pos-terminal.png` | POS terminal, shift and catalogue |
| `screens/04-tenant-support.png` | Support centre |
| `screens/05-platform-overview.png` | Platform operations overview |
| `screens/06-platform-reports.png` | Platform reports & analytics |
| `screens/07-workforce-assignments.png` | Workforce assignments |
| `screens/08-pos-sale-receipt.png` | Completed POS cash sale with receipt |

Defects fixed during acceptance (details in the phase report): non-JSON 401 on API requests without an
`Accept` header, missing request id on unmatched API routes, OpenAPI drift, production demo seeding,
missing accessible form names, and Vitest collecting Playwright specs.
