# Phase 2 — Identity, roles, tenancy and role dashboards

Status: **passed** (backend tests, frontend tests, browser acceptance)
Date: 2026-11-11

## Objective

Deliver authentication hardening, tenant registration, role/permission enforcement, tenant
isolation and role-aware dashboards/navigation, all proven in a real browser.

## Delivered — backend

- Email verification with hashed 6-digit codes (`user_verifications`), test-mode code returned
  only in local/testing environments.
- Auth flows audited: `auth.registered`, `auth.login`, `auth.login_failed`, `auth.logout`,
  `auth.email_verified`; actor attribution fixed for pre-session events.
- Tenant registration transaction: tenant + owner membership + `business-owner` role scoped to
  the tenant + `tenant.created` audit event.
- Tenant profile update with `tenant.update` authorization and before/after audit snapshot.
- Tenant member listing with per-tenant roles.
- Platform operations:
  - `EnsurePlatformRole` middleware (platform role with null tenant pivot), distinct from
    tenant-resolved permissions.
  - Overview counters, tenant directory (search/status/pagination), audit trail with filters.
- Organisation structure tables/models staged for Phase 3 (branches, departments, warehouses,
  stock locations, zones, bins).
- Seeded demo dataset: 7 tenants across the three operating modes, 14 accounts, tenant settings
  for deposit and catalogue pricing policies.

## Delivered — frontend

- Tenant-aware API client (`X-Tenant-Id` only from stored membership; no client-side trust).
- Login, register, verify, forgot-password placeholder, profile, notifications, support.
- Onboarding: create organisation and land on a readiness dashboard.
- Role-aware app shell:
  - Platform: Operations overview, Tenants, Audit trail.
  - Business: Dashboard, Team members, Settings, POS workspace (only when `brathamatu_pos`).
  - Workforce/onboarding: Profile and Support until Phase 3/8 modules land.
- Platform pages: overview KPIs, tenant directory with filters, tenant detail with members,
  audit trail with actor/tenant/subject/request correlation.
- Tenant pages: dashboard readiness checklist, team members, editable organisation settings.
- Route guards for platform access with a clear denial screen.

## API surface added

See `docs/api/openapi.yaml` (v1.0.0-phase2):

- `GET /dashboard`
- `POST /auth/email/verification-notification`, `POST /auth/verify-email`
- `GET|POST /tenants`, `GET|PATCH /tenants/{tenant}`, `GET /tenants/{tenant}/members`
- `GET /platform/overview`, `GET /platform/tenants`, `GET /platform/audit-logs`

## Verification

- Backend: `php artisan test` — **22 passed** (91 assertions), including:
  - login payload returns roles, permissions and tenants
  - onboarding creates membership + owner role
  - cross-tenant view blocked (403)
  - spoofed `X-Tenant-Id` rejected (request stays scoped to the real tenant)
  - platform admin directory/overview allowed; tenant user blocked from platform endpoints
  - `tenant.update` permission enforced
  - dashboard workspace resolution per role
  - failed login audited
- Frontend: `npm run build` passes; `npm test -- --run` — **13 passed**.
- Browser acceptance (Chrome, `http://127.0.0.1:5173`):
  1. Login as `owner@demosupermarket.test` → tenant dashboard (4 members, 1 business,
     BRATHAMATU POS, readiness checklist); console clean.
  2. Team members lists all four seeded members with tenant-scoped roles.
  3. Settings update persists (`+254 700 123 456`) and is recorded as `tenant.updated`
     in the audit trail.
  4. Sign out → login as `superadmin@brathamatu.test` → platform KPIs, tenant directory,
     search (`Pharmacy`), tenant detail, audit trail.
  5. Tenant owner manually navigates to `/platform/audit` → denial screen, no data leak.
  6. Register `owner@brightmart.test` → test-mode code shown → verify → onboarding →
     create “Bright Mart” → dashboard as Business Owner; tenant appears in the platform
     directory with 1 member and 0 branches.
- Evidence screenshots: `docs/evidence/phase-02/`.

## Notes / deferred

- Phone verification is deferred to the workforce onboarding phase (Phase 8), where phone
  numbers become operationally required.
- Invitations, role changes and branch scoping are Phase 3 scope; the Team page states this.
- Demo accounts are local/staging only and must never be enabled in production.
