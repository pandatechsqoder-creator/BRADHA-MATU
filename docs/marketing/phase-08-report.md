# Phase 8 — Blog and resources report

Status: **engineering complete** (2026-11-17). Article copy, download wording and consent text are
seeded as drafts for owner review; no testimonials, prices, addresses or response-time promises were
invented.

## Objective

Add a public resource hub built from real editorial content: article index and detail pages, one
primary category, tags, related articles, an editorial calendar, consent-gated resource downloads and
an RSS feed — all reusing the CMS publishing workflow and the Phase 7 structured-data validation gate.

## Delivered

### Schema and workflow

`2026_11_17_090000_create_blog_tables.php` adds:

- `blog_categories` (unique slug, parent, sort order) and `blog_tags`;
- `blog_posts` (slug, title, excerpt, Markdown/plain body, status, author, featured asset, category,
  reading time, SEO/OG fields, robots flags, publish/schedule timestamps, soft deletes);
- `blog_post_tag` and `blog_post_related` pivot tables;
- `resource_downloads` (media asset, consent flag, download counter, publish state);
- `editorial_items` (title, brief, assignee, due date, status, linked post).

`BlogService` owns the workflow: `draft → review → approved → scheduled → published` plus `archived`,
with `submit-review`, `approve`, `reject` (reason required), `schedule`, `publish`, `unpublish` and
`archive`. Separation of duties uses the existing marketing setting, so an author cannot approve their
own article while it is enabled. Every transition writes an audit event (`marketing.post.{action}`).
`marketing:publish-scheduled` now publishes due CMS pages and due articles in one run.

### Publishing gate

Publishing builds Article markup from the saved post (`StructuredDataService::buildArticle`) and
validates it (`validateArticle`): headline, description, datePublished and author must match the
visible content, and no `aggregateRating`, `review`, `offers` or `price` keys are allowed. Failures
return `422 VALIDATION_FAILED` with `details.structured_data.0`. The Article node includes an
`articleSection` from the primary category and `keywords` from the article tags.

### Public surface

| Route | Behaviour |
| --- | --- |
| `/resources` | Article index with CMS intro sections, search, category chips, pagination (9 per page); filtered/search views are noindex |
| `/resources/{slug}` | Article detail: breadcrumbs, category, author, date, reading time, Markdown-rendered body, tags, related articles, CTA band, Article JSON-LD, OG article metadata |
| `/resources/categories/{slug}` | Indexable category archive with published articles |
| `/resources/tags/{slug}` | Tag archive, noindex by default |
| `/resources/downloads/{slug}` | Download page; consent checkbox when required; POST validates consent, counts the download and redirects to the media asset |
| `/rss.xml` | RSS 2.0 feed of the latest 20 published articles |

Sitemap generation now includes published articles (0.6), categories with published posts (0.4) and
published downloads (0.3). Markdown is rendered through `Str::markdown`; plain bodies are escaped with
`nl2br(e(...))`.

### API and permissions

| Endpoint | Methods | Permission |
| --- | --- | --- |
| `/api/v1/platform/marketing/posts` | GET, POST | `blog.manage` |
| `/api/v1/platform/marketing/posts/{post}` | GET, PATCH, DELETE | `blog.manage` |
| `/api/v1/platform/marketing/posts/{post}/{action}` | POST (`submit-review`, `approve`, `reject`, `schedule`, `publish`, `unpublish`, `archive`) | `blog.manage` |
| `/api/v1/platform/marketing/post-categories` | GET, POST; PATCH `/{category}` | `blog.manage` |
| `/api/v1/platform/marketing/post-tags` | GET, POST; PATCH `/{tag}` | `blog.manage` |
| `/api/v1/platform/marketing/resource-downloads` | GET, POST; PATCH `/{resourceDownload}` | `blog.manage` |
| `/api/v1/platform/marketing/editorial-items` | GET, POST; PATCH `/{editorialItem}` | `blog.manage` |
| `/api/v1/public/articles`, `/public/articles/{slug}`, `/public/article-categories` | GET (unauthenticated) | — |

`blog.manage` is a new permission seeded for the content-manager, marketing-manager, content-editor,
content-publisher and seo-manager roles; tenant roles remain excluded. All operations are documented
in `docs/api/openapi.yaml` and the exact OpenAPI contract test passes.

