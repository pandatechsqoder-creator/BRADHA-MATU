# ADR 0002 — Rendering approach for the public website

- Status: **Accepted** (2026-11-16)
- Date: 2026-11-16
- Phase: 0
- Authority: BRATHAMATU_PUBLIC_WEBSITE_MASTER_PLAN.md §5 and §12

## Context

The public website must deliver meaningful HTML to search engines; a client-only app is explicitly
insufficient. The existing frontend is a client-only React SPA built with Vite and served as static
files. The production topology has no Node.js runtime: Nginx/TLS → PHP-FPM → Laravel, plus static SPA
assets on Nginx/CDN, Redis and queue workers. Adding SSR is therefore a deployment decision, not only a
framework decision.

The public site is content-managed, indexable and mostly cacheable. The authenticated app and the
marketing admin remain the existing React SPA. The two experiences have different performance,
accessibility, SEO and security requirements.

## Options considered

| Option | How it works | Strengths | Weaknesses |
| --- | --- | --- | --- |
| A. Laravel Blade public site (recommended) | Public routes and templates live in Laravel; CMS data is read server-side; admin stays in React | True server-rendered HTML; no new production runtime; native metadata/sitemap/JSON-LD/redirects; one deployment; request-time caching and CDN-friendly | Second UI layer (Blade + React); design tokens must be shared; editors use the React admin |
| B. TanStack Start / React SSR app | Separate React SSR service for public pages, Laravel as API | One React stack; component reuse; SSR | New Node runtime, process manager and build pipeline; SSR/API deployment coupling; framework maturity risk; duplicated auth/SEO plumbing |
| C. Build-time prerender of the SPA | Generate static HTML for known routes at build time | Cheap; static hosting; fast | Publishing, scheduling and CMS edits require a rebuild/deploy; dynamic routes (articles, filters, previews) degrade; SEO correctness depends on the rebuild pipeline |
| D. Inertia + React SSR | Laravel controllers return Inertia pages, rendered through a Node SSR service | React components, Laravel routing, SSR | Still requires Node SSR in production; couples public rendering to Inertia conventions; more moving parts than A for a content site |

## Decision

Adopt **Option A: Laravel Blade for the public website**, with the existing React SPA unchanged for
the authenticated app and the new marketing admin.

Implementation shape:

- `routes/web.php` owns public routes; `backend/resources/views/public/*` holds the layout, partials and
  section components; `backend/resources/css/public.css` builds through the backend's existing Vite
  pipeline at build time (no Node runtime in production).
- CMS services read published records and produce a view model containing page sections, resolved
  navigation, SEO metadata, breadcrumbs and JSON-LD. Templates only render; policy stays in PHP.
- Public responses are cached in Redis/CDN with tags per page and purged on publish, unpublish, slug
  change, menu change and settings change.
- Admin marketing screens remain in the React SPA under `/platform/marketing/*` and use the JSON API.
- `sitemap.xml`, `robots.txt`, redirects, 404/410 handling and `X-Robots-Tag` are server-side concerns,
  not SPA concerns.
- Public forms post to the API (`/api/v1/public/forms/*`) with server-side validation, throttling and
  consent capture. The Blade form is progressively enhanced; it must work without JavaScript.
- Core Web Vitals are collected with a small beacon to `/api/v1/public/vitals`; the public layout ships
  no client framework bundle.

Design consistency: export the existing brand/design tokens (colours, type scale, spacing, radii) into a
shared CSS source used by both the frontend build and the Blade build. Blade components mirror the
SPA's visual system; there is no attempt to share React components.

## Consequences

Positive:

- Essential content is in the initial HTML with no JavaScript dependency.
- No new production runtime, no Node SSR supervision, no SSR/API failure coupling.
- Metadata, canonicals, sitemap, redirects and JSON-LD are generated where the data lives.
- Public traffic can be cached and isolated from operational API limits.
- The operational SPA, POS and tenant features are untouched.

Negative:

- Two view technologies (Blade and React) to maintain.
- Marketing UI work happens twice only where public and admin share a visual component; this is
  mitigated by shared tokens and small, typed section components.
- Interactive public widgets (quote form, media galleries) need small, dependency-light scripts.

## Revisit triggers

Reconsider Option B (TanStack Start) if any of the following becomes true:

- The team mandates a React-only codebase for all surfaces.
- The public site needs heavy client-side personalisation or app-like interactivity.
- A Node runtime is standardised in production for other reasons.

Reconsider Option C only for a small, static launch site without CMS-driven scheduling; it is not
suitable once editors publish independently.

## Approval

Accepted by the owner on 2026-11-16: Laravel serves the public website with Blade; TanStack React
remains the app and marketing-admin frontend. The related owner decisions (brand naming, domain
topology, API namespace) are recorded in `docs/marketing/README.md`.
