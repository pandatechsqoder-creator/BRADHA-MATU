# Phase 13 — Marketing automation report

Status: **engineering complete** (2026-11-17). The workflow engine, workflow builder at
`/platform/marketing/automation`, execution log, retry/backoff handling, consent-gated outbound steps
and the global kill switch are live. No outbound email, SMS or WhatsApp message is sent by this phase:
the only outbound step records an honest blocked result until an approved provider exists.

## Objective

Follow up on leads automatically without ever messaging someone who did not consent and without
pretending an unapproved provider is connected: lead events trigger workflows once, duplicate
submissions and retries are idempotent, every step is logged, failures back off, and a single kill
switch can stop all execution while still recording events for later review.

## Delivered

### Schema

`2026_11_17_140000_create_automation_tables.php` (guarded with `hasTable`) adds:

- `automation_workflows` — name, description, trigger (`lead.created`, `lead.assigned`,
  `lead.status_changed`), status (`draft`, `active`, `paused`), conditions JSON, creator/updater,
  activated/paused timestamps; indexed by status + trigger;
- `automation_steps` — ordered positions per workflow with a type and JSON config; unique
  (workflow, position);
- `automation_runs` — one row per workflow/event with a unique `event_key` (SHA-256 of workflow, trigger,
  lead and event signature), status (`queued`, `running`, `waiting`, `completed`, `blocked`, `failed`),
  current position, resume time, attempts, last error and context; indexed by status + resume time;
- `automation_run_steps` — a frozen copy of each step (type and config) for the run, with status,
  output JSON, attempts, last error, scheduled/executed times; unique (run, position). Copying the
  config means later workflow edits cannot rewrite history.

### Engine

`AutomationService`:

- `dispatch()` fans an event out to active workflows whose conditions match (lead type list, source,
  UTM campaign); a duplicate `event_key` is skipped, so duplicate form submissions and retried triggers
  never create a second run;
- `advance()` executes pending steps in order; `wait` steps set a `resume_at`, other future
  `scheduled_for` values (including retry backoff) park the run as `waiting`;
- failures retry up to 3 attempts with 1/5/15-minute backoff and finally mark the step and run `failed`;
- `notify_internal` sends in-app notifications via `NotificationService` to lead managers or the lead
  owner with `{contact_name}`, `{business_name}`, `{reference}`, `{type}` placeholders;
- `add_activity` writes a lead activity (event `automation`) and bumps `last_activity_at`;
- `notify_external` checks the latest consent-ledger row for that channel plus `consent_marketing`;
  without consent it records `CONSENT_MISSING`, with consent it records `PROVIDER_NOT_APPROVED` and a
  rendered preview — `sent: false` in both cases;
- the kill switch (`MarketingSetting` key `automation.enabled`) makes `dispatch()` create a run with
  status `blocked` and the "paused by the kill switch" reason, without executing steps; runs are not
  replayed when automation is resumed;
- every workflow mutation (create, update, activate, pause) and kill-switch change is audited.

`LeadService` dispatches `lead.created` after capture and conversion recording, `lead.status_changed`
with a `status:from>to` signature, and `lead.assigned` with the assignee signature. Dispatch failures
are reported, never allowed to break lead capture.

`php artisan marketing:automation:run` processes due wait/retry steps and is scheduled every minute
with `withoutOverlapping()` in `routes/console.php`. The `POST …/automation/run-due` endpoint exposes
the same pass for operators and tests.

### UI

`/platform/marketing/automation` (`automation.view`) is a workflow list with trigger, step summary
(rendered as an arrow chain), conditions, run counters and last run; the creation form builds a
workflow name, trigger, lead-type checkboxes, optional UTM filter, and one to ten ordered steps with
per-type fields; activate/pause buttons per row; a kill-switch banner and confirm-guarded toggle; a
provider notice that outbound sends stay blocked; and a per-workflow executions table showing lead
reference, run status, per-step status badges, the human-readable block/failure reason, timestamps and
the idempotency explanation. Editors and tenants get no nav item and are refused at the route guard.

