# Phase 3 — Organisation structure and team management

Status: **passed** (backend tests, frontend tests, browser acceptance)
Date: 2026-11-11

## Objective

Let a tenant model its real organisation — branches, departments, warehouses, stock locations,
zones and bins — and manage who can see what through invitations, tenant roles and branch access
scopes. Every structure and membership rule is enforced by the Laravel API; the browser only
reflects what the backend allows.

## Delivered — backend

- **Organisation structure CRUD** (soft lifecycle: records are deactivated, never deleted):
  - `branches` — name, code (unique per tenant), address, city, contact, timezone, status.
  - `departments` — optional branch assignment.
  - `warehouses` — optional branch assignment, address.
  - `stock-locations` — branch/warehouse assignment, type (`zone|shelf|aisle|floor|bin`).
  - `stock-zones` and `stock-bins` — nested under location/zone with tenant ownership checks.
- **Branch access scopes** (`user_branch_scopes`): when a user has rows for a tenant, branch,
  department, warehouse and stock-location listings are filtered to those branches. No rows means
  unrestricted access. Scope data is per tenant; one user can be scoped differently in each business.
- **Invitations**:
  - `invitations` gains `branch_id`, `accepted_by` and a `(tenant_id, status)` index; the raw token
    is returned once and only its SHA-256 hash is stored.
  - Seven-day expiry; duplicate-pending and existing-member guards; role must be tenant-scoped;
    branch must belong to the same tenant.
  - Accept requires the signed-in email to match the invited address, then atomically creates the
    membership, tenant role pivot and branch scope, and marks the invitation accepted.
  - Preview exposes organisation, role, branch, status and expiry without leaking the token holder.
- **Member management**: list members with tenant roles and branch scopes plus `can_manage` meta;
  update roles/scopes; remove membership. Owners cannot remove their own Business Owner role, and
  the last active owner cannot be demoted. All changes audited (`member.roles_updated`,
  `member.scopes_updated`, `member.removed`).
- **Audit coverage**: `branch.*`, `department.*`, `warehouse.*`, `stock_location.*`,
  `stock_zone.created`, `stock_bin.created`, `invitation.created|accepted|revoked`,
  `member.*` — each with tenant, actor and request correlation.
- **Authorization**: every endpoint uses tenant-membership plus permission checks
  (`branch.view|create|update`, `tenant.users.manage`, `tenant.view`); cross-tenant IDs return 404
  or 422, never another tenant's data.
- **Demo data**: supermarket tenant now seeds departments, a main warehouse, aisle location, zone,
  bin, a branch-scoped branch manager and a pending manager invitation.

## Delivered — frontend

- **`/business/locations`** — full organisation manager:
  - Branch list with counts (departments, warehouses, locations), edit form, deactivate action.
  - Department, warehouse and stock-location creation with branch/warehouse selectors.
  - Stock-location row expands into zones and bins management; empty states explain the hierarchy.
  - Permission-aware affordances: branch managers can edit/deactivate but do not see create forms.
- **`/business/team`** — team and access management:
  - Member table shows tenant roles and branch access (or "All branches"), with a self marker.
  - "Manage access" editor for tenant roles and branch scopes, plus remove-member.
  - Invitation form (email, role, branch) returns a single-use accept link with copy action;
    pending invitations list with expiry and revoke. Read-only users see an explanation instead.
- **`/invitations/accept?token=…`** — invitation landing page:
  - Signed-out visitors are prompted to sign in (remembering the invitation URL) or register.
  - Signed-in visitors see organisation, role, branch and expiry, with an email-mismatch warning
    before the backend check; accept lands them in the new workspace.
- **Navigation**: "Branches & locations" added to the business sidebar.
- Login now honours a safe relative `?redirect=` target so invitation links survive sign-in.

## API surface added

See `docs/api/openapi.yaml` (v1.0.0-phase3):

