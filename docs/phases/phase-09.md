# Phase 9 — SOP and job operations

Status: **passed** (backend tests, frontend tests/build, browser acceptance)
Date: 2026-11-11

## Objective

Deliver versioned procedure templates, job-specific SOP snapshots, worker acknowledgement and
checklists, site attendance and live progress tracking. Exit criterion: **a worker cannot start
counting without the accepted assignment, SOP acknowledgement and site check-in.**

## Delivered — backend

- **Schema** (`2026_11_11_120000_create_operations_tables.php`):
  - `procedure_templates` and `procedure_template_versions` (immutable version snapshots with
    steps, checklists, acknowledgement flag, publish/supersede status).
  - `job_procedures`, `job_procedure_steps`, `job_procedure_acknowledgements`,
    `job_checklists` and `job_checklist_items` (job-level snapshot, acknowledgement identity/IP
    and per-assignment checklist responses).
  - `job_site_checkins`, `job_site_checkouts` and `job_attendance` (scheduled start, check-in/out,
    minutes on site).
  - `job_progress_events` (append-only stage/percent trail with actor type).
- **Procedure service** (`ProcedureService`):
  - Template creation, version publishing and supersession; publishing a version never rewrites
    jobs that already hold an assigned snapshot.
  - Applying a template copies the selected published version into a job snapshot with
    `JobProcedure.version` as the job revision, plus `template_version_id` for provenance.
  - Assigning a replacement SOP marks the previous active procedure `superseded` and rebuilds
    job checklists; locked/in-progress jobs reject changes.
- **Operations service** (`JobOperationsService`):
  - Readiness weights: accepted 10, SOP 15, required checklist 25, check-in 25, counting 25.
    Stages: `awaiting_acceptance|awaiting_sop|awaiting_acknowledgement|awaiting_checklist|awaiting_check_in|counting|ready`.
  - Check-in gate requires an accepted assignment, job in `scheduled|in_progress`, active SOP,
    required acknowledgement and all required pre-start checklist responses. Check-in transitions
    the job `scheduled → in_progress`, records attendance and a progress event.
  - `can_check_in` is computed server-side (prerequisites + field-work status + not already
    checked in); the UI consumes it rather than re-deriving the rule.
  - Check-out records attendance minutes and a progress event; the job remains in progress until
    counts are submitted in Phase 10.
  - Worker payloads include only the worker's own compensation, never tenant charge or margin;
    tenant payloads expose SOP, team attendance and progress but never compensation, quote or
    margin.
  - Operations dashboard aggregates active jobs, counting, awaiting check-in, unassigned,
    SOP pending and overdue, with stage, team, checklist progress, readiness, next action and last
    activity per job.
- **Authorization**:
  - Workforce routes use the `workforce` middleware; acknowledgement and checklist responses
    require `sop.acknowledge`; check-in/out are ownership-scoped.
  - Platform routes use `sop.manage` or the job monitor/assign/review permission pipe.
  - Tenant operations are nested through the tenant-scoped job route; a foreign job id returns
    404 (no existence leak). Tenant payloads never include compensation or margin.
  - New permissions: `sop.manage`, `sop.acknowledge`; monitor/assign/review roles receive the
    operations dashboard and job panel read access.
- **API surface** (`routes/api.php`):
  - `GET /tenants/{tenant}/stocktake-jobs/{job}/operations`
  - `GET /workforce/assignments/{assignment}/operations`,
    `POST /{assignment}/procedure/acknowledge`, `PUT /{assignment}/checklists/{item}`,
    `POST /{assignment}/check-in|check-out`
  - `GET|POST /platform/procedure-templates`, `GET|PATCH /{template}`,
    `POST /{template}/versions`
  - `GET /platform/operations`, `GET /platform/stocktake-jobs/{job}/operations`,
    `GET|POST /platform/stocktake-jobs/{job}/procedure`

## Delivered — frontend

- **Worker field operations** (`/workforce/assignments/{assignmentId}/operations`): start-of-count
  readiness gate with blockers, SOP steps and acknowledgement, per-item checklist responses
  (yes/no, text, select), site check-in (manual or browser GPS) and check-out, and the progress
  timeline. The layout exposes only **Your fee** — no tenant charge or margin.
- **Worker assignments**: accepted/scheduled cards link to the field operations workspace.
- **Platform SOP templates** (`/platform/sops`): template library with current-version steps and
  checklists, publish-version form prefilled from the current version, new-template form, and an
  explicit note that jobs keep their assigned snapshot.
