# Implementation backlog — Phases 1–15

Status: approved; Phases 1–14 engineering complete (2026-11-17); Phase 15 launch awaits owner
sign-off. Each phase follows the master plan's rule: implement, test in a real browser, record
evidence, obtain acceptance, then start the next phase.

Size: S ≈ 1–2 days, M ≈ 3–5 days, L ≈ 1–2 weeks (engineering estimate, excluding provider review
lead times).

## Phase 0 — Repository audit and architecture

Status: **complete**. Deliverables in this folder plus ADR 0002. Architecture approved by proceeding
with Phase 1.

## Phase 1 — Public website foundation (M)

Status: **complete** (2026-11-16). Report: [phase-01-report.md](phase-01-report.md). Evidence:
`docs/evidence/phase-1-public-website/`.

- Blade public layout, header, footer, responsive navigation, mobile menu, skip link, 404/410 pages.
- Shared design tokens derived from the existing React theme; accessible focus states and typography.
- Section component contract (hero, rich text, cards, process, FAQ, CTA, contact) driven by a read
  model, so Phase 2/3 content is seeded through CMS records from day one (admin UI arrives in Phase 4).
- Public route registration, canonical host handling, `noindex` on app/auth routes.
- `robots.txt` and sitemap scaffolding with environment awareness.

Exit gate: responsive browser tests at 390, 768 and 1440 px; keyboard and accessibility-tree smoke
test; Lighthouse SEO/accessibility/best-practices baseline recorded. A manual screen-reader pass
remains recommended before launch.

Dependencies: ADR 0002 approved; BRATHAMATU public brand applied. Logo, domain topology and contact
details remain owner decisions and are settings-driven.

## Phase 2 — Core marketing pages (L)

Status: **engineering complete** (2026-11-16); owner content/legal sign-off pending. Report:
[phase-02-report.md](phase-02-report.md). Evidence: `docs/evidence/phase-2-core-pages/`.

Build: homepage, about, stock-taking hub, BRATHAMATU POS hub, how it works, industries hub, pricing,
contact, FAQ and legal pages. Approved copy seeded as CMS content.

Rules: every CTA routes to quote/demo forms; no invented prices, testimonials, response times or
service areas; contact details come from settings.

Exit gate: content sign-off; working navigation and CTAs; mobile/tablet/desktop browser tests; all
pages render without JavaScript for essential content.

Delivered: 15 published pages including `/privacy`, `/terms` and `/cookies` (noindex pending legal
sign-off), enriched core copy, working quote/demo journeys, a footer legal menu and idempotent
seeding. Remaining gate item: owner sign-off on copy, legal entity/contact details, governing law,
retention periods and the cookie/consent policy.

## Phase 3 — Service and product landing pages (L)

Status: **engineering complete** (2026-11-16); owner content sign-off pending. Report:
[phase-03-report.md](phase-03-report.md). Evidence: `docs/evidence/phase-3-landing-pages/`.

Build: ten stock-taking pages (on-demand, monthly, annual, recurring, full, cycle counting, branch,
warehouse, independent verification, recounts) and ten POS pages (retail, supermarket, pharmacy,
wholesale, hardware, restaurant, multi-branch, inventory, barcode/catalogue, stock-taking integration).

Rules: unique, substantive content per page; no thin keyword permutations; recurring customers can
still request on-demand jobs (CTA copy, not a new booking flow yet).

Exit gate: unique-page review; CTA journey to quote/demo; browser tests; no duplicate metadata.

Delivered: 20 published landing pages and hub links to every one, unique titles/descriptions,
474–541 words per page, landing CTAs exercised at 390/768/1440 px, and 100 accessibility on both
Lighthouse profiles. Remaining gate item: owner sign-off on the landing-page copy.

## Phase 4 — CMS and media library (L)

Status: **engineering complete** (2026-11-16). Report: [phase-04-report.md](phase-04-report.md).
Evidence: `docs/evidence/phase-4-cms-media/`.

Build: page editor (sections, reorder, preview), draft → review → approved → scheduled → published
workflow, version history and restore, navigation/banner/CTA/FAQ/testimonial management, media library
with folders, alt text, captions, usage tracking and replacement, scheduled publishing.

Rules: edit/review/publish permissions separated; approval of one's own page blocked when separation of
duties is on; every transition audited; preview uses signed, expiring, noindex URLs.

Exit gate: publish, schedule, unpublish and restore tested in the browser; media replacement updates
usage; permission denial tested for each role.

Delivered: granular `cms.*`/`media.manage` permissions on `content-editor`, `content-publisher`,
`content-manager` and `marketing-manager` (D5 applied; unused `content.manage` replaced); versioned
publishing with separation of duties and audit entries; `marketing:publish-scheduled` every minute;
30-minute signed noindex previews; admin screens for overview, pages, media, navigation and content
libraries. Media replacement keeps the delivery URL and updates usage. Verified with 181 backend tests
and a two-part browser journey covering publish, schedule, unpublish, restore, replacement and role
denial.

