# BRATHAMATU public website and marketing platform — Phase 0 pack

Status: **Phases 1–14 engineering complete; Phase 15 readiness complete** (2026-11-17). Owner
Google/Meta credentials, Meta app review, campaign approver confirmation, outbound message provider
approval, offsite backup storage, hosting/domain/TLS, contact details and content/legal sign-off remain
pending before the live cut-over
Source plan: [BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md](../BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md)

This folder contains the Phase 0 deliverables plus the Phase 1–14 public website, CMS, lead CRM,
technical SEO, structured-data, blog, Google integration, Meta/social publishing, campaign management,
consent-aware analytics, marketing automation and operational hardening work, plus the Phase 15 launch
readiness pack. All fourteen implementation phases are implemented, tested and browser-verified — see
[phase-01-report.md](phase-01-report.md),
[phase-02-report.md](phase-02-report.md),
[phase-03-report.md](phase-03-report.md), [phase-04-report.md](phase-04-report.md),
[phase-05-report.md](phase-05-report.md), [phase-06-report.md](phase-06-report.md),
[phase-07-report.md](phase-07-report.md), [phase-08-report.md](phase-08-report.md),
[phase-09-report.md](phase-09-report.md), [phase-10-report.md](phase-10-report.md),
[phase-11-report.md](phase-11-report.md), [phase-12-report.md](phase-12-report.md),
[phase-13-report.md](phase-13-report.md) and [phase-14-report.md](phase-14-report.md). The operational
stock-taking platform (Phases 0–21) is unchanged.

## Deliverables

| Document | Contents |
| --- | --- |
| [phase-00-audit.md](phase-00-audit.md) | Repository audit: stack, auth/roles, routes/layouts, existing CMS/marketing, DB/API conventions, deployment and rendering constraints |
| [route-map.md](route-map.md) | Proposed public route map, app/admin routes, navigation model and indexing rules |
| [cms-data-model.md](cms-data-model.md) | Proposed schema for CMS, media, blog, SEO, structured data, leads, campaigns, Google/Meta, analytics, consent and automation |
| [api-contract.md](api-contract.md) | Public and platform-marketing endpoints, controls, permissions and OpenAPI workflow |
| [integration-prerequisites.md](integration-prerequisites.md) | Google and Meta account, API, scope, review and environment prerequisites |
| [backlog.md](backlog.md) | Phase 1–15 backlog with deliverables, dependencies and exit gates |
| [risk-register.md](risk-register.md) | Risk register with owners and mitigations |
| [phase-01-report.md](phase-01-report.md) | Phase 1 delivery report, browser evidence and Lighthouse baseline |
| [phase-02-report.md](phase-02-report.md) | Phase 2 core pages, legal notices, CTA verification and browser evidence |
| [phase-03-report.md](phase-03-report.md) | Phase 3 service and POS landing pages, metadata audit, CTA journeys and browser evidence |
| [phase-04-report.md](phase-04-report.md) | Phase 4 CMS workflow, versioning, admin screens, media library and role-denial evidence |
| [phase-05-report.md](phase-05-report.md) | Phase 5 public forms, consent ledger, attribution, lead pipeline, CSV export and browser evidence |
| [phase-06-report.md](phase-06-report.md) | Phase 6 redirects, SEO diagnostics, Core Web Vitals ingestion and browser evidence |
| [phase-07-report.md](phase-07-report.md) | Phase 7 JSON-LD graph, validation gate, admin panel, publishing block and browser evidence |
| [phase-08-report.md](phase-08-report.md) | Phase 8 article workflow, public resource hub, downloads consent, RSS/sitemap and browser evidence |
| [phase-09-report.md](phase-09-report.md) | Phase 9 Google OAuth/PKCE connection, queued Sync Console/GA4/Business/GTM imports, encryption, honest unsupported states and browser evidence |
| [phase-10-report.md](phase-10-report.md) | Phase 10 Meta OAuth, Pages/Instagram/ad assets, approval-gated social publishing, read-only ad reporting, encryption and browser evidence |
| [phase-11-report.md](phase-11-report.md) | Phase 11 campaign objectives, budgets, audiences, creative, UTM tracking, separation-of-duties approval, activation, revisions, results and browser evidence |
| [phase-12-report.md](phase-12-report.md) | Phase 12 consent banner and preference storage, `CONSENT_REQUIRED` ingestion rules, PII filtering and IP hashing, server-side conversions, funnel/campaign/landing-page/search reports, analytics workspace and browser evidence |
| [phase-13-report.md](phase-13-report.md) | Phase 13 workflow builder, triggers/conditions, ordered and delayed steps, idempotent runs, retries with backoff, consent-gated outbound blocks, execution logs, kill switch and browser evidence |
| [phase-14-report.md](phase-14-report.md) | Phase 14 security headers and noindex enforcement, upload MIME hardening, retention windows, backup/restore drill, production config probe, WCAG A/AA audit and performance budgets |
| [phase-15-report.md](phase-15-report.md) | Phase 15 launch readiness: launch-check gate results, owner inputs, blocked items and exit-gate status |
| [phase-15-runbook.md](phase-15-runbook.md) | Deployment, DNS/TLS, Search Console, GA4/consent, cut-over smoke test, rollback and monitoring steps |
| [staff-guide.md](staff-guide.md) | Staff guide to pages, media, SEO, blog, leads, campaigns, analytics and automation |
| [ADR 0002](../adr/0002-public-website-rendering.md) | Accepted rendering decision: server-rendered Blade public site, TanStack React for app/admin |

