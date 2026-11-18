# Phase 15 — Recurring and on-demand schedules

Status: **passed** (backend tests, frontend tests/build)
Date: 2026-11-14

## Objective

Deliver recurring and on-demand stock-take schedules: create schedules with flexible frequencies (weekly, fortnightly, monthly, quarterly, half-yearly, annual, custom, on-demand), generate occurrences without duplicates, trigger on-demand schedules, pause/resume schedules, and skip occurrences with reasons. Exit criterion: **a tenant can create, manage and trigger recurring stock-take schedules with occurrence tracking.**

## Delivered — backend

Phase 15 backend was already implemented in prior work:

- **Schema** (`2026_11_11_180000_create_schedule_tables.php`): `stocktake_schedules` and `schedule_occurrences` tables with tenant isolation, unique (schedule_id, scheduled_at) constraint, and status tracking.
- **ScheduleService**: schedule CRUD, occurrence generation with duplicate prevention, on-demand trigger, skip with reason, pause/resume.
- **ScheduleController**: 7 endpoints under `/tenants/{tenant}/schedules/*` and schedule-occurrence skip.
- **Routes**: registered under Phase 15 comment block in `routes/api.php`.
- **Tests**: `ScheduleTest.php` — create schedule, no-duplicate generation, on-demand trigger, skip occurrence, pause/resume, on-demand cannot auto-generate, tenant isolation.

## Delivered — frontend

- **API client** (`lib/api/schedule.ts`): typed wrappers for all 7 schedule endpoints with Zod validation.
- **Schemas** (`lib/api/schemas.ts`): `Schedule`, `ScheduleOccurrence`, `ScheduleWithOccurrences`, `CreateScheduleInput`, `UpdateScheduleInput`, `GenerateOccurrencesInput`, `SkipOccurrenceInput` types.
- **Hooks** (`lib/schedule/hooks.ts`): TanStack Query hooks — `useTenantSchedules`, `useTenantSchedule`, `useCreateTenantSchedule`, `useUpdateTenantSchedule`, `useGenerateScheduleOccurrences`, `useTriggerOnDemandSchedule`, `useSkipScheduleOccurrence`.
- **SchedulePage** (`features/schedule/SchedulePage.tsx`): schedule list with status badges, frequency/mode display, next occurrence, "New schedule" create form, manage links.
- **ScheduleForm** (`features/schedule/ScheduleForm.tsx`): create schedule form with title, description, mode (reminder/proposed), frequency, day of month, starts/ends at.
- **ScheduleDetailPage** (`features/schedule/ScheduleDetailPage.tsx`): schedule detail with pause/resume, occurrence generation (1-12 count), on-demand trigger, occurrence table with skip action, skip confirmation dialog.
- **Router**: added `/business/schedules` and `/business/schedules/$scheduleId` routes with `RequireTenant` guard.
- **Nav**: replaced "Schedules Soon" placeholder with active `/business/schedules` link in tenant sidebar.

## Verification

- Backend: `php artisan test` — **108/108 passed (1322 assertions)**, zero regressions.
- Frontend: `npm run build` — built successfully, TypeScript clean.
- Frontend: `npm test` — **18/18 passed**.
- Migration applied to live database.

## Notes / deferred

- Review window reminders/notifications are Phase 19.
- Demand forecast is part of Phase 19 reporting.
- Month-end/leap-year edge case tests deferred to Phase 20 security hardening.
- Commercial modes: `reminder` and `proposed` implemented; `prepaid` references a prepaid agreement (Phase 20+).
- No paid job or charge is ever created automatically from a schedule (key invariant preserved).
