# Phase 2 report — Core marketing pages

Status: **engineering complete and verified** (2026-11-16); owner content and legal sign-off pending
Plan: [Phase 2 in backlog.md](backlog.md#phase-2--core-marketing-pages-l)
Architecture: [ADR 0002](../adr/0002-public-website-rendering.md) — Blade public site, TanStack app/admin
Previous: [phase-01-report.md](phase-01-report.md)

## Objective

Complete the core public marketing pages with approved, non-invented copy, working quote/demo
journeys, published legal notices and browser-verified behaviour at 390, 768 and 1440 px.

## Delivered

### Pages and content

- Fifteen published public pages: home, stock taking, BRATHAMATU POS, how it works, industries, pricing,
  resources, about, contact, request quote, book demo, FAQs, privacy, terms and cookies.
- Homepage additions: “Why businesses choose BRATHAMATU” and a resource-hub teaser.
- BRATHAMATU POS additions: the demonstration-to-daily-use path and a checklist for what to prepare before
  a demo.
- How it works additions: “Where BRATHAMATU POS fits” with a link to BRATHAMATU POS, plus a “Before your first
  count” FAQ.
- Industries, contact and global FAQ content expanded around branches, batches/expiry dates, stock
  locations, confidentiality requests, scheduling and demo preparation.
- No prices, testimonials, response-time promises, service areas or contact details were invented.
  Prices and direct contact details remain settings/quote-driven.

### Legal pages

- Published `/privacy`, `/terms` and `/cookies` using a new `legal` section type.
- All three notices are **noindex** and excluded from `sitemap.xml` until the legal details are
  confirmed and the content is signed off.
- The footer renders a `legal` menu with Privacy Notice, Terms of Service and Cookie Notice.
- Each notice carries a visible “Last updated: 16 November 2026” date and a single H1.
- The notices explicitly leave the legal entity, registered address, data-protection contact,
  retention periods, governing law and consent policy for owner/legal confirmation instead of
  inventing them.

### CTAs and navigation

- Section-level CTA support was added to rich-text and contact sections.
- The quote and demo pages no longer link to themselves: their contact cards link to `/contact` with
  “Contact the team”.
- Every primary CTA routes to `/request-quote` or `/book-demo`; both journeys were exercised in the
  browser.
- Footer legal links, primary navigation and the mobile menu all resolve.

### CMS seeding

- Content revision raised to 4; the revision guard refreshes seeded sections once, then stays
  idempotent.
- The seeder publishes the legal pages, creates the legal menu and updates page SEO metadata.
- A one-time `brand.revision` migration renames legacy `panda-pos` pages and POS menu labels to
  BRATHAMATU, so existing databases converge without manual edits.
- A regression test confirms a second seeder run creates no duplicate pages, sections or menu items.

### Brand alignment

- Public copy, SEO titles/descriptions, navigation, settings and the POS hub slug now use BRATHAMATU,
  BRATHAMATU Stocktake and BRATHAMATU POS throughout; the POS hub is `/brathamatu-pos`.
- Public feature tests assert the new brand strings and Playwright screenshots were recaptured with the
  renamed site.

## Verification

| Check | Result |
| --- | --- |
| Backend suite | **167 passed** (1859 assertions) |
| Public website feature tests | **20 passed** (145 assertions) |
| Public Playwright suite | **32 passed, 1 skipped** (33 tests across 390/768/1440; desktop-only mobile-menu skip) |
| TypeScript (`npx tsc --noEmit`) | clean |
| Pint (Phase 2 changed files) | clean |
| Mobile Lighthouse (390 px emulation) | Performance **96**, Accessibility **100**, Best practices **100**, SEO **100** |
| Desktop Lighthouse (1440 px) | Performance **89**, Accessibility **100**, Best practices **100**, SEO **100** |
| Horizontal overflow at 390/768/1440 | none on any of the 15 pages |
| No-JavaScript render | passes on home, how it works and privacy |
| Internal link crawl on core pages | every internal link returns 200 |
| Legal pages | render, show the date, emit `noindex`, excluded from the sitemap |
| Quote/demo CTA journeys | pass; contact cards link to `/contact` |

Notes:

- The Lighthouse run used a temporary indexable instance (`MARKETING_INDEXABLE=true`) on a local
  single-threaded PHP development server. Accessibility, best-practices and SEO are 100 on both
  profiles. Performance is a development-server baseline, not a production measurement with caching
  and a CDN; desktop performance is the noisiest metric in this setup.
- A project-wide Pint run reports pre-existing formatting drift in 98 untouched files. Phase 2 did not
  reformat those files to avoid unrelated churn; the two PHP files changed in this phase pass Pint.

Commands:

```bash
cd backend && php artisan db:seed --class=MarketingContentSeeder --force
cd backend && php artisan test --filter=PublicWebsiteTest
cd frontend && npx tsc --noEmit
cd frontend && npm run test:e2e:public
```

The Playwright config starts or reuses the backend on `http://127.0.0.1:8000` and requires MySQL and
Redis. The seeder must have been applied.

Evidence in [`docs/evidence/phase-2-core-pages/`](../evidence/phase-2-core-pages/):

- Home, stock taking, contact, how it works and privacy at 390, 768 and 1440 px.
- The branded 404 and the book-demo CTA journey at each width.
- `lighthouse-mobile.report.{html,json}` and `lighthouse-desktop.report.{html,json}`.

## Decisions and boundaries

- Architecture confirmed by the owner on 2026-11-16: Laravel serves the public site with Blade;
  TanStack React remains the app and marketing-admin frontend. ADR 0002 is accepted.
- Legal notices are published but noindex and are not final. Before launch they need the legal
  entity name, registered address, data-protection contact, retention periods, governing law and
  dispute process.
- The cookie consent policy and tracking preference centre are not built yet. The cookie notice states
  that optional analytics or marketing cookies will only be set after required consent and that a
  preference control will exist before they are enabled. No analytics or marketing tags are loaded.
- Quote, demo and contact forms still submit in Phase 5. The current pages explain the process and
  route users to the contact page.
- Direct contact details, prices, service areas and testimonials remain owner decisions (D6) and are
  read from settings.
- The 20 stock-taking and BRATHAMATU POS landing pages were drafts at the end of Phase 2; Phase 3
  published them (see [phase-03-report.md](phase-03-report.md)).

## Exit gate

| Requirement | Status |
| --- | --- |
| Content sign-off | **pending owner review** — copy and legal placeholders need approval |
| Working navigation and CTAs | met |
| Mobile/tablet/desktop browser tests | met (390/768/1440) |
| Essential content without JavaScript | met |
| Legal pages published, noindex and linked | met, pending legal sign-off |

## Next phase

Phase 3 adds the ten stock-taking and ten BRATHAMATU POS landing pages. Phase 3 was subsequently
implemented and verified at the engineering level ([phase-03-report.md](phase-03-report.md)); owner
sign-off on Phase 2 copy and legal details is still pending. The urgent owner inputs are the legal
entity/contact details, governing law, retention periods and the cookie/marketing consent policy.