## Key findings

1. There is **no existing public website, CMS, media library, lead CRM, SEO system or marketing
   integration** in the repository. This surface is greenfield.
2. The operational platform is mature and should be reused, not rebuilt: Sanctum auth, 35 seeded roles,
   the permission middleware, audit logging, Redis queues, encrypted integration credentials, the API
   envelope, OpenAPI contract tests and the React SPA shell.
3. `content.manage` and the `content-manager` role already exist but are unused — a clean starting point
   for the granular marketing permission model.
4. The frontend is client-only and production has **no Node runtime**. True server rendering requires a
   decision, not just a framework choice.
5. The public brand is BRATHAMATU (BRATHAMATU Stocktake, BRATHAMATU POS), aligned with the repository
   and applied across the public site, app surfaces, routes and content.

## Decisions required before Phase 1

| # | Decision | Recommendation | Blocks |
| --- | --- | --- | --- |
| D1 | Rendering approach | Accept ADR 0002: Blade public site, React admin/app | Phase 1 |
| D2 | Brand and product naming | Confirmed: BRATHAMATU / BRATHAMATU Stocktake / BRATHAMATU POS | Content, logo, legal pages |
| D3 | Domain topology | Public site on the primary domain; app on `app.` or `/app`; API on `api.` or `/api` | Routing, OAuth redirects, cookies |
| D4 | API namespace | Keep `/api/v1/platform/marketing/*` instead of adding `/admin/*` | API contract, permissions |
| D5 | Permission model | Replace unused `content.manage` with granular marketing permissions and roles — **applied in Phase 4** | Phase 11 |
| D6 | Contact details, service areas, prices, testimonials, providers | Confirm real values; all remain settings, never invented | Phases 2, 3, 5, 13 |
| D7 | Google/Meta accounts and review | Start Google Cloud/Business Profile allowlist and Meta app review now | Phases 9–11 |
| D8 | Staging and CI | Provide hosted staging and run Playwright in CI before integrations | Phases 9–15 |

## Phase 1 — delivered (2026-11-16)

- Blade public layout, header/footer, responsive navigation, design tokens, 404/410 pages.
- CMS read model and seeded content so Phase 2/3 pages do not need a rewrite.
- Public route registration, canonical host handling, robots/sitemap, `noindex` for app/auth routes.
- Browser tests at 390, 768 and 1440 px; keyboard and accessibility-tree smoke checks; Lighthouse
  baseline recorded (100/100/100 for accessibility, SEO and best practices on mobile and desktop).

D1 (Blade public site with TanStack app/admin) and D2 (BRATHAMATU public brand) are applied in code and
confirmed by the owner. D5 (permission model) is applied in Phase 4: granular `cms.*` and
`media.manage` permissions replace the unused `content.manage` on the content roles. D3 (domain
topology) and D6 (contact details, service areas, prices, providers) remain open; the implementation
reads the relevant values from settings so they can be confirmed without a rewrite. D4, D7 and D8 apply
to later phases.

