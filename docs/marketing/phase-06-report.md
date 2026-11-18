# Phase 6 — Technical SEO report

Status: **engineering complete** (2026-11-16). Owner sign-off is not required for the tooling itself;
launch-time crawl and Search Console verification move to Phases 9 and 15.

## Objective

Give the marketing team the operational tools to keep the public site technically healthy: redirects
that survive slug changes, an SEO diagnostics report across published pages, and Core Web Vitals
collection from real visitors. Diagnostics must flag the defects in the backlog exit gate:
missing/duplicate metadata, canonical conflicts, broken internal links, redirect chains and orphan
pages.

## Delivered

### Redirects

- New schema `marketing_redirects` (uuid, unique `from_path`, `to_path`, status code, reason, page
  link, active flag, hit counter) and `seo_core_web_vitals` (path, device, LCP/CLS/INP/TTFB, metric
  payload, measured_at).
- Publishing a slug change on a page that was already live automatically records a 301 from the old
  URL to the new one (`SeoRedirectService::recordSlugChange`). The home page is exempt.
- The public fallback resolves active redirects before returning 404: chains are followed up to five
  hops, loops are stopped, every resolved request increments `hits`, and the first hop status code is
  returned.
- Manual redirect CRUD at `/api/v1/platform/marketing/seo/redirects` with 301/302/307/308 support,
  self-redirect and loop rejection, active/inactive toggling and audit events
  (`marketing.redirect.created|updated|deleted`).

### Diagnostics

`GET /api/v1/platform/marketing/seo/diagnostics` scans published page snapshots (metadata, canonical,
indexable flag), section links, visible menu items, active CTAs and banner links, then reports:

- missing metadata (title/description),
- duplicate titles and descriptions,
- broken internal links (published paths, redirect sources and known app/media/form prefixes are
  excluded),
- canonical conflicts (multiple pages declaring the same canonical) and canonicals pointing at
  unpublished paths,
- redirect chains longer than one hop and loops,
- orphan pages (indexable non-home pages with no inbound internal link).

Indexability is resolved with the same environment-aware rules as the public site, so local/staging
runs report zero indexable pages by design; production behaviour follows `MARKETING_INDEXABLE`.

### Core Web Vitals

- The public Blade layout sends one anonymised `sendBeacon` sample per visit (LCP via
  PerformanceObserver, CLS from non-input layout shifts, INP from event timing, TTFB from navigation
  timing) to `POST /api/v1/public/web-vitals`. The endpoint validates and stores the sample, returns
  `202`, is IP rate-limited to 60 requests/minute and is not linked from previews.
- `GET /api/v1/platform/marketing/seo/vitals` aggregates any 1–90 day window (optionally a single
  path): sample counts, device split, p75 LCP/CLS/INP/TTFB, share of good LCP samples and per-path
  rows plus the latest 20 samples.

### Admin UI

New `/platform/marketing/seo` screen (permission `cms.view`, sidebar under Website, linked from the
marketing overview):

- diagnostics summary cards, per-issue counters and expandable issue lists,
- redirects list with hit counts, reason, active toggle, delete and a create form (requires
  `cms.publish`; readers see an explanatory hint),
- Core Web Vitals panel with a 7/28/90-day window, path filter, metric cards and per-path table.

## API and permissions

| Endpoint | Method | Permission |
| --- | --- | --- |
| `/api/v1/public/web-vitals` | POST | public, `throttle:web-vitals` |
| `/api/v1/platform/marketing/seo/diagnostics` | GET | `cms.view` |
| `/api/v1/platform/marketing/seo/vitals` | GET | `cms.view` |
| `/api/v1/platform/marketing/seo/redirects` | GET | `cms.view` |
| `/api/v1/platform/marketing/seo/redirects` | POST | `cms.publish` |
| `/api/v1/platform/marketing/seo/redirects/{redirect}` | PATCH / DELETE | `cms.publish` |

Tenant roles remain excluded from the platform marketing surface. All redirect mutations are audited.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=SeoOperationsTest` | 5 passed, 145 assertions |
| Full backend suite | 205 passed, 2389 assertions |
| OpenAPI contract test | Included in the full suite, pass |
| Frontend `tsc --noEmit` | clean |
| Vitest | 18 passed |
| Playwright `e2e/seo.spec.ts` | 2 passed (4.4 min + 33 s) |
| Diagnostics seeded-defect test | Missing metadata, duplicate titles/descriptions, broken link, broken canonical, redirect chain and orphan detection all asserted |

Browser journey covers: SEO overview, manual redirect creation, live 301 verification with
`maxRedirects: 0`, disabling (404) and deleting, redirect-chain detection in diagnostics, vitals
beacon ingestion and aggregation, a full slug change with republish producing an automatic 301, and
permission boundaries (editor read-only, tenant denied).

### Evidence

`docs/evidence/phase-6-technical-seo/screens/`

| File | Contents |
| --- | --- |
| `01-seo-overview.png` | Diagnostics with zero issues, empty redirects and vitals summaries |
| `02-redirect-created.png` | Manual 301 visible with hits, reason, active state |
| `03-diagnostics-chain.png` | Redirect chain flagged with its full `a → b → /pricing` path |
| `04-core-web-vitals.png` | Three beacon samples aggregated: p75 2600 ms LCP, 66.7% good, device split |
| `05-slug-change-redirect.png` | Automatic `Page slug changed` 301 after republishing a renamed page |

## Open items

- A hosted crawl (for example Screaming Frog or an equivalent CI crawler) against the production
  domain is still required by the master plan; the diagnostics report and HTTP assertions cover the
  same rules locally.
- Search Console verification and CWV field data become available after Phase 9 credentials and the
  production launch.
- Owner copy, legal and contact decisions remain outstanding from Phases 2–5; they do not block the
  SEO tooling.

## Next

Phase 7 adds JSON-LD structured data templates (Organization, WebSite, WebPage, Service, Product,
SoftwareApplication, FAQPage, BreadcrumbList, Article) with a preview/validation step so invalid
templates cannot be published.
