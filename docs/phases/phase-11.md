# Phase 11 — Offline capability and field reliability

Status: **passed** (backend tests, frontend build)
Date: 2026-11-12

## Objective

Deliver offline-first counting: download the job package for local caching, queue count operations while offline, sync safely and idempotently when the connection returns, detect conflicts, and provide device/session audit. Exit criterion: **browser field workflow survives offline interruption and synchronises without duplicate/lost entries under tested scenarios.**

## Delivered — backend

- **Schema** (`2026_11_11_140000_create_offline_sync_tables.php`):
  - `count_device_sessions` — per-device audit trail: fingerprint, platform, app version, offline capability, first/last seen, package download time.
  - `count_sync_batches` — one row per batch sync call: total/synced/conflict/rejected counts + full per-item results JSON.
  - `count_sync_conflicts` — when an offline entry cannot be auto-merged: client value, server value, resolution status.

- **OfflineSyncService** (`app/Services/Count/OfflineSyncService.php`):
  - `jobPackage()` — downloads the full offline package (job metadata, assignment, scope, approved units, mode) for local caching. Excludes all financial data (no expected quantity, cost, compensation, margin). Records the device session and package download time.
  - `syncBatch()` — accepts an array of offline-queued items (entries, exceptions, unknown products) each carrying an idempotency key. Returns per-item status: `synced` (created or idempotent replay), `conflict` (submitted entry with different value — server wins, conflict recorded), or `rejected` (validation failure). Creates a `CountSyncBatch` audit row.
  - `heartbeat()` — records device-seen events without a package download.
  - Conflict detection: if a submitted entry already exists with the same idempotency key but a different value, the server preserves its value and records a `CountSyncConflict`; the client receives `synced` (idempotent) status so it does not retry.
  - Submitted sessions: only accept idempotent re-syncs (same key → `synced`, new key → `rejected`); never overwrite submitted entries.
  - Active sessions: draft entries with the same key are updated (last-write-wins for draft state); new entries are created.

- **Controller** (`WorkforceOfflineSyncController`):
  - `GET  /workforce/assignments/{assignment}/count/package` — download job package (permission: `stocktake.count.view`)
  - `POST /workforce/assignments/{assignment}/count/sessions/{session}/sync` — batch sync (permission: `stocktake.count.view`)
  - `POST /workforce/assignments/{assignment}/count/heartbeat` — device heartbeat (permission: `stocktake.count.view`)

- **CountService public helpers**: `scopeForPackage()` and `modeForJob()` exposed for the sync service.

## Delivered — frontend

- **IndexedDB queue** (`lib/offline/db.ts`):
  - `enqueue()` — adds an item to the local queue, idempotent by key (updates existing queued/failed items, skips already-synced).
  - `getQueueForSession()`, `getPendingItems()`, `markSynced()`, `markFailed()`, `clearSyncedForSession()`.
  - IndexedDB store with indexes by session+assignment, idempotency key (unique), and status.

- **Sync engine** (`lib/offline/syncEngine.ts`):
  - `syncSession()` — drains the pending queue in batches of 50, sends to the server, processes per-item outcomes.
  - Online/offline detection via `navigator.onLine`.
  - Event emitter (`addSyncListener`) for UI updates: started, item_synced, item_failed, item_conflict, completed, error.
  - Max 3 retry attempts per item; failed items remain in the queue for manual retry.
  - `sendHeartbeat()` and `downloadJobPackage()`.

- **React hooks** (`lib/offline/hooks.ts`):
  - `useOnlineStatus()` — live online/offline with browser event listeners.
  - `useOfflineSync(assignmentId, sessionId)` — manages the sync lifecycle: auto-sync when online, periodic sync every 15s, heartbeat every 60s, `addToQueue()` for offline entry capture, `triggerSync()` for manual retry.
  - `useJobPackageDownload(assignmentId)` — downloads and caches the job package in sessionStorage.

- **UI components**:
  - `OfflineBanner` — shows "You are offline" (amber) with pending count, or sync progress (blue) with "Sync now" button, or conflict/failed indicators.
  - `SyncStatusBadge` — per-item status pill: Queued (gray), Syncing (blue pulse), Synced (green, hidden by default), Failed (red), Conflict (amber).

- **WorkerCountPage integration**:
  - Offline banner at the top of the page.
  - "Download for offline" button — caches the job package before going offline.
  - When offline, the entry form queues the item to IndexedDB instead of posting to the API; when online, it posts directly as before.
  - Automatic sync when the connection returns; periodic sync while online.

## Verification

- Backend: `php artisan test` — **77/77 passed (953 assertions)**, zero regressions.
  - `OfflineSyncTest` (6 tests, 62 assertions):
    - Job package download: ownership-scoped; returns scope with approved units; excludes all financial data.
    - Batch sync: creates entries, exceptions, unknown products idempotently; per-item status reported; re-sync is idempotent (no duplicates).
    - Conflict: submitted entry preserves server value when client sends a different value; `synced` status returned (no silent overwrite).
    - Rejection: out-of-scope variant and unapproved unit are rejected.
    - Heartbeat: device session recorded.
    - Isolation: another worker's assignment → 404.
- Frontend: `npx tsc -b` clean; `npm run build` — built in 7.45s.
- Live: `brathamatu-stocktake-api` health → `db=ok, cache=ok`; Phase 11 migration applied.

## Notes / deferred

- Evidence file upload (photos, barcode scans) delivery through short-lived signed URLs is deferred to Phase 19/20.
- Local data encryption (beyond HTTPS in transit) is deferred to Phase 20 security hardening.
- Service worker for true offline page load (without any network) is deferred — the current implementation uses IndexedDB + sessionStorage caching, which survives navigation/reload but not a cold start with no network.
- GPS geofencing for check-in validation remains optional (Phase 9 schema supports it).
