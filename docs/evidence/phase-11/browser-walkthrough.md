# Phase 11 — offline capability, browser walkthrough

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `worker@brathamatu.test`

## Steps

1. Opened the count workspace for assignment `01M7FK84BXQ7CCBR9W49WTB3HE` (job `STJ-20261115-DLQBDO`).
2. Observed the offline readiness banner and the blind-count contract before recording entries.
3. Fetched the offline job package for the assignment and sent device heartbeats (worker token,
   `GET /api/v1/workforce/assignments/{assignment}/offline-package` and
   `POST /api/v1/workforce/assignments/{assignment}/heartbeat`); both responded 200 during the walkthrough.
4. Confirmed queued entries from the offline payload replay idempotently — the same idempotency keys
   produce `synced` results without duplicate rows, and a conflicting submitted value is recorded as a
   `conflict` rather than overwriting the server value.

## Captured state

- Workspace banner: `✓ Offline package cached — entries will queue locally if you lose connection.`
- Session status **Active**, with 7 entries, 1 exception and 1 unknown product visible after replay.
- Package contains job metadata, assignment, frozen scope, approved units and count mode only —
  no expected quantities, cost, margin or compensation.

## Automated coverage

`backend/tests/Feature/OfflineSyncTest.php` covers package download, batch sync, idempotent replay,
conflict recording and heartbeat. Full backend suite: `121 passed (1472 assertions)` on 2026-11-15.

## Result

Pass. The field workflow survives offline interruption in the tested scenarios and synchronises without
duplicate or lost entries; conflicts are surfaced for resolution.
