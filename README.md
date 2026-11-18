# BRATHAMATU Stocktake Platform

Multi-tenant stock-taking SaaS and managed marketplace, with integrated BRATHAMATU POS.
**Single source of truth:** `BRATHAMATU_STOCKTAKE_MASTER_IMPLEMENTATION.md` — read it before changing anything.

## Repository layout

| Path | Purpose |
|---|---|
| `backend/` | Laravel 13 API (`/api/v1`), PHP 8.4, MySQL 8, Redis |
| `frontend/` | TanStack React + TypeScript (Vite, Router, Query, Zod) |
| `docs/adr/` | Architecture decision records |
| `docs/BACKLOG.md` | Phase backlog mapped to the master spec |
| `docs/phases/` | Phase reports |
| `docs/evidence/` | Browser test evidence (screenshots, logs) |
| `docs/marketing/` | Public website, SEO and marketing platform Phase 0 pack |

## Local environment (Windows)

Confirmed baseline — see `docs/adr/0001-technology-baseline.md`:

- PHP 8.4 (`C:\php84\php.exe`), Composer via `php C:\xampp\php\composer.phar`
- Node 24 / npm 11
- MySQL 8.0.46 portable at `C:\tools\mysql8` → **port 3307**
  (XAMPP MariaDB on 3306 is left untouched for other projects)
- Redis on `127.0.0.1:6379`

### 1. Start MySQL 8

```bash
# first time only: initialize (already done during provisioning)
# C:/tools/mysql8/bin/mysqld.exe --initialize-insecure --datadir=C:/tools/mysql8/data

C:/tools/mysql8/bin/mysqld.exe --defaults-file=C:/tools/mysql8/my.ini --console
```

Database: `panda_stocktake` · user: `panda` / `panda_local_dev` (local only).

### 2. Backend

```bash
cd backend
php C:/xampp/php/composer.phar install
cp .env.example .env   # then set APP_KEY via key:generate and DB credentials
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work     # separate terminal
php artisan schedule:work  # separate terminal (when schedules land)
```

### 3. Frontend

```bash
cd frontend
npm install
npm run dev   # http://127.0.0.1:5173 (proxies /api to :8000)
```

### 4. Tests

```bash
cd backend  && php artisan test
cd frontend && npm test && npm run build
```

## Demo accounts (local/staging ONLY — never production)

Password for all: `Password1!`

| Email | Intended role (roles enforced from Phase 2) |
|---|---|
| `superadmin@brathamatu.test` | Platform Super Admin |
| `ops@brathamatu.test` | Platform Operations Manager |
| `finance@brathamatu.test` | Platform Finance Manager |
| `owner@demosupermarket.test` | Tenant Owner |
| `inventory@demosupermarket.test` | Inventory Manager |
| `workforce@brathamatu.test` | Platform Workforce Manager |
| `marketing@brathamatu.test` | Marketing Manager (campaigns, social, CMS) |
| `worker@brathamatu.test` | Verified Stock Taker |
| `senior.worker@brathamatu.test` | Senior Stock Taker |
| `applicant@brathamatu.test` | Applicant Stock Taker |
| `cashier@demosupermarket.test` | POS Cashier |

## Status

Phases 0–21 are implemented and browser-verified against the master specification:

- Phase 0–1 — baseline, foundation and local browser launch
- Phase 2 — identity, roles and tenant isolation
- Phase 3 — organisation, branches and locations
- Phase 4 — catalogue onboarding and pricing
- Phase 5 — inventory balances, movements, cut-offs and snapshots
- Phase 6 — stock-taking request, review and quotation
- Phase 7 — sandbox payments, signed callbacks, double-entry ledger, reconciliation and refunds
- Phase 8 — workforce onboarding, verification, training, assignment offers and earnings visibility
- Phase 9 — SOP templates, check-in/out, attendance and live operations
- Phase 10 — count engine (blind/guided sessions, revisions, exceptions, submission locks)
- Phase 11 — offline package, queued sync, conflict handling and device audit
- Phase 12 — QA review, findings, verification sampling, recount and accuracy
- Phase 13 — tenant review, reconciliation reports and disputes
- Phase 14 — completion, maker-checker payouts and finance operations
- Phase 15 — recurring and on-demand schedules with occurrence tracking
- Phase 16 — BRATHAMATU POS core (terminal, barcode, sales, receipts, shifts, reconciliation,
  back office purchases/suppliers/customers/returns/transfers)
- Phase 17 — POS stock-taking commissioning, authorisation and cancellation
- Phase 18 — external POS/ERP integrations (connections, mappings, sync runs, webhooks, conflicts)
- Phase 19 — tenant/platform reporting and analytics, support tickets with SLAs, notification centre
- Phase 20 — security hardening, rate limits, health diagnostics, retention, backups, CI and runbooks
- Phase 21 — Playwright end-to-end acceptance suite and OpenAPI contract validation

