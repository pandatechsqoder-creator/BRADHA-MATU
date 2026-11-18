# Phase 1 — project foundation and local browser launch

Status: **passed** (backfilled report, 2026-11-15)
Date: 2026-11-15 (describes work completed before Phase 2)

## Objective

Deliver a working foundation: Laravel API skeleton with a standard response/error format, logging,
request IDs and health endpoint, plus a TanStack React shell with router, query client, API client,
design system and a real login flow against the API.

## Delivered — backend

- Laravel 13 application under `backend/`, serving `/api/v1`.
- Health endpoint `GET /api/v1/health` (see `backend/routes/api.php`).
- Standard error envelope `{error:{code,message,details,request_id}}` via exception rendering.
- Request-ID middleware and structured logging.
- Sanctum installation and personal access token tables.
- Queue/cache/session tables from the default migrations; scheduler foundation registered.
- Cross-origin and rate-limiting configuration for the Vite dev origin.

## Delivered — frontend

- Vite + React 19 + TypeScript application under `frontend/`.
- TanStack Router with auth guards (`requireAuth`, `redirectIfAuthed`) and TanStack Query.
- Typed API client (`src/lib/api/client.ts`) that injects the bearer token and `X-Tenant-Id`,
  unwraps the `data` envelope and surfaces `ApiError`.
- Design system (`src/components/ui.tsx`): buttons, fields, cards, tables, badges, alerts, loaders.
- App shell with role-aware navigation and sign-out.
- Login and registration pages backed by the real API; registration continues into onboarding.

## Verification

- Backend boots on `:8000`; `GET /api/v1/health` returns 200 with status payload.
- Frontend boots on `:5173`; the login page renders and signs in demo accounts through the API.
- `php artisan test` green (suite has grown to 121 tests as later phases landed).
- `npx tsc -b` and `npm run build` clean.
- Browser login verified for owner, supervisor, finance and worker demo accounts (2026-11-15).

## Result

Pass. Both applications run locally, the browser can log in to the test environment, and health/API
smoke checks succeed.
