# Phase 3 report — Service and product landing pages

Status: **engineering complete and verified** (2026-11-16); owner content sign-off pending
Plan: [Phase 3 in backlog.md](backlog.md#phase-3--service-and-product-landing-pages-l)
Architecture: [ADR 0002](../adr/0002-public-website-rendering.md) — Blade public site, TanStack app/admin
Previous: [phase-02-report.md](phase-02-report.md)

## Objective

Publish the ten stock-taking and ten BRATHAMATU POS landing pages with unique, substantive content,
working quote/demo journeys, unique metadata and browser-verified behaviour at 390, 768 and 1440 px.

## Delivered

### Pages and content

Twenty published landing pages, each with a hero, introductory rich text, a linked service grid, a
count-specific process, FAQs and a closing CTA band:

| Stock-taking | BRATHAMATU POS |
| --- | --- |
| `/stock-taking/on-demand` | `/brathamatu-pos/retail` |
| `/stock-taking/monthly` | `/brathamatu-pos/supermarket` |
| `/stock-taking/annual` | `/brathamatu-pos/pharmacy` |
| `/stock-taking/recurring` | `/brathamatu-pos/wholesale` |
| `/stock-taking/full` | `/brathamatu-pos/hardware` |
| `/stock-taking/cycle-counting` | `/brathamatu-pos/restaurant` |
| `/stock-taking/branch` | `/brathamatu-pos/multi-branch` |
| `/stock-taking/warehouse` | `/brathamatu-pos/inventory-management` |
| `/stock-taking/independent-verification` | `/brathamatu-pos/barcode-catalogue` |
| `/stock-taking/recounts` | `/brathamatu-pos/stocktaking-integration` |

Each page carries its own scope, process and FAQs rather than a keyword permutation: 474–541 words of
seeded section content per page across six sections. All 20 have unique SEO titles and descriptions.
The recurring page explains that recurring customers can still request one-off counts; the
independent-verification page states that the service is not a statutory audit; the pharmacy page ties
batch and expiry tracking to the inventory batch/tracking model. No prices, testimonials, response-time
promises, service areas or contact details were invented.

### Hub linking

The stock-taking and POS hubs now link to every landing page. Card partials accept an optional URL and
render the title as a link plus a “Learn more about {title}” action for screen readers. The homepage
service grid links to the on-demand, monthly, annual and recurring pages.

### CTAs

Every stock-taking landing page sends its primary CTA to `/request-quote`; every POS landing page sends
its primary CTA to `/book-demo`. Secondary CTAs route to the relevant hub or, on the integration page,
to `/request-quote`. All journeys were exercised in the browser, including `/request-quote` and
`/book-demo` from a landing page at each viewport.

### Accessibility correction

Making hub cards linkable exposed two issues on the dark POS workflow grid: brand-green links on the
dark background measured 2.95:1, and the brand link’s visible initials were absent from its accessible
name. Dark-section links now use `--brand-200` (white on hover), and the brand accessible name includes
the visible initials. Lighthouse accessibility returned to 100 on both profiles.

### CMS seeding

- Content revision raised to 5; `publishPage()` content is created for all landing pages and the
  `landingPages()` step is split into stock-taking and POS builders.
- The database holds 35 published pages, zero drafts and 22 `landing`-type records (20 landing pages
  plus the quote and demo pages).
- A second seeder run remains idempotent.

## Verification

| Check | Result |
| --- | --- |
| Backend suite | **168 passed** (1987 assertions) |
| Public website feature tests | **21 passed** (273 assertions) |
| Public Playwright suite | **32 passed, 1 skipped** (35 pages at 390/768/1440; desktop-only mobile-menu skip) |
| TypeScript (`npx tsc --noEmit`) | clean |
| Pint (changed PHP files) | clean |
| Landing page metadata | 20 unique titles and descriptions |
| Landing page content | 474–541 words and six sections per page |
| Draft pages | 0; 35 pages published; content revision 5 |
| Horizontal overflow | none on any of the 35 public pages at 390/768/1440 |
| No-JavaScript render | passes on home, how it works, privacy, recounts and pharmacy |
| Internal link crawl on core pages | every internal link returns 200 |
| Landing CTA journeys | on-demand → quote and retail → demo pass at every width |
| Mobile Lighthouse (`/stock-taking/on-demand`, 390 px) | Performance **100**, Accessibility **100**, Best practices **100**, SEO **100** |
| Desktop Lighthouse (`/brathamatu-pos/retail`, 1440 px) | Performance **89**, Accessibility **100**, Best practices **100**, SEO **100** |

Notes:

- The Playwright loop validates every one of the 35 public pages (status, H1, footer, overflow and,
  for landing pages, a visible primary CTA). Representative landing pages are captured as screenshots;
  the loop itself covers all twenty.
- The Lighthouse run used a temporary indexable instance (`MARKETING_INDEXABLE=true`) on a local
  single-threaded PHP development server, so performance is a development baseline, not a production
  measurement with caching and a CDN.
- A project-wide Pint run still reports pre-existing formatting drift in untouched files; only the two
  PHP files changed in this phase are in scope and pass.

Commands:

```bash
cd backend && php artisan db:seed --class=MarketingContentSeeder --force
cd backend && php artisan test
cd frontend && npx tsc --noEmit
cd frontend && npm run test:e2e:public
```

The Playwright config starts or reuses the backend on `http://127.0.0.1:8000` and requires MySQL and
Redis. The seeder must have been applied.

Evidence in [`docs/evidence/phase-3-landing-pages/`](../evidence/phase-3-landing-pages/):

- On-demand and retail landing pages at 390, 768 and 1440 px.
- `lighthouse-mobile.report.{html,json}` and `lighthouse-desktop.report.{html,json}`.

## Decisions and boundaries

- Phase 3 was implemented on the owner’s instruction while Phase 2 copy and legal sign-off remain
  pending. Landing-page copy therefore also needs owner review before launch.
- Direct contact details, prices, service areas and testimonials remain owner decisions (D6) and are
  read from settings.
- Quote, demo and contact forms still submit in Phase 5; the landing pages route users to the existing
  quote/demo pages, which explain the process.
- Legal notices remain noindex pending legal sign-off.

## Exit gate

| Requirement | Status |
| --- | --- |
| Unique-page review | met — unique metadata and 474–541 words per page |
| CTA journey to quote/demo | met — verified at 390/768/1440 |
| Browser tests | met — all 35 pages, three widths |
| No duplicate metadata | met — 20 unique titles and descriptions |
| Content sign-off | **pending owner review** |

## Next phase

Phase 4 adds the CMS and media library: page editor with section reordering and preview, the
draft → review → approved → scheduled → published workflow, version history, navigation/CTA/FAQ
management and a media library with usage tracking. It starts after Phase 3 acceptance and the
outstanding owner copy, legal and contact decisions.