## Phase 5 — Lead capture and CRM (L)

Status: **engineering complete** (2026-11-16); owner copy/legal/contact sign-off pending. Report:
[phase-05-report.md](phase-05-report.md). Evidence: `docs/evidence/phase-5-leads-crm/`.

Build: quote, POS demo, contact, callback and partnership forms; server-side validation; honeypot and
throttle; consent capture; UTM/referrer attribution; lead pipeline with statuses, assignment,
activities and notifications; restricted CSV export.

Rules: a lead never creates a paid job; consent ledger is authoritative; exports are permissioned,
rate-limited and audited; duplicate submissions are idempotent.

Exit gate: submit every form in the browser; verify lead, consent and attribution rows; assign and work
a lead; verify unauthorized roles cannot export; spam/rate-limit behaviour verified.

## Phase 6 — Technical SEO (M)

Status: **engineering complete** (2026-11-16). Report: [phase-06-report.md](phase-06-report.md).
Evidence: `docs/evidence/phase-6-technical-seo/`.

Build: per-page metadata, canonical URLs, Open Graph, breadcrumbs, index/noindex controls, redirects,
dynamic sitemap, environment-aware robots, SEO diagnostics (missing/duplicate metadata, canonical
conflicts, broken links, redirect chains, orphan pages) and CWV ingestion.

Rules: one canonical per indexable page; previews and app routes noindex; slug changes create 301s;
sitemap excludes drafts and archives marked noindex.

Exit gate: crawl with a crawler and verify metadata, canonicals, robots and sitemap; redirect tests;
diagnostics detect seeded defects.

## Phase 7 — Structured data (M)

Status: **engineering complete** (2026-11-17). Report: [phase-07-report.md](phase-07-report.md).
Evidence: `docs/evidence/phase-7-structured-data/`. Article emission waits for Phase 8 posts and
LocalBusiness for verified locations; both are blocking validation issues when requested. Product
markup is unavailable until a public catalogue with real prices exists.

Build: Organization, WebSite, WebPage, Service, Product, SoftwareApplication, Article/BlogPosting,
BreadcrumbList, FAQPage and eligible LocalBusiness templates; admin preview, validation, missing-field
reporting and validation history.

Rules: JSON-LD must match visible content; no invented reviews, ratings, prices, addresses or hours;
LocalBusiness only for genuine verified locations.

Exit gate: validate every schema type; compare against visible content; invalid templates blocked from
publishing.

## Phase 8 — Blog and resources (M)

Status: **engineering complete** (2026-11-17). Report: [phase-08-report.md](phase-08-report.md).
Evidence: `docs/evidence/phase-8-blog-resources/`. Article markup is generated from published posts
and validated at publish time; LocalBusiness and Product remain gated as recorded in Phase 7.

Build: article index/detail, categories, tags, authors, related articles, editorial calendar, resource
downloads (gated or public), RSS feed.

Rules: tag archives noindex by default; one primary category; publish workflow shared with CMS;
downloads respect consent settings.

Exit gate: publish, schedule and index an article; verify related content, feeds, downloads and the
sitemap; browser tests for index and article templates.

## Phase 9 — Google integrations (L, plus external lead time)

Status: **engineering complete** (2026-11-17). Report: [phase-09-report.md](phase-09-report.md).
Evidence: `docs/evidence/phase-9-google-integrations/`. Owner Google Cloud OAuth credentials and
Business Profile API allowlist remain external prerequisites; the workspace shows an explicit
setup-required state and never invents Google metrics while they are pending.

Build: Google OAuth (state + PKCE), connection dashboard, Business Profile locations/reviews where
available, Search Console metrics, GA4 reporting, read-only GTM configuration, queued syncs, logs,
health and reconnect/disconnect.

Rules: no fake data or unsupported promises; token expiry handled explicitly; all actions audited.

Exit gate: connect a test Google account; verify scopes, encrypted tokens, sync data, error states and
disconnect; permission denial tested. External prerequisite: Business Profile API allowlist.

## Phase 10 — Meta integrations (L, plus app review lead time)

Status: **engineering complete** (2026-11-17). Report: [phase-10-report.md](phase-10-report.md).
Evidence: `docs/evidence/phase-10-meta-integrations/`. Owner Meta app credentials, app review and
confirmed Business/Page/Instagram/ad assets remain external prerequisites; the workspace shows an
explicit setup-required state, never invents Meta metrics and keeps ad spend read-only.

Build: Meta OAuth, Business/Page/Instagram/ad-account connection, approved publishing, scheduled posts,
insights, ad reporting and spend approval controls.

