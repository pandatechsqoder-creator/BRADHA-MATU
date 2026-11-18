# Phase 0 — repository discovery and technical baseline

Status: **passed** (backfilled report, 2026-11-15)
Date: 2026-11-15 (describes work completed before Phase 2)

## Objective

Establish the technical baseline before feature work: confirm the stack and versions, local ports,
environment variables and startup commands, record architecture decisions, and create the backlog,
ERD, OpenAPI baseline and threat model.

## Baseline confirmed

| Item | Value |
| --- | --- |
| Backend | Laravel 13.17 on PHP 8.3+ (runtime PHP 8.4) |
| Auth | Laravel Sanctum bearer tokens |
| Database | MySQL 8 on port **3307** (dev database `brathamatustock`) |
| Frontend | React 19.2 + TypeScript 5.9 + Vite 7.1 |
| Routing/state | TanStack Router 1.132 + TanStack Query |
| Validation | Zod v4 on the frontend; Form Requests on the backend |
| Backend URL | `http://127.0.0.1:8000` |
| Frontend URL | `http://127.0.0.1:5173` |
| Tests | PHPUnit feature suite (`php artisan test`), Vitest (`npm test`) |

## Deliverables present in the repository

- `docs/adr/0001-technology-baseline.md` — architecture decision record for the stack, API envelope,
  tenancy model and public-ID strategy.
- `docs/BACKLOG.md` — phase backlog mapped to the master implementation document.
- `docs/ERD.md` — entity relationship baseline.
- `docs/api/openapi.yaml` — OpenAPI baseline (versioned, updated per phase).
- `docs/THREAT_MODEL.md` — threat model covering tenants, workers, finance and POS surfaces.
- `README.md` — ports, environment variables and run commands.
- `.env.example` files for backend and frontend.

## Exit verification

- `php artisan migrate` runs cleanly on MySQL 3307.
- Backend boots on `:8000`; `GET /api/v1/health` returns healthy.
- Frontend boots on `:5173` and renders the login shell.
- Backend and frontend test harnesses run.
- No pre-existing functionality was overwritten without review; the repository was built as the
  BRATHAMATU platform from this baseline.

## Result

Pass. The baseline is documented and still matches the running system.
