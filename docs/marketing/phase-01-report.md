# Phase 1 report — Public website foundation

Status: **passed** (2026-11-16)
Plan: [Phase 1 in backlog.md](backlog.md#phase-1--public-website-foundation-m)
Architecture: [ADR 0002](../adr/0002-public-website-rendering.md) — Blade public site, React app/admin unchanged

## Objective

Deliver the public site shell, a CMS-backed read model, seeded Phase 1 content, environment-aware
indexing and the browsing/SEO foundation, verified in a real browser at 390, 768 and 1440 px.

## Delivered

### Rendering and routing

- Blade public layout with skip link, sticky header, desktop and mobile navigation, footer, breadcrumbs
  and 404/410 error views.
- Public routes in `backend/routes/web.php`: `/`, `/robots.txt`, `/sitemap.xml` and a CMS page fallback.
- Twelve published pages: home, stock taking, BRATHAMATU POS, how it works, industries, pricing, resources,
  about, contact, request quote, book demo and FAQs.
- Draft pages reserved for later content sign-off: privacy, terms, cookies plus the 10 stock-taking and
  10 BRATHAMATU POS service sub-pages (Phase 2/3).
- Static design system in `backend/public/css/public.css` using the existing emerald brand palette,
  accessible focus states, reduced-motion support and responsive grids.
- Static CSS only: production still needs no Node runtime.

### CMS read model (minimum for Phases 1–3)

- Migration `2026_11_16_120000_create_marketing_cms_tables.php` creates `marketing_settings`,
  `cms_pages`, `cms_page_sections`, `cms_menus`, `cms_menu_items` and `seo_metadata`.
- Models under `App\Models\Marketing` with ULID public ids, published/indexable scopes, ordered
  sections and polymorphic SEO metadata.
- Scoped services under `App\Services\Marketing`: `MarketingSettings`, `PublicMenu`,
  `PublicPageResolver`, `PublicChrome` and `PublicUrls`.
- `MarketingContentSeeder` is idempotent: brand settings, pages, sections, metadata and menus are
  upserted, and existing page sections are never overwritten once created.
- The public brand is BRATHAMATU / BRATHAMATU Stocktake / BRATHAMATU POS across content, routes,
  settings and the POS hub slug.

### SEO and indexing

- Per-page title, description, canonical, Open Graph, Twitter card and robots directives from
  `seo_metadata` with sensible fallbacks.
- Indexing is enabled only when `APP_ENV=production` or `MARKETING_INDEXABLE=true`.
- Outside production, `robots.txt` disallows everything and pages emit `noindex, nofollow`.
- Private prefixes (`/api`, `/app`, `/business`, `/pos`, `/platform`, `/workforce`, auth and account
  paths) always emit `X-Robots-Tag: noindex, nofollow`; 404 and 410 responses do the same.
- `sitemap.xml` lists only published, indexable, non-scheduled pages with lastmod, changefreq and
  priority.
- `MARKETING_CANONICAL_BASE` redirects GET/HEAD requests to the canonical scheme/host with a 301.

### Accessibility and resilience

- Skip link moves focus to `<main tabindex="-1">`.
- Mobile menu is native `<details>` and works without JavaScript; navigation is keyboard-operable.
- All public content renders server-side; no JavaScript is required for essential content.
- CMS output is escaped; missing marketing tables degrade to an empty chrome and a branded 404 rather
  than a 500.

## Routes

| Route | Source | Indexing |
| --- | --- | --- |
| `/` | `cms_pages.slug = home` | indexable in production |
| `/{slug}` | published CMS pages | indexable unless `noindex`/`requires_auth` |
| `/robots.txt` | environment-aware controller | plain text |
| `/sitemap.xml` | published indexable pages | XML |
| Unknown paths | `errors/404` | noindex |
| Private app prefixes | React SPA / API | always noindex |

## Verification

| Check | Result |
| --- | --- |
| Backend suite | **163 passed** (1785 assertions) |
| Public website feature tests | **16 passed** (71 assertions) |
| Public Playwright suite | **23 passed, 1 skipped** (desktop-only mobile-menu skip) |
| PHP formatting (Pint) / TypeScript (`tsc -b`) | clean |
| Mobile Lighthouse (390 px emulation) | Accessibility **100**, SEO **100**, Best practices **100** |
| Desktop Lighthouse (1440 px) | Accessibility **100**, SEO **100**, Best practices **100** |
| Horizontal overflow at 390/768/1440 | none on any of the 12 pages |
| No-JavaScript render | passes |
| Skip link / keyboard mobile menu | passes |

Commands:

```bash
cd backend && php artisan migrate --force && php artisan db:seed --class=MarketingContentSeeder --force
cd backend && php artisan test --filter=PublicWebsiteTest
cd frontend && npm run test:e2e:public
```

Prerequisites for the browser suite: MySQL and Redis running, and the marketing migration/seeder
applied. The Playwright config starts (or reuses) the backend on `http://127.0.0.1:8000`.

The Lighthouse run used a temporary indexable instance
(`MARKETING_INDEXABLE=true MARKETING_CANONICAL_BASE=http://127.0.0.1:8001`) so the SEO score reflects
production indexing rather than the intentionally noindexed local environment.

Evidence in [`docs/evidence/phase-1-public-website/`](../evidence/phase-1-public-website/):

- `mobile-390-home.png`, `tablet-768-home.png`, `desktop-1440-home.png`
- `mobile-390-stock-taking.png`, `tablet-768-stock-taking.png`, `desktop-1440-stock-taking.png`
- `mobile-390-contact.png`, `tablet-768-contact.png`, `desktop-1440-contact.png`
- `mobile-390-404.png`, `tablet-768-404.png`, `desktop-1440-404.png`
- `lighthouse-mobile.report.{html,json}`, `lighthouse-desktop.report.{html,json}`

## Decisions and boundaries

- ADR 0002 Option A is implemented: the public site is server-rendered Blade; the React SPA remains
  the app/admin surface.
- No contact details, prices, testimonials or service areas were invented. Contact output shows a
  “being confirmed” fallback until `MARKETING_CONTACT_*` values are supplied.
- Quote and demo pages are landing pages with contact guidance; real form submission belongs to
  Phase 5.
- No public `Cache-Control` headers yet. The web middleware sets session cookies, so shared caching
  is deferred to the Phase 6 cache design.
- There is no CMS admin UI yet (Phase 4). Content is managed by the seeder until then.
- The 410 view and indexing rule exist, but no route emits 410 until redirects land in Phase 6.
- Domain topology (public domain vs app subdomain) and final brand asset/contact sign-off remain
  owner decisions; the implementation supports them through `MARKETING_CANONICAL_BASE` and
  `FRONTEND_URL`.

## Exit gate

| Requirement | Status |
| --- | --- |
| Responsive browser tests at 390, 768, 1440 px | met |
| Keyboard and accessibility tree | passes (skip link, focusable mobile menu, landmarks, single H1, labelled nav) |
| Manual screen-reader pass (NVDA/VoiceOver) | not performed; recommended before launch |
| Lighthouse SEO/accessibility/best-practices baseline | met (100/100/100 mobile and desktop) |
| Public routes, robots, sitemap, canonical host, noindex private routes | met |

## Next phase

Phase 2 — core marketing pages: legal copy sign-off, richer page copy, working CTA journeys and the
20 service/POS landing pages currently seeded as drafts (Phase 3), before the CMS admin UI in Phase 4.