Rules: publishing requires approval; spend requires `campaigns.spend` plus internal approval; no
automation can spend; asset permission errors shown honestly.

Exit gate: connect test assets; publish/schedule an approved post; verify insight sync and ad reporting;
verify an editor cannot publish or spend.

## Phase 11 — Campaign management (L)

Status: **engineering complete** (2026-11-17). Report: [phase-11-report.md](phase-11-report.md).
Evidence: `docs/evidence/phase-11-campaigns/`. Approval, activation, creative, tracking links and
results are live; owner still confirms production approver/spend holders.

Build: campaign creation, objectives, channels, audiences, creative, landing-page link, UTM builder,
budgets, owner/approver, draft → review → approved → active → paused/completed, change history and
results.

Rules: no campaign bypasses approval; budget changes after approval require re-approval; provider-side
caps configured where supported.

Exit gate: draft and approve a campaign in the browser; verify activation blocked without approval and
permission; verify audit and budget controls.

## Phase 12 — Analytics and reporting (M)

Status: **engineering complete** (2026-11-17). Report: [phase-12-report.md](phase-12-report.md).
Evidence: `docs/evidence/phase-12-analytics/`. Consent-gated event ingestion, server-side conversions,
funnel/campaign/landing-page/search reports and the analytics workspace are live; search reporting
stays honestly unavailable until owner Google Search Console credentials are connected.

Build: consent-aware event tracking, conversion events, lead funnel, campaign performance, landing-page
reports, search performance, attribution and dashboards.

Rules: unconsented sessions excluded; no raw PII in analytics; server-side conversions reconcile with
form submissions.

Exit gate: verify events only fire with consent; funnel and campaign reports match seeded data;
consent withdrawal removes future tracking.

## Phase 13 — Marketing automation (M)

Status: **engineering complete** (2026-11-17). Report: [phase-13-report.md](phase-13-report.md).
Evidence: `docs/evidence/phase-13-automation/`. Workflow builder (lead created/assigned/status changed),
conditions, ordered steps, idempotent runs, retries with backoff, execution logs, in-app notifications,
consent-gated outbound steps that stay blocked without an approved provider, and the global kill switch
are live.

Build: workflow engine with triggers, conditions, delayed steps, approved notifications, consent checks,
execution logs and a global kill switch.

Rules: no external send without consent and an approved provider; idempotent execution; kill switch
tested; failures retried with backoff and alerted.

Exit gate: run workflows for lead created/assigned/status changed; verify consent blocking, idempotency,
logs and kill switch in the browser.

## Phase 14 — Security and operational hardening (M)

**Status: engineering complete** (2026-11-17). Report: [phase-14-report.md](phase-14-report.md).
Evidence: `docs/evidence/phase-14-hardening/` (axe JSON, performance JSON, screenshots). Delivered:
security headers and unindexed private surfaces, MIME-checked media uploads, extended retention
windows, backup/verified restore drill (205 tables / 4,774 rows), production configuration health
probe, WCAG A/AA audits with zero violations on audited pages, and public-page performance budgets.
Owner follow-up: offsite backup storage.

Build: security review, permission matrix review, privacy/retention workflows, upload security,
backup/recovery test, performance budgets, accessibility audit, monitoring/alerting.

Rules: no unresolved critical/high issue; public forms protected; OAuth secrets and tokens protected;
private routes unindexed.

Exit gate: documented review, recovery test, accessibility and performance results, no critical/high
findings.

## Phase 15 — Production launch (M)

**Status: readiness engineering complete** (2026-11-17); live cut-over awaits owner inputs. Report:
[phase-15-report.md](phase-15-report.md). Runbook: [phase-15-runbook.md](phase-15-runbook.md). Staff
guide: [staff-guide.md](staff-guide.md). Launch gate: `php artisan marketing:launch-check` (currently
blocked on `contact_details`; integration warnings expected). Evidence:
`docs/evidence/phase-15-launch/launch-check.txt`.

Build: domain and SSL, production configuration, CDN/cache, Search Console, GA4 and consent, approved
Google/Meta connections, initial content, redirects, staff training and post-launch review.

Exit gate: owner production sign-off and the master plan's final acceptance standard.

## Deferred until explicitly approved

- Email/SMS/WhatsApp provider selection and external sending.
- Ad spend activation and provider-side campaign launch.
- LocalBusiness pages beyond genuine service areas.
- Multi-language content.
- AI-generated content or autonomous campaign changes.
- Any change to tenant stock-taking, POS, finance or workforce behaviour.

## Cross-phase acceptance

Every phase records: scope delivered, tests run, browser evidence (screenshots/logs), permission and
denial checks, responsive checks, known limitations, and an explicit acceptance statement. A phase is
not complete because the code compiles.