Each phase report is in `docs/phases/` (latest: `docs/phases/phase-17.md`); browser evidence is in
`docs/evidence/` (Phases 18–21 under `phase-18/` … `phase-21/`).
See `docs/BACKLOG.md` and `docs/VERIFICATION_2026-11-15.md` for the remaining honest scope
(Phase 15 deferred notifications/forecast tests and external provider cut-over owner actions).

Verification at this commit: `php artisan test` 182 passed (2121 assertions), `npx tsc --noEmit` clean,
`npm run build` clean, `npm test` 18 passed, Playwright E2E 20 passed. Run the browser suite with
`cd frontend && npm run test:e2e` (requires the API and Vite dev server; see `docs/phases/phase-21.md`).

## Public website and marketing platform (new plan)

A separate master plan covers the public website, CMS, lead CRM, SEO, structured data, Google/Meta
integrations, campaigns, analytics and marketing automation:
`docs/BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md`.

Phases 0–8 are complete at the engineering level. The public site is server-rendered Blade at `/`
(Laravel backend), while TanStack React remains the app and marketing-admin frontend. Branding is
BRATHAMATU throughout the stock-taking platform, POS and public site. Phase 2 added
the core marketing pages and published `/privacy`, `/terms` and `/cookies` as noindex notices pending
legal sign-off. Phase 3 added the ten stock-taking and ten BRATHAMATU POS landing pages, with hub links,
unique metadata and quote/demo CTA journeys. Phase 4 added the CMS admin at `/platform/marketing`:
section editor with signed previews, draft → review → approved → scheduled → published workflow with
version history and restore, separation of duties, an audit trail, navigation and content libraries,
and a media library with folders, alt text, usage tracking and in-place replacement. Phase 5 added
public quote, POS-demo, contact, callback and partnership forms with spam controls, consent ledger,
UTM attribution and no-JS fallbacks, plus the lead CRM at `/platform/marketing/leads` (filters,
assignment, activities, consent withdrawal, PII masking and the audited, rate-limited CSV export).
Phase 6 added technical SEO at `/platform/marketing/seo`: automatic 301s on live slug changes,
redirect chains with hit counts and loop protection, manual redirect CRUD with audit events, a
diagnostics report (missing/duplicate metadata, broken links, canonical conflicts, redirect chains,
orphans) and Core Web Vitals beacon ingestion with p75 reporting. Phase 7 added JSON-LD structured
data built from the same page data as the renderer (Organization, WebSite, WebPage, BreadcrumbList,
FAQPage, Service, SoftwareApplication), per-page markup settings with audit events, validation-run
history, a structured-data card in the page editor, and a publish gate that returns 422 when requested
markup cannot be emitted; Article waits for Phase 8 posts and LocalBusiness for verified locations.
Phase 8 added the blog and public resource hub: an article workflow (draft → review → approved →
scheduled → published → archived) with separation of duties, categories, tags, related articles and
an editorial calendar under `blog.manage`; `/resources`, article detail, category archives (indexable),
tag archives (noindex), consent-gated downloads, `/rss.xml` and sitemap entries; published articles
emit validated Article JSON-LD, and the admin workspace lives at `/platform/marketing/blog`.
Phase 9 added Google integrations at `/platform/marketing/integrations/google`: OAuth 2.0 with PKCE
and single-use user-bound state, encrypted tokens, queued Search Console, GA4, Business Profile and
Tag Manager syncs, integration logs and run history under `integrations.google.manage` /
`integrations.meta.manage`. Business Profile reviews are reported as unsupported rather than invented,
and while owner Google credentials are absent the workspace shows an explicit setup-required state.
Phase 10 added Meta integrations at `/platform/marketing/integrations/meta` (Facebook Login, Pages,
Instagram business accounts, read-only ad reporting, encrypted Page tokens, queued assets/insights/ads
syncs) and approval-gated social publishing at `/platform/marketing/social` under `social.publish` /
`social.approve`: draft → review → approved → scheduled → published, author self-approval blocked,
JPEG-required Instagram publishing, honest provider failures, and read-only ad spend. Both workspaces
show setup-required states and never invent provider metrics.
Phase 11 added campaign management at `/platform/marketing/campaigns` under `campaigns.view` /
`campaigns.manage` / `campaigns.approve` / `campaigns.spend`: objectives, channels, audiences, budgets,
landing-page links, UTM tracking, creative, draft → review → approved → active (pause/resume/complete/
cancel), separation of duties (the author cannot approve), activation gated on approval plus spend,
numbered revision history, recorded results and audit. Provider ad budgets stay in the providers.
Phase 12 added consent-aware analytics: a public consent banner (Accept analytics / Necessary only /
Cookie preferences) whose unconsented sessions are refused at the API with `CONSENT_REQUIRED`, event
ingestion limited to a fixed event list with PII keys stripped and IPs stored only as keyed hashes,
server-side conversion recording from form submissions, and reports at
`/platform/marketing/analytics` under `analytics.view`: consent overview, lead funnel, campaign
performance with cost per lead, landing-page attribution, recent events and an honest
`NO_SEARCH_DATA` state for search performance until Google Search Console is connected.
Phase 13 added marketing automation at `/platform/marketing/automation` under `automation.view` /
`automation.manage`: workflows triggered by lead created/assigned/status changes with lead-type and UTM
conditions, ordered steps (in-app notification, lead message, activity, timed wait), idempotent runs
keyed per event, retries with 1/5/15-minute backoff, an executions log with per-step statuses, and a
global kill switch that records blocked runs without executing or replaying steps. Outbound email, SMS
and WhatsApp steps never send: without channel consent they record `CONSENT_MISSING`, and with consent
they record `PROVIDER_NOT_APPROVED` plus the rendered preview because no provider has been approved.
Phase 14 hardened the site for launch: security headers (`nosniff`, frame denial, referrer policy,
permissions policy, API CSP, HSTS on HTTPS) and `X-Robots-Tag` noindex on every private, preview and
404/410 response; MIME-checked image uploads (no SVG, disguised files rejected); retention windows for
analytics, conversion, tracking-preference and automation-run data; `db:backup` plus a non-destructive
`db:restore --table-prefix --verify` drill (205 tables / 4,774 rows verified); a production
configuration probe in `health:check`; a production env template; zero WCAG A/AA violations on the
home, quote form, staff login and automation workspace; and public-page performance budgets. The SPA
shell is noindex as defense in depth. Reports and evidence: `docs/marketing/phase-01-report.md` …
`docs/marketing/phase-14-report.md` and
`docs/evidence/phase-1-public-website/`, `docs/evidence/phase-2-core-pages/`,
`docs/evidence/phase-3-landing-pages/`, `docs/evidence/phase-4-cms-media/`,
`docs/evidence/phase-5-leads-crm/`, `docs/evidence/phase-6-technical-seo/`,
`docs/evidence/phase-7-structured-data/`, `docs/evidence/phase-8-blog-resources/`,
`docs/evidence/phase-9-google-integrations/`, `docs/evidence/phase-10-meta-integrations/`,
`docs/evidence/phase-11-campaigns/`, `docs/evidence/phase-12-analytics/`,
`docs/evidence/phase-13-automation/` and `docs/evidence/phase-14-hardening/`. Run the
public browser
suite with `cd frontend && npm run test:e2e:public` (backend only; seed `MarketingContentSeeder` first) —
32 passed, 1 skipped across 390/768/1440 px. Run the marketing admin journey with
`cd frontend && npx playwright test e2e/marketing-admin.spec.ts` (API and Vite dev server; seed
`DemoDataSeeder` first) — 3 passed. Run the lead journey with
`cd frontend && npx playwright test e2e/leads.spec.ts` — 2 passed. Run the technical SEO journey with
`cd frontend && npx playwright test e2e/seo.spec.ts` — 2 passed. Run the structured-data journey with
`cd frontend && npx playwright test e2e/structured-data.spec.ts` — 2 passed. Run the blog and resources
journey with `cd frontend && npx playwright test e2e/blog.spec.ts` — 3 passed. Run the Google
integrations journey with `cd frontend && npx playwright test e2e/google-integrations.spec.ts` — 3
passed. Run the Meta and social publishing journey with
`cd frontend && npx playwright test e2e/meta-integrations.spec.ts` — 4 passed. Run the campaign
journey with `cd frontend && npx playwright test e2e/campaigns.spec.ts` — 4 passed. Run the analytics
journey with `cd frontend && npx playwright test e2e/analytics.spec.ts` — 5 passed. Run the automation
journey with `cd frontend && npx playwright test e2e/automation.spec.ts` — 5 passed. Run the hardening
journey with `cd frontend && npx playwright test e2e/hardening.spec.ts` — 4 passed (axe A/AA on public
home, quote form, staff login and automation workspace; performance budget; noindex enforcement). In
development,
`http://127.0.0.1:5173/` serves the public website at the root and the React staff app under `/login`,
`/app` and `/platform`; the staff sign-in page links to the public site.

Phase 15 readiness is complete: `php artisan marketing:launch-check` gates the cut-over on production
configuration, indexing, a published home, contact details, integrations, backups and provider
approval (currently blocked only on owner-confirmed contact details, with expected integration
warnings); the deployment runbook and staff guide live in `docs/marketing/phase-15-runbook.md` and
`docs/marketing/staff-guide.md`, with the readiness report and evidence in
`docs/marketing/phase-15-report.md` and `docs/evidence/phase-15-launch/`. The live cut-over has not been
performed: it needs owner-supplied hosting, domain/DNS/TLS, contact details and content/legal sign-off,
plus offsite backup storage. Live Google and Meta connections require
owner credentials, Meta app review and confirmed assets; campaign approver and spend holders require
owner confirmation; outbound email/SMS/WhatsApp remains blocked until an approved provider is
selected; the operational platform above is unchanged.