- `PATCH|DELETE /tenants/{tenant}/members/{user}`, `GET /tenants/{tenant}/roles`
- `GET|POST /tenants/{tenant}/branches`, `PATCH|DELETE /tenants/{tenant}/branches/{branch}`
- `GET|POST /tenants/{tenant}/departments`, `PATCH|DELETE /tenants/{tenant}/departments/{department}`
- `GET|POST /tenants/{tenant}/warehouses`, `PATCH|DELETE /tenants/{tenant}/warehouses/{warehouse}`
- `GET|POST /tenants/{tenant}/stock-locations`, `PATCH|DELETE /tenants/{tenant}/stock-locations/{stockLocation}`
- `GET|POST /tenants/{tenant}/stock-locations/{stockLocation}/zones`
- `GET|POST /tenants/{tenant}/stock-zones/{stockZone}/bins`
- `GET|POST /tenants/{tenant}/invitations`, `POST /tenants/{tenant}/invitations/{invitation}/revoke`
- `GET /invitations/{token}`, `POST /invitations/{token}/accept`

## Verification

- Backend: `php artisan test` — **30 passed** (143 assertions). Phase 3 coverage
  (`OrganisationTest`, 8 tests / 52 assertions):
  - owner builds branch → department → warehouse → location → zone → bin hierarchy
  - branch scopes restrict branch/department/warehouse/location listings
  - cross-tenant branch returns 404 and cross-tenant department assignment returns 422
  - invitation accept creates membership, role and branch scope
  - wrong-email, expired and revoked invitations rejected
  - member roles and scopes update; owner self-demotion blocked; last owner protected
  - users without `tenant.users.manage` cannot invite or update members
- Frontend: `npm run build` passes (TypeScript + Vite); `npm test -- --run` — **13 passed**.
- Browser acceptance (Chrome, `http://127.0.0.1:5173`):
  1. As `owner@brightmart.test`: created branch "Downtown Store" (`BRT-DTN`, Nairobi) and
     "Westlands Branch" (`BRT-WST`); branch counts update live.
  2. Created department "Fresh Produce" (Downtown), warehouse "Back Store" (Downtown), stock
     location "Aisle 1" (aisle, Back Store), zone "Dry Goods" and bin "Bin 01" — all counts
     propagate (branch 1/1/1, warehouse locations 1, location zones 1, zone bins 1).
  3. Invited `downtown.manager@brightmart.test` as Branch Manager scoped to Downtown Store; the
     pending invitation appeared with expiry and a copyable single-use link.
  4. Signed out, registered the invitee, verified the test-mode code, opened the invitation link,
     saw organisation/role/branch, and accepted — landing in Bright Mart as Branch Manager.
  5. As the manager, `/business/locations` showed only Downtown Store (Westlands hidden) and no
     create forms; `/business/team` was read-only with the invitation section restricted.
  6. Back as owner, "Manage access" reflected the correct role/scope checkboxes; adding Westlands
     updated the member row to "Downtown Store, Westlands Branch"; removing it restored the
     single-branch scope.
- Evidence screenshots: `docs/evidence/phase-03/`
  - `locations-hierarchy.png`
  - `manager-branch-scoped.png`
  - `manager-readonly-team.png`
  - `team-member-access.png`

## Notes / deferred

- Email delivery is not configured in this environment (test-mode only). Invitations therefore
  expose a copyable single-use link; recipient email delivery is completed with the notification
  adapters in Phase 19. Tokens are stored hashed and expire after seven days.
- Invitation preview/accept require an authenticated session by design; the signed-out landing
  page preserves the invitation URL through sign-in.
- Structure endpoints deactivate rather than delete so later phases can keep historical job,
  count and movement references intact.
- Local test credential reset: the Bright Mart owner password was reset via `artisan tinker` for
  acceptance only (`owner@brightmart.test` / `Owner1234`). Demo and browser-test accounts must
  never be enabled in production.