## Phase 2 — delivered (2026-11-16)

- All core pages enriched: homepage differentiators and resource teaser, POS onboarding, how-it-works
  POS fit and pre-count FAQ, industry and contact FAQs, expanded global FAQs.
- `/privacy`, `/terms` and `/cookies` published with a legal section type, a footer legal menu and
  `noindex` until legal details are confirmed. They are excluded from the sitemap.
- Quote and demo contact cards now route to `/contact`; primary CTAs were exercised in the browser.
- Seeded content revision 4 is idempotent; a second seeder run creates no duplicates.
- Branding is BRATHAMATU / BRATHAMATU Stocktake / BRATHAMATU POS across public content, settings,
  navigation and the `/brathamatu-pos` slug; legacy `panda-pos` rows migrate on seed.
- Verification: 167 backend tests, 32 public browser tests (1 skipped), no-JS checks, internal link
  crawl, no horizontal overflow and 100 accessibility/best-practices/SEO Lighthouse scores.
- Owner sign-off is still required for copy, legal entity/contact details, governing law, retention
  periods and the cookie/consent policy.

## Phase 3 — delivered (2026-11-16)

- Twenty landing pages published: ten stock-taking services and ten BRATHAMATU POS products, each with
  a hero, scope, linked service grid, process, FAQs and a closing CTA.
- Unique SEO titles and descriptions across all 20 pages; 474–541 words of content per page; no thin
  keyword permutations.
- Stock-taking and POS hubs, plus the homepage service grid, now link to their landing pages.
- Every stock-taking page routes primary CTAs to `/request-quote`; every POS page routes to
  `/book-demo`. Both journeys were exercised in the browser at all three widths.
- Dark-section link contrast and the brand link’s accessible name were corrected after making hub
  cards linkable.
- Seeded content revision 5; 35 published pages, zero drafts, and the seeder remains idempotent.
- Verification: 168 backend tests, 21 public feature tests, 32 public browser tests (1 skipped),
  no-JS checks, internal link crawl, no horizontal overflow and 100 accessibility on both Lighthouse
  profiles (mobile 100/100/100/100, desktop 89/100/100/100).
- Owner sign-off is still required for landing-page copy, legal details and the contact/price inputs.

## Phase 4 — delivered (2026-11-16)

- Versioned publishing workflow: draft → review → approved → scheduled → published, with unpublish and
  restore. Editing a published page returns it to draft while the live snapshot stays online.
- Separation of duties blocks self-approval when `marketing.separation_of_duties` is on; every
  transition is audited.
- `marketing:publish-scheduled` runs every minute; preview uses a 30-minute signed, noindex URL.
- Admin screens for overview, page editor with section reordering, media library (folders, alt text,
  captions, usage, replacement), navigation and the banner/CTA/FAQ/testimonial library.
- Granular `cms.view/edit/review/publish` and `media.manage` permissions applied to the content roles
  (`content-editor`, `content-publisher`, `content-manager`, `marketing-manager`); super admins receive
  every permission, so they manage the site from the Website section and the platform-overview
  "Public website" card.
- Media replacement keeps the delivery URL, increments the version and preserves usage references.
- Staff sign-in links to the public website. In development the Vite origin
  (`http://127.0.0.1:5173`) serves the Blade public site at `/` and the React staff app under
  `/login`, `/app`, `/platform`, `/business`, `/pos` and `/workforce`.
- Verification: 182 backend tests, OpenAPI contract pass, TypeScript and build clean, 18 Vitest
  tests, and a three-part Playwright journey covering publish, schedule, unpublish, restore, media
  replacement, role denial (editor, support agent, tenant owner) and super-admin website access.
- Owner sign-off is still required for Phase 2–3 copy, legal details and the contact/price inputs.

## Phase 5 — delivered (2026-11-16)

- Quote, POS-demo, contact, callback and partnership forms render server-side on `/request-quote`,
  `/book-demo` and `/contact`, with CSRF, encrypted human-timing tokens, a honeypot, UTM/referrer
  capture and transactional/marketing consent checkboxes.
- One capture pipeline for the JSON API (`/api/v1/public/forms/{form}`) and the no-JS web POST
  (`/forms/{form}`); validation is server-side and idempotent submissions return the original
  reference.
