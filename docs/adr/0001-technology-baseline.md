# ADR 0001 — Technology Baseline and Local Environment

- Status: Accepted
- Date: 2026-11-11
- Phase: 0
- Authority: BRATHAMATU_STOCKTAKE_MASTER_IMPLEMENTATION.md (single source of truth)

## Context

Workspace `brathamatustock` was empty except the master specification. No existing
code to preserve. Phase 0 requires confirming exact versions, ports, environment
and startup commands before building.

## Confirmed environment (inspected 2026-11-11)

| Component | Version / location | Notes |
|---|---|---|
| PHP | 8.4.24 (ZTS, VS2022 x64) — `C:\php84\php.exe` | Extensions present: pdo_mysql, pdo_sqlite, mbstring, openssl, curl, fileinfo, zip, gd, bcmath. `intl` absent (not required by Laravel core). |
| Composer | 2.10.2 — `php C:\xampp\php\composer.phar` | Not on PATH; invoke via phar. |
| Node.js | v24.11.1 | npm 11.6.2 |
| MySQL | 8.0.46 (portable, noinstall) — `C:\tools\mysql8` | Downloaded from cdn.mysql.com. XAMPP MariaDB 10.4 exists but is NOT used (spec requires MySQL 8). |
| Redis | Server listening on 127.0.0.1:6379 (redis-cli 8.8.0) | Used for cache/queue/locks. |
| OS | Windows 10 (10.0.19045), Git Bash shell | |

## Decisions

1. **Monorepo layout**: `backend/` (Laravel), `frontend/` (TanStack React),
   `docs/` (ADRs, backlog, phase reports, evidence). MySQL binaries/data live
   outside the repo at `C:\tools\mysql8` (documented in README).
2. **Backend**: Laravel 13 (latest stable via Composer), PHP 8.4, MySQL 8.0.46,
   Redis for cache + queue. Queue driver `redis` locally; scheduler via
   `php artisan schedule:work` in dev.
3. **Frontend**: Vite + React 19 + TypeScript, TanStack Router, TanStack Query,
   Zod. Versions locked in `frontend/package.json` lockfile at setup.
4. **Auth model**: Laravel Sanctum **bearer tokens** (not cookie/SPA mode).
   Rationale: frontend and API run on different local origins; bearer tokens
   avoid cross-origin CSRF complexity. Tokens expire (configurable), are
   revocable, and logout deletes the token server-side. Frontend stores the
   token in `localStorage` for Phase 1; hardening (memory storage + refresh
   rotation) is scheduled for Phase 20 security review. MFA for privileged
   roles arrives in Phase 2.
5. **Local ports** (confirmed free at baseline):
   - MySQL: `127.0.0.1:3306`
   - Redis: `127.0.0.1:6379` (already running)
   - Laravel API: `127.0.0.1:8000` (`php artisan serve`)
   - Vite frontend: `127.0.0.1:5173` (`npm run dev`)
6. **API conventions**: base `/api/v1`, JSON envelope `{data, meta}` /
   `{error:{code,message,details,request_id}}`, UUID public IDs, UTC ISO-8601,
   request correlation ID middleware (`X-Request-Id`).
7. **Money/quantities**: `DECIMAL` columns, ISO currency codes (KES default),
   no floats. Centralised calculation services in later phases.
8. **Testing**: PHPUnit (Laravel default) for backend; Vitest + Testing Library
   for frontend; Playwright for browser E2E from Phase 2 onward. Phase 1 browser
   acceptance executed with the in-session browser tooling and recorded under
   `docs/evidence/`.
9. **Pending commercial decisions** (catalogue pricing formula, deposit %,
   POS relationship, recount cost matrix, schedule modes, accounting chart,
   payment credentials, hosting) remain **configurable policy records**, per
   spec §0.3 / §28.2. No hardcoded commercial constants.

## Consequences

- Local dev diverges from production hosting ( undecided ); app stays stateless.
- Portable MySQL runs as a console process, not a Windows service; README
  documents start/stop. A service install can be added later if desired.
- XAMPP Apache/PHP are not used by this project.