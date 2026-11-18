# Phase 0 — Repository audit and architecture baseline

Status: **complete — awaiting architecture approval** (2026-11-16)
Scope: the public website, SEO and marketing platform described in
[BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md](../BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md).

No Phase 1 code has been written. This phase only inspected the existing repositories and produced
the route map, data model, API contract, rendering decision, integration prerequisites, backlog and
risk register.

## 1. What already exists

The repository is the completed BRATHAMATU stock-taking platform (operational Phases 0–21). It is a
mature, authenticated multi-tenant API plus a React operations SPA. It contains **no public website,
no CMS, no marketing or SEO functionality**.

| Area | Current state |
| --- | --- |
| Backend | Laravel 13.17 on PHP 8.3+ (runtime 8.4), Sanctum 4 bearer tokens, PHPUnit 12 |
| Database | MySQL 8 (local port 3307), Redis cache/queue, 25 migrations, ULID public IDs, bigint PKs |
| API | REST `/api/v1`, 219 paths / 273 operations enforced by an OpenAPI contract test |
| Frontend | React 19.2, TypeScript 5.9, Vite 7.1, TanStack Router 1.132, TanStack Query 5, Zod 4, Tailwind 4 |
| Rendering | Client-only SPA (`frontend/dist` static build); Laravel serves a default `welcome` view at `/` |
| Auth | Bearer token in `localStorage`, 1,440-minute expiry, logout revokes server-side |
| Roles | 35 seeded roles across platform, tenant and workforce scopes; per-user permission overrides |
| Public surface | Health, register/login, payment callbacks and integration webhooks only |
| Media | `local`, `public` and `s3` disks configured; no media table, upload endpoint or library |
| SEO | No sitemap, no per-page metadata, no redirects, no JSON-LD; `robots.txt` is `Disallow:` (allow all) |
| Lead capture | None (tenant support tickets exist, but they are authenticated and unrelated) |
| Integrations | Tenant POS/ERP connections with encrypted credentials, HMAC webhooks, sync runs and conflicts |
| Jobs | Redis queue, one queued job (`ProcessCatalogueImport`), scheduler for integration sync and retention |
| Deployment | Nginx/TLS → PHP-FPM 8.4 → Laravel; static SPA on Nginx/CDN; **no Node runtime in production** |
| CI | Backend tests + `composer audit`; frontend Vitest, build and `npm audit`; Playwright is local-only |

Verification baseline at audit time: backend 147 tests / 1,714 assertions, Vitest 18 tests, Playwright
20 browser tests, clean production build.

## 2. Authentication, roles and tenancy

- Sanctum personal access tokens; every non-public endpoint sits inside `auth:sanctum`.
- Role scopes: `platform` (global), `tenant` (bound to a tenant through `user_roles.tenant_id`) and
  `workforce`. Platform middleware (`platform`) and workforce middleware (`workforce`) gate whole
  route groups; `permission:<slug>` gates individual actions.
- Tenant requests also carry `X-Tenant-Id`; tenant isolation is enforced in queries and policies.
- A `content.manage` permission and a `content-manager` (“Content / Marketing Manager”) platform role
  already exist in `RbacSeeder` but **no route or controller consumes them**. They are a clean hook for
  the marketing permission model.
- Audit logging, request correlation IDs and a uniform error envelope already exist and should be
  reused for every marketing mutation.

> Marketing/CMS data is platform-owned, not tenant-owned. It must sit behind platform roles and must
> never expose tenant, lead or worker records to public endpoints.

## 3. Route and layout map

### API

- Base prefix `/api/v1`; groups: auth, account, dashboard, tenants, workforce, platform, integrations,
  payments and health.
- Public unauthenticated endpoints: `GET /health`, `POST /auth/register`, `POST /auth/login`,
  `POST /payments/mpesa/callback`, `POST /payments/card/callback`,
  `POST /integrations/webhooks/{connection}`.
- All remaining 260+ operations require a bearer token; platform marketing endpoints would extend the
  `platform` group.

### Frontend

- 52 TanStack Router routes, all authenticated or auth-related:
  `/login`, `/register`, `/forgot-password`, `/verify`, `/onboarding`, `/app`, `/profile`,
  `/settings`, `/notifications`, `/support`, `/invitations/accept`;
  tenant routes under `/business/*` and `/pos/*`; platform routes under `/platform/*`;
  workforce routes under `/workforce/*`.
- `/` immediately redirects to `/app` when a token exists, otherwise `/login`. There is no public
  layout, header/footer, marketing section component or public navigation.
- `AppShell` is the only shell and assumes an authenticated user; it cannot host public pages.
- `index.html` has a single static description and title; no per-page metadata mechanism.

### Backend web

