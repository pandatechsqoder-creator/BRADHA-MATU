# Phase 7 — Structured data report

Status: **engineering complete** (2026-11-17). Article reporting depends on Phase 8 blog posts and
LocalBusiness depends on owner-verified location records, so both are flagged as blocking issues when
requested rather than emitted with invented data.

## Objective

Emit JSON-LD that matches the rendered page, give the marketing team a preview and validation step,
and prevent publishing a page whose requested markup is invalid. No invented ratings, reviews,
prices, offers, addresses or opening hours.

## Delivered

### Graph builder

`StructuredDataService` builds a single `@context: https://schema.org` graph from the resolved public
page (live snapshot, working snapshot for validation, or signed preview):

- `Organization` — name, canonical home URL, tagline, email/phone/address from settings, `sameAs`
  social profiles;
- `WebSite` — site name, description, language, publisher reference;
- `WebPage` — canonical URL (null-safe in previews), visible page title, meta description,
  `dateModified` from the last version;
- `BreadcrumbList` — starts at Home and ends with the visible page title, resolved ancestors
  included;
- `FAQPage` — only when visible FAQ or FAQ-library items exist; questions and answers copied verbatim;
- `Service` — stock-taking landing pages with a description and a service/process section; optional
  `serviceType` override;
- `SoftwareApplication` — BRATHAMATU POS pages with a visible feature section; optional
  `applicationCategory` override (default `BusinessApplication`).

`Article`/`BlogPosting` is not emitted until Phase 8 supplies post records. `LocalBusiness` is not
emitted until a verified location record exists. `Product` is not offered because there is no public
product catalogue; publishing invented prices or offers is explicitly forbidden.

### Validation and publishing gate

`POST /platform/marketing/pages/{page}/structured-data/validate` rebuilds the page from its working
snapshot and reports:

- required fields per emitted node (name, url, description, serviceType, mainEntity, …);
- WebPage name/description/URL must match the visible title, meta description and canonical;
- breadcrumb must start at Home and end with the visible page title;
- FAQ entries must match visible FAQs exactly, with no duplicates;
- Service/SoftwareApplication names must match the page title;
- requested-but-missing Article, LocalBusiness, Service, SoftwareApplication or FAQ markup;
- forbidden `aggregateRating`, `review`, `offers` or `price` keys.

Each run is stored in `structured_data_validation_runs` and the latest 20 are returned. Publishing
calls the same validation and returns `422 VALIDATION_FAILED` with
`details.structured_data.0` when the working snapshot is invalid, so a page cannot go live with
broken markup.

### Public rendering

`PublicPageController`, the preview controller and the Blade layout inject one `<script
type="application/ld+json">` into the head, JSON-encoded with hex flags to prevent tag injection.
Previews stay `noindex, nofollow` and `no-store`.

### Admin UI

The marketing page editor gains a “Structured data (JSON-LD)” card:

- live validation status and emitted node types,
- checkboxes for requestable markup with Phase 8/location notes,
- service type and application category overrides,
- issue list with type/field messages,
- collapsible emitted JSON-LD,
- recent validation runs with timestamps,
- `Validate now` and `Save markup settings` for `cms.edit`; the settings are audited
  (`marketing.structured_data.updated`, `marketing.structured_data.validated`).

Saving page details or sections refreshes the panel. Workflow failures now surface the first
validation detail (for example the structured-data blocking reason) instead of the generic envelope
message.

## API and permissions

| Endpoint | Method | Permission |
| --- | --- | --- |
| `/api/v1/platform/marketing/pages/{page}/structured-data` | GET | `cms.view` |
| `/api/v1/platform/marketing/pages/{page}/structured-data` | PUT | `cms.edit` |
| `/api/v1/platform/marketing/pages/{page}/structured-data/validate` | POST | `cms.edit` |
| `/api/v1/platform/marketing/pages/{page}/structured-data/runs` | GET | `cms.view` |

All four operations are documented in `docs/api/openapi.yaml`; the OpenAPI contract test stays exact.
Tenant roles remain excluded.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=StructuredDataApiTest` | 6 passed, 62 assertions |
| Full backend suite | 211 passed, 2451 assertions |
| OpenAPI contract test | Included in the full suite, pass |
| Frontend `tsc --noEmit` | clean |
| Vitest | 18 passed |
| Playwright `e2e/structured-data.spec.ts` | 2 passed (1.8 min + 3 s) |

Backend tests cover graph correctness (including no ratings/offers/prices), FAQ-to-markup matching,
validation-run history, requested Article markup blocking publish with `422`, signed-preview JSON-LD
with `noindex`, and permission denial for tenant users.

The browser journey creates a landing page with hero, service card and FAQ, confirms valid markup,
requests Article markup, sees the blocking issue, submits for review, has the publisher attempt
publish and receive the blocking message, clears the request, validates, publishes, and checks the
live page’s JSON-LD against the visible title, description and FAQ. A second test verifies the seeded
home and stock-taking pages.

### Evidence

`docs/evidence/phase-7-structured-data/screens/`

| File | Contents |
| --- | --- |
| `01-valid-markup.png` | Valid graph: Organization, WebSite, WebPage, BreadcrumbList, FAQPage, Service |
| `02-invalid-article-markup.png` | Requested Article markup produces a blocking issue and an invalid run |
| `03-publish-blocked.png` | Publisher sees “Structured data must be valid before publishing…” and the page stays approved |
| `04-validation-run-valid.png` | Valid run recorded with the earlier invalid run in history |
| `05-public-page-markup.png` | Live page rendering the same title, service and FAQ captured in JSON-LD |

## Open items

- Phase 8 adds Article/BlogPosting emission from real posts and removes that blocking issue
  automatically.
- A verified location record is required before LocalBusiness can be emitted; owner confirmation is
  pending with the broader contact/location decisions.
- Product markup stays unavailable until a public product catalogue with real prices exists; the
  master plan’s “no invented prices” rule takes precedence.
- External Rich Results Test / Schema Markup Validator runs should be repeated on the production
  domain after launch.

## Next

Phase 8 adds the blog and resources surface: article index/detail, categories, tags, related
articles, editorial calendar, downloads and RSS, reusing the CMS workflow and this validation gate.
