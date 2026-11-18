# Public route map

Status: proposed for Phase 1 approval (2026-11-16)
Hosting assumption: public website and marketing admin share the existing Laravel application; the
operational SPA keeps its current routes. Domain names are still an owner decision (see §14 of the
master plan), so this map is host-agnostic.

## URL conventions

- Lowercase, hyphenated, no trailing slash; one canonical URL per page.
- Public pages are server-rendered at `/…`; the authenticated app stays under `/app`, `/business`,
  `/platform`, `/workforce` and `/pos` on the app host (or `/app/*` on one host — decision pending).
- CMS slug is the URL. Changing a published slug creates an automatic 301 in `seo_redirects`.
- Indexable pages carry exactly one canonical. Filtered/paginated resource lists use `?page=2`,
  self-canonical with `rel=next/prev`; no indexable filter combinations.
- Preview URLs use a signed, expiring token and `X-Robots-Tag: noindex`.
- Every authenticated app route returns `X-Robots-Tag: noindex` and is excluded in `robots.txt`.

## Public site (indexable)

| Route | Template / purpose | Data source | JSON-LD |
| --- | --- | --- | --- |
| `/` | Homepage: hero, service modes, POS intro, industries, process, proof, FAQ, latest resources, contact | CMS page + latest articles + approved testimonials | Organization, WebSite, WebPage, FAQPage |
| `/stock-taking` | Stock-taking hub | CMS page + service index | WebPage, Service, BreadcrumbList |
| `/stock-taking/on-demand` | On-demand landing page | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/monthly` | Monthly landing page | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/annual` | Annual landing page | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/recurring` | Custom recurring schedules | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/full` | Full physical stock take | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/cycle-counting` | Cycle counting | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/branch` | Multi-branch stock taking | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/warehouse` | Warehouse and distribution | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/independent-verification` | Independent verification | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/stock-taking/recounts` | Recounts and discrepancy investigation | CMS landing page | Service, FAQPage, BreadcrumbList |
| `/brathamatu-pos` | POS hub | CMS page + POS product index | SoftwareApplication, WebPage, BreadcrumbList |
| `/brathamatu-pos/retail` | Retail POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/supermarket` | Supermarket POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/pharmacy` | Pharmacy POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/wholesale` | Wholesale POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/hardware` | Hardware POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/restaurant` | Restaurant POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/multi-branch` | Multi-branch POS | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/inventory-management` | Inventory management | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/barcode-catalogue` | Barcode and catalogue | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/brathamatu-pos/stocktaking-integration` | POS + stock-taking integration | CMS landing page | SoftwareApplication, FAQPage, BreadcrumbList |
| `/how-it-works` | End-to-end service process | CMS page | WebPage, HowTo (only if steps match visible content) |
| `/industries` | Industry hub | CMS page + industry index | WebPage, BreadcrumbList |
| `/industries/{slug}` | Curated industry page | Industry content record | WebPage, Service, BreadcrumbList |
| `/pricing` | Published pricing or “request a quote” | CMS page + pricing settings | WebPage, Service, BreadcrumbList |
| `/locations` | Genuine service areas only | Service-area index | WebPage, BreadcrumbList |
| `/locations/{slug}` | Genuine eligible location | Service-area record | LocalBusiness only if genuine NAP data exists |
| `/resources` | Resource hub / blog index | Published articles | Blog, WebPage, BreadcrumbList |
| `/resources?page={n}` | Paginated article index (self-canonical) | Published articles | Blog |
| `/resources/categories/{slug}` | Category archive | Published articles | CollectionPage, BreadcrumbList |
| `/resources/tags/{slug}` | Tag archive (noindex by default) | Published articles | — |
| `/resources/{slug}` | Article | Published article | BlogPosting, BreadcrumbList, FAQPage if eligible |
| `/resources/downloads/{slug}` | Gated or public download | Resource download record | WebPage |
| `/about` | Company, method, credentials | CMS page | AboutPage, Organization, BreadcrumbList |
| `/contact` | Contact details and form | CMS page + global settings | ContactPage, Organization, BreadcrumbList |
| `/request-quote` | Stock-taking quote form | CMS form page | WebPage, BreadcrumbList |
| `/book-demo` | BRATHAMATU POS demo form | CMS form page | WebPage, BreadcrumbList |
| `/faqs` | Global FAQ | Approved FAQ records | FAQPage, BreadcrumbList |
| `/privacy` | Privacy notice | Legal page | WebPage |
| `/terms` | Terms of service | Legal page | WebPage |
| `/cookies` | Cookie and tracking notice | Legal page | WebPage |
| `/consent-preferences` | Tracking preference centre | Consent settings | — (noindex allowed) |