- Consent is stored in an append-only `marketing_consents` ledger; leads store status, attribution,
  activities, assignment history and conversion timestamps. A lead never creates a stocktake job.
- Platform CRM at `/platform/marketing/leads`: stats, filters, PII masking without `leads.export`,
  lead detail with status/assignment/activity/consent controls, and a rate-limited audited CSV export
  that honours the visible filters.
- New `leads.view/manage/export` permissions and a `lead-agent` role, applied to the content roles;
  tenant roles are excluded.
- Verification: 200 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a two-part
  Playwright journey covering public capture, pipeline work, consent withdrawal, filtered export,
  masked PII and tenant denial.
- Owner sign-off is still required for form copy, consent wording, the privacy notice and contact
  details; outbound email/SMS delivery waits for the Phase 9/10 provider credentials.

## Phase 6 — delivered (2026-11-16)

- Publishing a slug change on a live page creates a 301 automatically; the public fallback follows
  active redirects (including chains, with loop protection and hit counts) before returning 404.
- Manual redirect CRUD at `/platform/marketing/seo` for 301/302/307/308 with audit events, and an
  admin diagnostics report covering missing/duplicate metadata, broken internal links, canonical
  conflicts, broken canonicals, redirect chains and orphan pages.
- Core Web Vitals beacon (`POST /api/v1/public/web-vitals`, 60/min per IP) stores LCP/CLS/INP/TTFB
  samples; the admin panel aggregates p75 values, good-LCP share, device split and per-path rows for
  7/28/90-day windows.
- Verification: 205 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a
  two-part Playwright journey covering redirect create/disable/delete, chain diagnostics, vitals
  aggregation, an automatic slug-change 301 and role boundaries.
- A hosted crawl and Search Console verification remain for Phases 9/15.

## Phase 7 — delivered (2026-11-17)

- `StructuredDataService` emits one JSON-LD graph (Organization, WebSite, WebPage, BreadcrumbList,
  FAQPage, Service, SoftwareApplication) from the same resolved page data as the renderer, so markup
  matches visible content and never invents ratings, reviews, prices or offers.
- Per-page settings at `/platform/marketing/pages/{page}/structured-data` (requested types, service
  type/application category overrides), signed-preview rendering, and validation-run history.
- Publishing validates the working snapshot and returns `422 VALIDATION_FAILED` with the blocking
  reason when requested markup cannot be emitted; the editor surfaces that reason.
- The page editor gains a structured-data card with status, emitted types, issues, JSON preview,
  overrides, `Validate now`, `Save markup settings` and recent runs.
- Verification: 211 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a
  two-part Playwright journey covering valid/invalid markup, the publish block, a successful publish
  and live JSON-LD matching the visible page.

## Phase 8 — delivered (2026-11-17)

- New blog schema (categories, tags, posts, related links, resource downloads, editorial items) with
  a draft → review → approved → scheduled → published → archived workflow, separation of duties and
  audit events under the new `blog.manage` permission.
- Public `/resources` hub: article index with search/category/tag filters, article detail with
  Markdown rendering and related articles, indexable category archives, noindex tag archives,
  gated downloads with a consent confirmation, RSS 2.0 at `/rss.xml` and sitemap entries.
- Published articles emit Article JSON-LD generated from the visible title, excerpt, author and
  category; publishing is blocked with a 422 when validation fails.
- Admin UI at `/platform/marketing/blog`: article editor with workflow actions, categories and tags,
  resource downloads and the editorial calendar, reached from the Website navigation.
- Verification: 219 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a
  three-part Playwright journey covering the editor→publisher workflow, live article/related
  content, index/tag/download pages, feeds, sitemap, navigation and role boundaries.

## Phase 9 — delivered (2026-11-17)

- Google OAuth 2.0 authorization-code flow with PKCE (S256), a single-use user-bound state and
  encrypted credentials; the callback routes the app back with connected/error flags.
- Marketing-specific integration schema: connections, Business Profile accounts/locations, Search
  Console properties and daily metrics, GA4 properties and metrics, GTM containers, sync runs and
  integration logs, under `integrations.google.manage` / `integrations.meta.manage`.