### Permissions

`RbacSeeder` adds `automation.view` and `automation.manage`; both are excluded from tenant roles.

| Role | automation.view | automation.manage |
| --- | --- | --- |
| platform-owner, content-manager, marketing-manager | yes | yes |
| seo-manager, read-only-executive, lead-agent | yes | no |
| marketing-integration-admin | yes | yes |
| content-editor, content-publisher | no | no |
| tenant roles | excluded | excluded |

### Seed and OpenAPI

`DemoDataSeeder::seedAutomationDemo()` seeds an active **Quote follow-up** workflow (triggers on
`lead.created`, limits to quote/pos_demo leads, internal notification → 30-minute wait → activity →
email step) idempotently. `docs/api/openapi.yaml` documents the workflow CRUD, activate/pause,
executions, kill-switch and run-due paths; `OpenApiContractTest` covers them both ways.

## Verification

- `AutomationApiTest` — 11 tests, 119 assertions: catalogue and CRUD/activation (including
  `AUTOMATION_LOCKED` and `AUTOMATION_STEPS_REQUIRED`), validation details, public capture running the
  workflow and notifying a manager, condition matching, consent-missing and provider-not-approved
  blocking with previews, status-change/assignment triggers, wait resume via time travel, kill-switch
  blocking + audit + resume, idempotent dispatch, retry backoff to failure, and permission/tenant
  denials.
- `OpenApiContractTest`, `LeadCaptureTest`, `LeadPipelineApiTest` — 18 tests, 115 assertions.
- Full backend suite: **283 passed, 3023 assertions** (218.56s).
- `npx tsc --noEmit` clean; `npm run build` **394 modules**, 14.15s (existing chunk-size warning);
  Vitest **18 passed** (6 files).
- Phase 13 Playwright journey (`e2e/automation.spec.ts`): **5 passed** (2.1m; 25.1s, 27.4s, 16.3s,
  29.9s, 20.3s) against the seeded dev API and Vite server.

## Evidence

`docs/evidence/phase-13-automation/screens/`

| File | Shows |
| --- | --- |
| `01-automation-overview.png` | Automation workspace: kill-switch state, counters, provider notice, workflow list with the seeded Quote follow-up |
| `02-workflow-active.png` | Newly created E2E workflow saved as a draft and activated from the list |
| `03-executions-idempotent.png` | Browser-submitted and duplicated API-submitted leads: one run per lead, blocked email steps with the provider reason |
| `04-consent-blocked-step.png` | A lead without marketing consent: `notify_external` blocked with the consent reason; internal steps executed |
| `05-kill-switch-blocked-run.png` | Automation paused: the new event is recorded as a blocked run with all steps pending; earlier runs unchanged |
| `06-editor-permission-denied.png` | Editor has no Automation nav item and is refused at the route guard |
| `07-tenant-denied.png` | Tenant account blocked by the platform-administration gate |

The dev database also retains workflows created by repeated verification runs; the overview screenshot
shows them as-is. They are harmless demo rows that can be pruned with the rest of the demo data before
production seeding (Phase 15); nothing in production should carry E2E-named workflows.

## Open items

- Outbound email/SMS/WhatsApp sending remains blocked (`PROVIDER_NOT_APPROVED`) until the owner selects
  and approves a provider; consent-ledger checks and rendered previews are already in place.
- The scheduler requires a production cron entry (`schedule:run` every minute) in Phase 15; without it,
  waiting runs advance only through `POST …/automation/run-due` or the artisan command.
- Test-created workflows and runs stay in the dev database; prune with demo data before production.
- Reviews and audit-log workspace remain Phase 14 deliverables.

## Next

Phase 14 — security and operational hardening: permission-matrix review, privacy/retention workflows,
upload security, backup/recovery test, performance budgets, accessibility audit and monitoring.