## Public non-page routes

| Route | Purpose |
| --- | --- |
| `/sitemap.xml` | Dynamic sitemap, generated from published pages/posts, regenerated on publish and nightly |
| `/robots.txt` | Environment-aware; allows public pages, disallows app/auth/api/preview routes |
| `/rss.xml` | Published article feed (optional, Phase 8) |
| `/forms/quote` | POST-only form handler (may live under `/api/v1/public/forms/quote`) |
| `/forms/pos-demo` | POST-only form handler |
| `/forms/contact` | POST-only form handler |
| `/forms/callback` | POST-only callback request |
| `/unsubscribe/{token}` | Signed unsubscribe confirmation |
| `/preview/{page}/{token}` | Signed CMS preview, noindex |

## Authenticated app routes (unchanged)

- Existing 52 SPA routes under `/login`, `/register`, `/forgot-password`, `/verify`, `/onboarding`,
  `/app`, `/profile`, `/settings`, `/notifications`, `/support`, `/invitations/accept`,
  `/business/*`, `/pos/*`, `/platform/*`, `/workforce/*`.
- Login/Register links on the public site point to the app host; after authentication users land on
  `/app` as today.
- Auth routes are always `noindex` and excluded from the sitemap.

## Marketing admin routes (new, inside the existing SPA)

Add a **BRATHAMATU — Marketing** sidebar section, visible only with marketing permissions:

| Route | Screen |
| --- | --- |
| `/platform/marketing` | Website overview and health |
| `/platform/marketing/pages` | CMS pages and landing pages |
| `/platform/marketing/pages/{id}` | Page editor, sections, preview, versions |
| `/platform/marketing/navigation` | Menus and menu items |
| `/platform/marketing/media` | Media library |
| `/platform/marketing/seo` | SEO manager, metadata, redirects, diagnostics |
| `/platform/marketing/structured-data` | Templates, preview, validation history |
| `/platform/marketing/blog` | Posts, categories, tags, editorial calendar |
| `/platform/marketing/leads` | Lead pipeline and assignment |
| `/platform/marketing/leads/{id}` | Lead detail, activity, consent, attribution |
| `/platform/marketing/integrations/google` | Google connection and assets |
| `/platform/marketing/integrations/meta` | Meta connection and assets |
| `/platform/marketing/campaigns` | Campaign list, approvals and budgets |
| `/platform/marketing/campaigns/{id}` | Campaign detail, creative, UTM, results |
| `/platform/marketing/analytics` | Traffic, conversions, funnel, search performance |
| `/platform/marketing/automation` | Workflows, executions and kill switch |
| `/platform/marketing/reviews` | Reviews and reputation |
| `/platform/marketing/permissions` | Marketing roles, approvals and audit |

## Navigation model

- One `primary` menu (header), one `footer` menu and optional `utility` menu, all editable in the CMS.
- Menu items may point to a CMS page (stable `page_id`) or an absolute/relative URL; links are resolved
  at render time so slug changes keep working.
- Location and industry links must be generated from approved records; no free-text mass location
  links.
- The public header exposes Login/Register and the primary CTA (Request a quote); the footer repeats
  contact details from global settings.

## Redirect and indexing rules

- Draft, review, approved, scheduled, archived and private pages are never routable publicly and never
  appear in the sitemap.
- Publishing a slug change creates a 301; deleting a page creates a 410 or a reviewed redirect.
- Unknown public URLs render a branded 404 with `noindex`, not the SPA.
- The app host/prefix emits `X-Robots-Tag: noindex`; the API is never crawled.
- `noindex` is allowed per page and forced for previews, tag archives, search results and thin
  paginated lists.

## Phase ownership

- Phase 1: shell, header/footer, navigation, design system, 404, robots/sitemap scaffolding.
- Phase 2: home, about, stock-taking hub, POS hub, how it works, industries hub, pricing, contact,
  FAQs and legal pages.
- Phase 3: the ten stock-taking landing pages and ten POS landing pages.
- Phase 6: metadata, canonical, redirects, Open Graph, breadcrumbs and indexing controls.
- Phase 8: resource index, categories, tags, articles and downloads.
