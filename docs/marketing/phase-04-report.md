# Phase 4 report — CMS and media library

Status: **engineering complete and verified** (2026-11-16); owner content sign-off pending
Plan: [Phase 4 in backlog.md](backlog.md#phase-4--cms-and-media-library-l)
Architecture: [ADR 0002](../adr/0002-public-website-rendering.md) — Blade public site, TanStack app/admin
Previous: [phase-03-report.md](phase-03-report.md)

## Objective

Give the BRATHAMATU team an admin surface for the public website: a page editor with section
management and preview, the draft → review → approved → scheduled → published workflow with version
history and restore, navigation/banner/CTA/FAQ/testimonial management, and a media library with
folders, alt text, captions, usage tracking and in-place replacement.

## Delivered

### Publishing workflow and versioning

`MarketingPageService` owns every transition and enforces the rules in one place:

- Statuses: `draft`, `review`, `approved`, `scheduled`, `published`, `archived`. Editing a published
  page returns it to `draft` while the published snapshot and URL stay live.
- Permission split is enforced by route middleware: `cms.edit` for create/edit/sections/submit-review/
  restore/delete, `cms.review` for approve/reject, `cms.publish` for publish/schedule/unpublish, and
  `cms.view` for reads and preview.
- Separation of duties: with `marketing.separation_of_duties` enabled (default), a user cannot approve
  a page they submitted; the attempt returns `403 FORBIDDEN`.
- Every transition writes an audit entry (`marketing.page.created/updated/sections_replaced/
  submitted_review/approved/rejected/scheduled/published/unpublished/deleted/version_restored`).
- Version history stores a snapshot of slug, title, SEO and sections on each save/transition.
  `restoreVersion` replays a snapshot into the working copy as a new draft version; published state is
  untouched until the restored draft is approved and published again.
- `CmsPage::effectiveSlug()` serves `draft_slug ?: slug`, so slug edits on drafts do not break the
  live URL.
- Scheduled publishing: `marketing:publish-scheduled` runs every minute
  ([routes/console.php](../../backend/routes/console.php)) and publishes due `scheduled` pages through
  the same service path, producing versions and audit entries.
- Preview: `MarketingPageService::previewUrl()` issues a 30-minute temporary signed URL
  (`marketing.preview`, noindex) for the latest version or a specific version UUID.

### Admin APIs

All under `/api/v1/platform/marketing/*` with the existing envelope, validation and permission
middleware:

- Overview counts (pages by status, live/scheduled, media, navigation, banners/CTAs/FAQs/testimonials,
  SoD flag).
- Pages: list/filter (search, status, type), create, show, update, replace sections, submit-review,
  approve, reject, schedule, publish, unpublish, versions, restore, preview, delete.
- Menus and two-level menu items: create/update/delete menu, create/update/delete items, each item
  pointing to a page or URL, target and visibility, integer `parent_id`.
- Banners, CTAs, FAQs and testimonials CRUD; testimonials are created unapproved and approved through
  the review permission.
- Media: folders CRUD, asset upload (checksum dedupe), list with `meta.pagination`, detail, update
  metadata, delete, replace (same URL, version+1), usages and versions.

Every new operation is recorded in [docs/api/openapi.yaml](../api/openapi.yaml); the existing contract
test requires an exact match and passes.

### Media library

- Assets live on a ULID-keyed delivery route `/media/{uuid}`; uploads store checksum, dimensions,
  MIME type, size, folder, alt text, caption and description.
- Usage tracking scans page section JSON for 26-character asset IDs and resolves them to page titles
  and other CMS records; the detail pane shows "Used in N place(s)" with the referencing pages.
- Replacement uploads a new file while keeping the asset ID and delivery URL, increments `version`,
  and keeps prior versions listed.

### Admin UI (TanStack)

Routes in [frontend/src/app/router.tsx](../../frontend/src/app/router.tsx), all inside `AppShell` +
`RequirePlatform`:

| Route | Permission | Screen |
| --- | --- | --- |
| `/platform/marketing` | `cms.view` | Overview: stats, pipeline by status, content inventory |
| `/platform/marketing/pages` | `cms.view` | Filterable page list, create draft |
| `/platform/marketing/pages/$pageId` | `cms.view` | Section editor (add/reorder/hide/remove), page details, SEO, workflow panel, version history, preview |
| `/platform/marketing/media` | `media.manage` | Folder sidebar, upload, asset grid, metadata, usage, replace, pagination |
| `/platform/marketing/navigation` | `cms.view` | Menus, two-level item tree, item form |
| `/platform/marketing/content` | `cms.view` | Banners, CTAs, FAQs, testimonials tabs |

Workflow buttons appear only with the matching permission, and the API refuses the call even if the
UI is bypassed. A "Website" section in the app sidebar links to these screens for platform users with
`cms.view`, and includes a "View public website" link. The staff sign-in page also links to the public
site. Platform super admins receive every permission in the current-user payload (matching the
backend's super-admin bypass), so the Website section, the platform-overview "Public website" card and
the CMS APIs are all available to them.

The Vite dev server proxies public-site routes to Laravel, so `http://127.0.0.1:5173/` renders the
Blade public website while `/login`, `/app`, `/platform`, `/business`, `/pos` and `/workforce` remain
the React staff app. This mirrors the production topology on one origin; set
`VITE_PUBLIC_SITE_URL` only if the public site is deployed on a separate host.

## Verification

| Check | Result |
| --- | --- |
| Backend suite | **182 passed** (2121 assertions) |
| Marketing admin API tests | **13 passed** |
| OpenAPI contract test | passes (exact operation match) |
| TypeScript (`npx tsc --noEmit`) | clean |
| Production build (`npm run build`) | succeeds |
| Vitest | **18 passed** |
| Playwright marketing journey (`npx playwright test e2e/marketing-admin.spec.ts`) | **3 passed** (6.2 min) |
| Pint (changed PHP files) | clean |

Browser journey (serial, one seeded editor and one publisher):

1. Editor signs in, opens the overview, creates a draft, adds a rich-text section, submits for review.
2. Publisher approves and publishes; the live URL returns 200 with the new copy.
3. Editor edits sections: the page returns to draft while the previously published snapshot remains
   live; publisher re-approves and republishes.
4. Unpublish returns 404; version history lists four versions; restoring version 3 loads the earlier
   copy back into the editor.
5. Media: upload a PNG with alt text, reference it from a page, confirm "Used in 1 place(s)" and the
   page usage entry, replace the file (version 2) and confirm the same URL still serves `image/png`.
6. Permission denial: editor cannot approve (button absent and direct API call returns 403);
   publisher schedules a page for `2027-01-15T10:00` and the scheduled state is shown; a support agent
   sees "You do not have permission to view this page."; a tenant owner sees "Platform administration
   is only available to BRATHAMATU platform roles."
7. Staff login: the sign-in page links to the public site; loading the Vite origin root returns the
   Blade homepage. A super admin sees the Website section, the platform-overview "Public website" card
   and opens the marketing workspace.

Commands:

```bash
cd backend && php artisan migrate --force
cd backend && php artisan db:seed --class=DemoDataSeeder --force
cd backend && php artisan test
cd frontend && npx tsc --noEmit && npm run build && npm test
cd frontend && npx playwright test e2e/marketing-admin.spec.ts
```

The Playwright config starts or reuses the backend on `http://127.0.0.1:8000` and Vite on
`http://127.0.0.1:5173`; it requires MySQL and Redis.

Evidence in [`docs/evidence/phase-4-cms-media/screens/`](../evidence/phase-4-cms-media/screens/):

| Screenshot | Shows |
| --- | --- |
| `01-marketing-overview.png` | Admin overview after login |
| `02-editor-draft.png` | Draft page in the section editor |
| `03-published.png` | Published page and live status |
| `04-version-history.png` | Version list with restore controls |
| `05-media-replaced.png` | Media detail after replacement with usage |
| `06-editor-denied.png` | Editor without approve permission |
| `07-scheduled.png` | Scheduled publishing state |
| `08-support-denied.png` | Support agent denied access |
| `09-tenant-denied.png` | Tenant owner denied the platform area |
| `10-super-admin-website.png` | Super admin in the marketing workspace from the platform overview |

## Decisions and boundaries

- The journey test creates pages with the `phase4-` slug prefix. Its scheduled page for
  `2027-01-15T10:00` remains in the local database as scheduling evidence; the scheduler is not
  running in development, and the page disappears from the public site when unpublished or deleted.
- Separation of duties defaults to on and is read from `marketing.separation_of_duties`; changing it
  is a settings decision, not a code change.
- Media delivery is served by Laravel with public cache headers; moving to object storage/CDN remains a
  deployment decision and does not change the API contract.
- Preview URLs are signed for 30 minutes and noindex; they are for reviewers only, not for sharing.
- Blog, SEO tooling, redirects and lead capture remain later phases (5–7).
- Owner content, legal and contact sign-off from Phases 2–3 is still pending.

## Exit gate

| Requirement | Status |
| --- | --- |
| Publish, schedule, unpublish and restore tested in the browser | met |
| Media replacement updates usage | met — same URL, version 2, usage preserved |
| Permission denial tested for each role | met — editor cannot approve; support agent and tenant owner denied |
| Approval of own page blocked when SoD on | met — covered by backend tests; default on |
| Every transition audited | met — audit entries asserted in tests |
| Preview uses signed, expiring, noindex URLs | met — 30-minute signed route |

## Next phase

Phase 5 adds lead capture and CRM: quote, POS demo, contact, callback and partnership forms with
server-side validation, honeypot/throttle, consent capture, UTM attribution and a permissioned lead
pipeline. It starts after Phase 4 acceptance and the outstanding owner copy, legal and contact
decisions.