- Queued syncs for Search Console (28-day daily performance), GA4 (28-day sessions/users/conversions),
  Business Profile (accounts and locations) and GTM (containers), with refresh-on-expiry, 401 → expired,
  permission-denied and unreachable handling; Business reviews are reported as unsupported instead of
  being invented.
- Admin workspace at `/platform/marketing/integrations/google`: connection status, capabilities,
  synced counts, sync buttons, run history, logs, connect/reconnect/disconnect and an explicit owner
  setup-required state while credentials are missing.
- Verification: 231 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a
  three-part Playwright journey covering the unconfigured workspace, API 409/422 guards and role denial.

## Phase 10 — delivered (2026-11-17)

- Facebook Login OAuth with a single-use user-bound state, short→long-lived token exchange, encrypted
  credentials and best-effort revocation; the callback routes the app back with connected/error flags.
- Meta schema for Businesses, Pages (encrypted Page tokens), Instagram accounts, ad accounts, campaign
  records, social posts and approval decisions.
- Queued syncs for Pages/assets, insights and read-only ad reporting; 401 → expired connection,
  permission-denied handling, per-post insight warnings, budget cents converted to decimal spend.
- Approval-gated social publishing at `/platform/marketing/social` (`social.publish`): draft → review →
  approved → scheduled → published, with author self-approval blocked, rejection reasons,
  JPEG-required Instagram container publishing, Facebook feed publishing and honest failure states;
  the scheduler publishes due posts.
- `/platform/marketing/integrations/meta` (`integrations.meta.manage`): setup-required state,
  connection status, capabilities with read-only ad spend, synced counts, connected Pages, syncs, runs,
  logs and disconnect.
- Verification: 250 backend tests, OpenAPI contract pass, TypeScript and Vitest clean, and a four-part
  Playwright journey covering the honest unconfigured workspace, draft workflow, editor restriction
  and tenant denial.

## Phases 11–14 — delivered (2026-11-17)

- Phase 11 adds campaign management at `/platform/marketing/campaigns`: objectives, channels,
  audiences, creative, landing pages, UTM builder, budgets, separation-of-duties approval before
  activation, revisions and results.
- Phase 12 adds consent-aware analytics: the public consent banner and preference storage,
  `CONSENT_REQUIRED` ingestion rules, PII filtering and IP hashing, server-side conversion recording,
  and funnel/campaign/landing-page/search reports feeding the analytics workspace.
- Phase 13 adds event-driven automation at `/platform/marketing/automation`: trigger/condition
  matching, ordered and delayed steps, idempotent event keys, retries with backoff, consent-gated
  outbound steps that stay honestly blocked without an approved provider, execution logs and a global
  kill switch. Verification: 283 backend tests, 18 Vitest tests and a five-part Playwright journey.
- Phase 14 adds operational hardening: security headers and noindex enforcement on private surfaces,
  MIME-checked uploads, extended retention windows, a verified backup/restore drill, a production
  configuration health probe, zero WCAG A/AA violations on audited pages and public-page performance
  budgets. Verification: 286 backend tests, TypeScript/Vitest clean and a four-part Playwright run.
  Full details in [phase-14-report.md](phase-14-report.md).

## Phase 15 — readiness complete (2026-11-17)

- `php artisan marketing:launch-check` gates launch on production configuration, indexing, published
  home/pages/articles, confirmed contact details, integration connections, backups and provider
  approval; it exits non-zero on any blocking item. Tests: 2 passed.
- The current run blocks on `contact_details` and warns on Google, Meta, Search Console, GA4 and the
  unapproved outbound provider; 35 pages, 3 articles, a published home and a fresh backup pass.
- The deployment runbook covers server requirements, deploy order, DNS/TLS, Search Console, GA4 and
  consent, the cut-over smoke test, rollback and monitoring; the staff guide covers daily operations.
- The live cut-over is **not** performed: it requires owner-supplied hosting, domain/TLS, contact
  details and legal sign-off. See [phase-15-report.md](phase-15-report.md).

## Remaining owner actions

- Confirm contact email/phone and legal entity/governing law.
- Provide hosting, domain/DNS and TLS access.
- Complete Google and Meta credentials/app review; provide Search Console and GA4 access.
- Choose an approved outbound email/SMS/WhatsApp provider before enabling external automation steps.
- Choose offsite backup storage and sign off on Phase 2–4 content and imagery.
