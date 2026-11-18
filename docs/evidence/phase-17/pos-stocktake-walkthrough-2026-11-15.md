# Phase 17 — POS stock-taking integration, end-to-end browser walkthrough (re-verification)

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `owner@demosupermarket.test` (tenant owner)

## Steps and captured state

1. Opened `/pos/stocktake` (sidebar: BRATHAMATU POS → Stock-taking).
2. Readiness panel: **Eligible to commission** — 8 catalogue products (minimum 5, threshold met),
   catalogue ready `Yes`, open commissions `0`, prepaid agreement `Active`.
3. Entered title `Browser acceptance count`; **Commission stock-take** became enabled.
4. Commissioned the job. Result:
   - New commission `POS-NWRNYPGJ` — `proposed`, `on demand`, `commissioned pending authorisation`,
     with **Authorise** and **Decline** actions.
   - Readiness flipped to **Not eligible**, open commissions `1`, blocking condition
     `existing_commissioned_jobs` — one open commission must be resolved first.
5. Clicked **Authorise**. Result: commission now `commissioned authorised`, authorised by
   `Demo Supermarket Owner`; readiness returned to **Eligible to commission**, open commissions `0`,
   and the action changes to cancel-with-reason.
6. Previous demo commissions remain listed: `POS-A1W0IUNU` (cancelled, decline reason recorded) and
   `POS-ZTCLJYA2` (`POS-VERIFY-001`, authorised then cancelled with reason).

## Result

Pass. Tenant-scoped POS stock-taking readiness, commissioning, blocking rules, authorisation, decline/
cancel and the commission history all work through the browser. Tenant access returns 2xx while the
route remains outside the platform-only group.