- `routes/web.php` serves the default Laravel welcome page at `/`. This is the only web-rendered page.
- `public/robots.txt` allows all crawling; there is no `sitemap.xml`.

## 4. Database and API conventions to follow

- Public identifier: ULID `uuid` on externally referenced records; internal bigint PKs stay private.
- Tenant-owned tables lead composite indexes with `tenant_id`; marketing tables are platform-scoped.
- Money uses `DECIMAL`; timestamps UTC; immutable history for status changes, financial records and
  audit. Soft deletes only for reversible hiding (published pages, media), never for audit.
- Response envelope: `{data, meta:{request_id,timestamp,…}}`; errors:
  `{error:{code,message,details,request_id}}`.
- Pagination meta: `current_page`, `per_page`, `total`, `last_page`.
- Validation via Form Requests; authorization via permission middleware/policies.
- OpenAPI is versioned in `docs/api/openapi.yaml` and enforced by `OpenApiContractTest`; every new
  endpoint must be added there in the same change.
- Existing encrypted integration pattern: `credentials` cast to `encrypted:array`, `webhook_secret`
  cast to `encrypted`, HMAC verification and idempotent webhook event rows. OAuth tokens for
  Google/Meta should follow the same pattern.

## 5. Deployment and rendering constraints

- Production topology has **no Node.js SSR runtime**: Nginx/TLS → PHP-FPM → Laravel API, plus a static
  SPA build on Nginx/CDN. Adding SSR means adding a new runtime, process manager and failure mode.
- The SEO requirement is explicit: essential content must be present in the delivered HTML, not only
  after JavaScript execution.
- The whole public site will be indexed; private app routes must remain excluded from crawling.
- CORS currently allows only `FRONTEND_URL`; public pages served by Laravel do not need CORS, while the
  app SPA continues to call the API as today.

See [ADR 0002](../adr/0002-public-website-rendering.md) for the rendering recommendation.

## 6. Gaps this plan must fill

1. Public site shell, components, routes and accessible responsive design.
2. CMS pages, versions, sections, navigation, banners, CTAs, FAQs and testimonials.
3. Media library with folders, alt text, captions, usage tracking and replacement.
4. Blog/resources, categories, tags, authors, editorial calendar and downloads.
5. Lead capture forms, CRM pipeline, assignment, activities, consent and UTM attribution.
6. Technical SEO: metadata, canonicals, sitemap, robots, redirects, Open Graph, breadcrumbs, noindex.
7. Dynamic JSON-LD templates, preview and validation.
8. Google OAuth/integrations: Business Profile, Search Console, GA4, GTM.
9. Meta OAuth/integrations: Pages, Instagram, ad accounts, publishing and spend approval.
10. Campaigns, creative, UTM builder, budgets, approvals and activation controls.
11. Consent-aware analytics, conversion events and attribution.
12. Marketing automation with consent checks, execution logs and a kill switch.
13. Granular marketing permissions, audit trail and separate review/publish/spend rights.

Existing tenant POS/ERP integrations are **not** the right base for Google/Meta marketing OAuth, but
their encrypted-credential, webhook and sync-run patterns should be reused.

## 7. Naming and scope conflicts to resolve before Phase 1

| Item | Current repository | New plan |
| --- | --- | --- |
| Company/product brand | BRATHAMATU Stocktake, BRATHAMATU POS | BRATHAMATU Stocktake, BRATHAMATU POS (aligned 2026-11-16) |
| Domain topology | One SPA origin + API origin (`FRONTEND_URL`) | Public website plus app (domains undecided) |
| API namespace | `/api/v1/platform/*` | Proposal uses `/api/v1/admin/*` |
| Permission hook | `content.manage` umbrella | Granular CMS/SEO/leads/campaign permissions |
| Rendering | Client-only SPA | Server-rendered or reliably prerendered |

These were decisions, not defects. Brand naming was resolved on 2026-11-16 (BRATHAMATU across the
stock-taking platform, POS and public website); domain topology, API namespace and rendering approach
were settled before Phase 1.

## 8. Recommended boundaries

- **Do not modify** tenant stock-taking, POS, finance, workforce or existing integration behaviour.
- Public traffic must not share rate limits, queues or cache keys with the operational API.
- Marketing records are platform-owned; no `tenant_id` on CMS/SEO/blog tables.
- Leads are independent records; they must never automatically create a paid stock-taking job.
- Public endpoints are read-only or form submissions; everything else requires platform permissions.
- Add marketing endpoints to OpenAPI and contract tests in the same phase they are built.

## 9. Exit gate

Phase 0 passes when the architecture is approved: rendering approach, brand/domain, API namespace,
permission model and the Phase 1 scope. Until then, no public-site code is written.