### Admin UI

`/platform/marketing/blog` (navigation: Website → Blog & resources) provides four tabs:

- **Articles** — status counts, article table and an editor with title, slug, excerpt, Markdown body,
  primary category, comma-separated tags, author, SEO fields, noindex, workflow buttons (submit,
  approve/reject, schedule/publish, unpublish, archive, delete) and a link to the live article.
- **Categories & tags** — create forms and lists with published/post counts; creating a tag also
  happens implicitly when saving an article.
- **Downloads** — create a download from a media-library asset with consent and publish toggles;
  list rows show path, counters and state with publish/unpublish actions.
- **Editorial calendar** — create items with due date, brief and status, and update status inline.

`useBlogPosts`, `useBlogTransition`, taxonomy, download and editorial hooks live in
`frontend/src/lib/marketing/blogHooks.ts` and invalidate the `['marketing','blog']` query family.

### Seeded content

`MarketingContentSeeder` (content revision 6) publishes three stock-taking articles
(`preparing-for-a-stock-count`, `cycle-counting-and-annual-stock-takes`,
`reducing-stock-discrepancies`), two categories, tags, related links, a consent-gated
`stock-count-preparation-checklist` download backed by a real TXT file in public storage, and two
planned/review editorial items for December.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=BlogApiTest` | 8 passed, 86 assertions |
| Full backend suite | 219 passed (2537 assertions) |
| OpenAPI contract test | Included in the full suite, pass |
| Frontend `tsc --noEmit` | clean |
| Vitest | 18 passed |
| Playwright `e2e/blog.spec.ts` | 3 passed (2.6 min) |

Backend tests cover the editor→publisher workflow, the public Article graph and rendered article,
listing, RSS and sitemap, separation of duties, scheduled publishing through the console command,
unpublish removing the article from the public site and API, taxonomy slug uniqueness and public
categories, tenant denial on every platform resource, consent-gated download counting and redirect,
and noindex on filtered and tag archives.

The browser journey logs in as the content editor, creates and saves a Markdown article, submits it
for review, switches to the publisher, approves and publishes it, checks the live article (title,
Markdown heading, tags, related articles, Article JSON-LD), the resources index, the noindex tag
archive, the download consent page and redirect, RSS and sitemap contents, the public article API,
then unpublishes/archives the article. Additional tests verify super-admin navigation and tenant
denial.

### Evidence

`docs/evidence/phase-8-blog-resources/screens/`

| File | Contents |
| --- | --- |
| `01-blog-workspace.png` | Editor workspace with seeded articles, status counts and Website navigation |
| `02-article-draft.png` | New article saved as a draft with category, tags and SEO description |
| `03-article-in-review.png` | Article in review with editorial workflow actions |
| `04-article-published.png` | Publisher state after approve and publish, with the public link |
| `05-public-article.png` | Live article with byline, Markdown body, tags and related articles |
| `06-resources-index.png` | Public resources hub listing the new article |
| `07-tag-noindex.png` | Tag archive rendered with `noindex` robots metadata |
| `08-download-consent.png` | Consent-gated download page before confirming |
| `09-article-unpublished.png` | Article archived after evidence capture |
| `10-taxonomy.png` | Categories and tags with counts and creation forms |
| `11-editorial-calendar.png` | Editorial calendar with a new planned item |
| `12-downloads.png` | Downloads tab with the seeded checklist |

## Open items

- Owner sign-off is still required for article copy, the download consent sentence and legal
  wording; the seeded articles are practical guidance, not legal or financial advice.
- The editor does not yet expose featured-image selection, author assignment or editorial-item
  assignee/linking; the schema and public renderer already support featured assets.
- Scheduling is covered by backend tests and the console publish command; the browser journey does
  not wait for a scheduled publish.
- Tag archives stay noindex by default; revisit only if the owner wants tag pages indexed.
- RSS, Rich Results and Search Console checks should be repeated on the production domain after
  launch.

## Next

Phase 9 connects Google Search Console, GA4 and Business Profile behind owner-provided credentials;
LocalBusiness markup remains blocked until verified locations exist.