- **Job operations panel** on the platform job page: readiness/team/SOP/checklist gate summary,
  blockers, assigned SOP with steps and checklist counts, template apply/replace, checklist
  progress, team readiness table, progress timeline and attendance.
- **Live operations dashboard** (`/platform/operations`): six counters, search filter and a job
  table with stage, team, checklist, progress, next action, overdue badge and 30-second refresh.
- **Tenant field progress card** on the tenant job page: status, readiness, SOP steps, team
  attendance and progress timeline, shown only for execution/review statuses and gated by tenant
  job permissions.
- **Navigation and guards**: platform nav exposes Field operations and SOP templates; the
  operations board uses `RequireAnyPermission` across monitor/assign/review/SOP permissions.

## Verification

- Backend: `php artisan test` — **62 passed (749 assertions)**.
  - `JobOperationsTest` (3 tests, 66 assertions): worker cannot check in without acknowledgement
    and required checklist; check-in is blocked until the required checklist is complete (and the
    job is scheduled); operations are tenant isolated and never expose compensation.
  - `ProcedureTest` covers template/version publishing, job snapshotting and replacement.
- Frontend: `npm test` — **18 passed** (6 files); `npx tsc -b` and `npm run build` pass (Vite
  chunk-size warning only).
  - `WorkerOperationsPage.test.tsx` drives the full gate: SOP acknowledgement → checklist items →
  enabled check-in → counting stage, asserting the API mutations.
- Browser acceptance (`http://localhost:5173`, in-app browser):
  1. `workforce@brathamatu.test` opened **SOP templates**, published **v2** of *Standard retail
     stock count SOP* (summary revised) and saw the library report **2 versions**.
  2. Opened demo job **STJ-20261111-GSILMC** (`awaiting_worker_acceptance`) and applied the SOP.
     The job panel showed the snapshot, 5 steps, pre-count checklist 4 required of 5, close-out
     3 of 4, readiness **10%**, blockers *Acknowledge the job SOP / Complete 4 required checklist
     items / Check in at the job site*.
  3. **Field operations** board showed 1 active job, 1 SOP pending, readiness 10%, next action
     *Acknowledge the job SOP*.
  4. `worker@brathamatu.test` opened **Open field operations →** from Assignments. Readiness 10%,
     check-in disabled, **Your fee Ksh 700.00**, no tenant charge or margin anywhere.
  5. Acknowledged the SOP → readiness **25%**, stage *Checklist pending*. Answered the four required
     pre-count items → readiness **50%**, stage *Awaiting site check-in*, check-in enabled. While
     the job was still `awaiting_worker_acceptance` (team 1/3), the button correctly stayed
     disabled with *Awaiting job schedule* and the API rejected a forged check-in.
  6. The demo team was completed through the assignment service (two recorded override offers,
     accepted by the senior and applicant accounts), moving the job to **Scheduled**. The worker
     then checked in: job **In progress**, readiness **100%**, activity *Checked in at Aisle 1 —
     Dry Goods* then *Count started after check-in*.
  7. Checked out with handover notes; attendance recorded checked-in/out timestamps and **1 minute
     on site**, with the checkout progress event.
  8. Platform **Field operations** then showed **1 counting**, team **3/3 accepted**,
     checklist **4/4 complete**, readiness **48%** (team average; the other two workers have not
     started their checklists) and last activity *Check out*.
  9. `owner@demosupermarket.test` opened the tenant job page: **In progress**, and the
     **Field progress** card showed readiness 48%, 3/3 accepted, 1 acknowledgement, the SOP steps,
     team attendance (one checked out, two not checked in) and the full progress timeline — with
     no compensation, quote internals or margin.
- Evidence: `docs/evidence/phase-09/01-platform-sop-templates.png`,
  `02-platform-job-sop-applied.png`, `03-platform-live-operations-board.png`,
  `04-worker-operations-gate-blocked.png`, `05-worker-checkin-enabled.png`,
  `06-worker-checked-in-counting.png`, `07-worker-site-attendance.png`,
  `08-platform-live-board-after-checkin.png`, `09-tenant-field-progress-card.png`.

## Notes / deferred

- Count capture, submission locks and revision history belong to Phase 10. An open check-in keeps
  the job in progress; check-out does not close the job.
- Attendance is site presence only; GPS is captured when the worker grants browser permission and
  is optional. No background location tracking is performed.
- SOP snapshots are immutable. Publishing a new template version requires explicitly replacing the
  job SOP and is rejected once counting has started.
- Demo accounts and credentials remain local/staging only and must never be enabled in production.
