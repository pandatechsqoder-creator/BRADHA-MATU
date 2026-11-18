# Proposed API contract changes

Status: proposal for Phase 1 approval (2026-11-16)
Base: `/api/v1`. Existing envelope, request ID, pagination, validation and error conventions are
reused unchanged.

## 1. Namespace decision

The master plan proposes `/api/v1/admin/*`. The repository already uses `/api/v1/platform/*` for
every super-admin surface, protected by `auth:sanctum` + `platform` + `permission:*`. Introducing a
second admin namespace would duplicate middleware and confuse the OpenAPI contract.

**Recommendation:** keep the plan's `/api/v1/public/*` exactly as written and place admin marketing
routes under `/api/v1/platform/marketing/*`. The React admin still lives at `/platform/marketing/*`;
only the API prefix differs from the plan.

All new endpoints must be added to `docs/api/openapi.yaml` and to the existing contract test in the
same change.

## 2. Public contract

Public routes are unauthenticated, rate-limited, cacheable where safe and **never expose drafts,
internal IDs, PII or tenant data**.

### 2.1 Content reads

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/public/navigation/{key}` | `primary`, `footer`, `utility`; resolved URLs |
| GET | `/public/pages/{slug}` | Published page with sections and SEO metadata |
| GET | `/public/services` | Published stock-taking service pages |
| GET | `/public/services/{slug}` | Service landing page |
| GET | `/public/industries` | Published industry pages |
| GET | `/public/industries/{slug}` | Industry page |
| GET | `/public/locations` | Genuine service areas only |
| GET | `/public/locations/{slug}` | Location page |
| GET | `/public/articles` | Paginated published articles (`page`, `per_page<=24`, `category`, `tag`, `q`) |
| GET | `/public/articles/{slug}` | Published article with rendered body, SEO, Article JSON-LD and related articles |
| GET | `/public/article-categories` | Categories with published counts |
| GET | `/public/faqs` | Visible FAQs, optionally `page` |
| GET | `/public/testimonials` | Approved testimonials only |
| GET | `/public/pricing` | Published pricing or the quote-request state |
| GET | `/public/settings` | Public brand, contact and social settings |
| GET | `/public/consent-policy` | Current consent policy version and text |

Response: standard `{data, meta}`. Content payloads include `seo` (title, description, canonical,
robots, Open Graph) and `structured_data` (array of JSON-LD objects) so the renderer does not
recompute policy client-side.

Caching: `Cache-Control: public, s-maxage=300, stale-while-revalidate=60` plus `ETag`; purged by tag
on publish. Public reads must not use the operational API rate limiter.

### 2.2 Form submissions

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/public/forms/quote` | Stock-taking quotation request |
| POST | `/public/forms/pos-demo` | BRATHAMATU POS demo request |
| POST | `/public/forms/contact` | General contact |
| POST | `/public/forms/callback` | Callback request |
| POST | `/public/forms/partnership` | Partnership enquiry |
| POST | `/public/events` | Consent-aware analytics/conversion events (batched) |
| POST | `/public/web-vitals` | Core Web Vitals beacon (LCP/CLS/INP/TTFB, `202`, 60/min per IP) |

Request fields mirror `leads` (§6 of the data model) plus:

- `consent_marketing` and `consent_transactional` booleans, `policy_version`;
- hidden honeypot field and form render timestamp;
- optional `Idempotency-Key` header (UUID) to make retries safe;
- UTM fields, `landing_page`, `referrer` captured server-side where absent.

Controls:

- `throttle:public-forms` (per IP + hashed email/phone), plus a WAF/Turnstile adapter behind a config
  flag (`MARKETING_CAPTCHA_DRIVER=none|turnstile`).
- Server-side validation; reject submissions faster than a human threshold or with honeypot content.
- Store consent record atomically with the lead; never send marketing email without consent.
- Response `201 {data:{reference, message}}`; validation errors use the standard `VALIDATION_FAILED`
  envelope. Duplicate idempotency keys return the original reference.

### 2.3 Non-JSON public routes (served by Laravel web)

`GET /sitemap.xml`, `GET /robots.txt`, `GET /rss.xml`, `GET /unsubscribe/{token}`,
`GET /preview/{page}/{token}`. These are not part of the JSON API and are documented in the route map.

## 3. Admin marketing contract

All routes: `auth:sanctum`, `platform`, explicit `permission:*`, platform audit logging, request ID.
Mutations accept an `If-Match`/`updated_at` guard where concurrent editing is possible.

### 3.1 Website overview

`GET /platform/marketing/overview` — page counts by status, scheduled items, SEO issue counts, lead
counts, campaign status, integration health, recent audit events.

### 3.2 CMS and navigation

| Method | Path |
| --- | --- |
| GET/POST | `/platform/marketing/pages` |
| GET/PATCH/DELETE | `/platform/marketing/pages/{page}` |
| POST | `/platform/marketing/pages/{page}/submit-review` |
| POST | `/platform/marketing/pages/{page}/approve` |
| POST | `/platform/marketing/pages/{page}/schedule` |
| POST | `/platform/marketing/pages/{page}/publish` |
| POST | `/platform/marketing/pages/{page}/unpublish` |
| GET | `/platform/marketing/pages/{page}/versions` |
| POST | `/platform/marketing/pages/{page}/versions/{version}/restore` |
| GET | `/platform/marketing/pages/{page}/preview` (signed URL) |
| PUT | `/platform/marketing/pages/{page}/sections` (ordered replace) |
| GET/POST/PATCH/DELETE | `/platform/marketing/menus` and `/menus/{menu}/items` |
| GET/POST/PATCH/DELETE | `/platform/marketing/banners` and `/ctas` |
| GET/POST/PATCH/DELETE | `/platform/marketing/faqs` and `/testimonials` |

Workflow endpoint rules: submit requires `cms.edit`; approve/reject requires `cms.review`; publish and
unpublish require `cms.publish`; the same user may not approve their own page when the policy
`marketing.separation_of_duties` is on (default on).

### 3.3 Media

| Method | Path |
| --- | --- |
| GET/POST | `/platform/marketing/media/folders` |
| PATCH/DELETE | `/platform/marketing/media/folders/{folder}` |
| GET | `/platform/marketing/media/assets` |
| POST | `/platform/marketing/media/assets` (multipart) |
| GET/PATCH/DELETE | `/platform/marketing/media/assets/{asset}` |
| POST | `/platform/marketing/media/assets/{asset}/replace` |
| GET | `/platform/marketing/media/assets/{asset}/usages` |
| GET | `/platform/marketing/media/assets/{asset}/versions` |

Uploads: server-side MIME sniffing, image re-encode, metadata stripping, size/dimension limits,
private-by-default S3 with signed URLs.

### 3.4 SEO and structured data

Implemented in Phase 6 (see `docs/api/openapi.yaml`):

| Method | Path |
| --- | --- |
| GET | `/platform/marketing/seo/diagnostics` (`cms.view`) |
| GET | `/platform/marketing/seo/vitals?days=1–90&path=` (`cms.view`) |
| GET | `/platform/marketing/seo/redirects` (`cms.view`) |
| POST | `/platform/marketing/seo/redirects` (`cms.publish`, audited) |
| PATCH/DELETE | `/platform/marketing/seo/redirects/{redirect}` (`cms.publish`, audited) |

Implemented in Phase 7 (per-page structured data):

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/platform/marketing/pages/{page}/structured-data` | `cms.view` |
| PUT | `/platform/marketing/pages/{page}/structured-data` | `cms.edit` |
| POST | `/platform/marketing/pages/{page}/structured-data/validate` | `cms.edit` |
| GET | `/platform/marketing/pages/{page}/structured-data/runs` | `cms.view` |

Planned for later phases:

| Method | Path |
| --- | --- |
| GET/PUT | `/platform/marketing/seo/metadata/{type}/{id}` |
| POST | `/platform/marketing/seo/sitemap/generate` |
| GET | `/platform/marketing/seo/sitemap/status` |

### 3.5 Blog and resources (implemented Phase 8, `blog.manage`)

| Method | Path |
| --- | --- |
| GET/POST | `/platform/marketing/posts` |
| GET/PATCH/DELETE | `/platform/marketing/posts/{post}` |
| POST | `/platform/marketing/posts/{post}/{action}` where action is `submit-review`, `approve`, `reject`, `schedule`, `publish`, `unpublish` or `archive` |
| GET/POST | `/platform/marketing/post-categories`; PATCH `/platform/marketing/post-categories/{category}` |
| GET/POST | `/platform/marketing/post-tags`; PATCH `/platform/marketing/post-tags/{tag}` |
| GET/POST | `/platform/marketing/resource-downloads`; PATCH `/platform/marketing/resource-downloads/{resourceDownload}` |
| GET/POST | `/platform/marketing/editorial-items`; PATCH `/platform/marketing/editorial-items/{editorialItem}` |

Publishing validates Article markup from the saved post and returns `422 VALIDATION_FAILED` when it
cannot be emitted. The public Blade routes are `/resources`, `/resources/{slug}`, category and tag
archives, `/resources/downloads/{slug}` (GET page, POST consent-gated download) and `/rss.xml`; tag
archives and filtered listings are noindex.

### 3.6 Leads and CRM

| Method | Path |
| --- | --- |
| GET | `/platform/marketing/leads` (filters: type, status, assignee, source, campaign, date) |
| GET/PATCH | `/platform/marketing/leads/{lead}` |
| POST | `/platform/marketing/leads/{lead}/assign` |
| POST | `/platform/marketing/leads/{lead}/status` |
| GET/POST | `/platform/marketing/leads/{lead}/activities` |
| GET | `/platform/marketing/leads/export` (CSV; `leads.export`, audited, rate-limited) |
| GET | `/platform/marketing/leads/{lead}/consents` |
| POST | `/platform/marketing/leads/{lead}/consents` |

Rules: no endpoint creates a stock-taking job; exports write `lead_exports` and an audit entry;
PII is masked in list responses unless the caller has `leads.export`.

### 3.7 Google and Meta integrations

Google (Phase 9, `integrations.google.manage`):

| Method | Path |
| --- | --- |
| GET | `/platform/marketing/integrations/google` (status, scopes, capabilities, counts, runs, logs) |
| GET | `/platform/marketing/integrations/google/connect` (returns the authorization URL; PKCE state server-side) |
| GET | `/platform/marketing/integrations/google/callback` (public OAuth callback; single-use state + PKCE) |
| DELETE | `/platform/marketing/integrations/google` (revokes token and clears synced data) |
| POST | `/platform/marketing/integrations/google/sync` (`search_console`, `ga4`, `business`, `gtm` or `all`; 202 queued) |

Meta (Phase 10, `integrations.meta.manage`; social publishing uses `social.publish` / `social.approve`):

| Method | Path |
| --- | --- |
| GET | `/platform/marketing/integrations/meta` (status, scopes, capabilities, counts, assets, runs, logs) |
| GET | `/platform/marketing/integrations/meta/connect` (returns the Facebook Login URL; state server-side) |
| GET | `/platform/marketing/integrations/meta/callback` (public OAuth callback; single-use state) |
| DELETE | `/platform/marketing/integrations/meta` (revokes permissions and clears Meta assets; posts are kept) |
| POST | `/platform/marketing/integrations/meta/sync` (`assets`, `insights`, `ads` or `all`; 202 queued) |
| GET/POST | `/platform/marketing/social-posts` (list with status counts + composer options; create draft) |
| GET/PATCH/DELETE | `/platform/marketing/social-posts/{post}` |
| POST | `/platform/marketing/social-posts/{post}/submit-review` |
| POST | `/platform/marketing/social-posts/{post}/approve` / `reject` (`social.approve`; author cannot self-approve) |
| POST | `/platform/marketing/social-posts/{post}/schedule` / `publish` (approval required; scheduler publishes due posts) |

Meta ad data is read-only reporting; no endpoint creates or changes ad spend. `/webhooks/meta` is not
part of Phase 10 and remains planned for a later phase.

OAuth: `state` bound to the initiating user, single-use and expiring; PKCE where supported (Google;
Meta's Facebook Login flow uses state only); tokens encrypted with the application key and never
returned by any endpoint; scopes recorded; reconnect required on expiry. Google Business Profile
reviews are not exposed by the current API access and are reported as unsupported rather than stored
or estimated.

### 3.8 Campaigns (Phase 11), consent-aware analytics (Phase 12) and automation (Phase 13)

Implemented campaign endpoints:

| Method | Path / permission |
| --- | --- |
| GET/POST | `/platform/marketing/campaigns` (`campaigns.view` / `campaigns.manage`) |
| GET/PATCH | `/platform/marketing/campaigns/{campaign}` |
| POST | `…/{campaign}/submit-review` |
| POST | `…/{campaign}/approve` and `/reject` (`campaigns.approve`; owner and creator cannot decide) |
| POST | `…/{campaign}/activate` (`campaigns.manage` + `campaigns.spend`; approved decision required) |
| POST | `…/{campaign}/pause`, `/resume`, `/complete`, `/cancel` |
| POST / DELETE | `…/{campaign}/assets` and `…/{campaign}/assets/{asset}` (draft/rejected only) |
| POST | `…/{campaign}/results` (active, paused or completed only) |
| GET | `…/{campaign}/performance?days=` (`campaigns.view` + `analytics.view`) |

Campaign rules: activation requires an approved approval row and `campaigns.spend`; edits after approval
reset the campaign to draft so any budget, audience or creative change is re-approved; every transition
writes a numbered revision and an audit entry.

Implemented analytics endpoints (`analytics.view`; `days` clamped 1–365, default 30):

| Method | Path |
| --- | --- |
| POST | `/public/analytics/consent` (throttled `public-events`, stores the session preference) |
| POST | `/public/analytics/events` (max 25 events per request; unconsented sessions receive `CONSENT_REQUIRED` 422) |
| GET | `/platform/marketing/analytics/overview` |
| GET | `/platform/marketing/analytics/funnel` |
| GET | `/platform/marketing/analytics/campaigns` |
| GET | `/platform/marketing/analytics/landing-pages` |
| GET | `/platform/marketing/analytics/search` |
| GET | `/platform/marketing/analytics/events` |

Analytics rules: unconsented sessions are excluded from ingestion and reports; payload keys matching
email/phone/name/address/contact/message/subject are dropped server-side and IP addresses are stored only
as a keyed SHA-256 hash; form submissions record server-side conversion events that reconcile with the
lead funnel; search reporting returns `available: false` with `NO_SEARCH_DATA` instead of estimated
metrics when no Search Console data exists.

Implemented automation endpoints (Phase 13):

| Method | Path / permission |
| --- | --- |
| GET/POST | `/platform/marketing/workflows` (`automation.view` / `automation.manage`) |
| GET/PATCH | `/platform/marketing/workflows/{workflow}` (`automation.view` / `automation.manage`) |
| POST | `…/{workflow}/activate` and `/pause` (`automation.manage`) |
| GET | `…/{workflow}/executions?limit=` (`automation.view`, max 200) |
| POST | `/platform/marketing/automation/kill-switch` (`{enabled: bool}`) |
| POST | `/platform/marketing/automation/run-due` (processes due wait/retry steps) |

Automation rules: workflows listen to `lead.created`, `lead.assigned` or `lead.status_changed` with
lead-type/source/UTM conditions; steps run in order (`notify_internal`, `notify_external`, `add_activity`,
`wait`); each trigger is dispatched once per `event_key` so duplicate submissions and retries never run
twice; edits are refused while a workflow is active (`AUTOMATION_LOCKED`) and activation requires at
least one step (`AUTOMATION_STEPS_REQUIRED`); failed steps retry 3 times with 1/5/15-minute backoff;
`notify_external` records `CONSENT_MISSING` when the lead has not granted that channel and
`PROVIDER_NOT_APPROVED` (with a rendered preview) because no outbound provider is approved; the kill
switch records blocked runs without executing steps and does not replay them on resume.

Planned (Phase 14):

| Method | Path |
| --- | --- |
| GET | `/platform/marketing/reviews` and `PATCH /reviews/{review}` |
| GET | `/platform/marketing/audit-logs` |

## 4. Cross-cutting rules

- **Auth:** public routes unauthenticated; all admin routes require platform role + permission.
- **Errors:** unchanged envelope; add codes `FORM_RATE_LIMITED`, `SPAM_REJECTED`, `OAUTH_STATE_INVALID`,
  `TOKEN_EXPIRED`, `CAMPAIGN_NOT_APPROVED`, `CONSENT_REQUIRED`, `PREVIEW_TOKEN_EXPIRED`,
  `AUTOMATION_LOCKED`, `AUTOMATION_STEPS_REQUIRED`. Campaign workflow
  codes (`CAMPAIGN_APPROVAL_REQUIRED`, `CAMPAIGN_APPROVAL_CONFLICT`, `CAMPAIGN_NOT_IN_REVIEW`,
  `CAMPAIGN_LOCKED`, `CAMPAIGN_STATUS_INVALID`, `CAMPAIGN_BUDGET_REQUIRED`, `CAMPAIGN_DATES_REQUIRED`,
  `CAMPAIGN_LANDING_PAGE_NOT_FOUND`) are documented in OpenAPI.
- **Pagination:** existing meta fields; public article lists cap `per_page` at 24.
- **Idempotency:** form submissions and webhooks; unique external IDs for provider records.
- **Audit:** every marketing mutation writes `audit_logs` (`AuditLogger`) with actor, before/after and
  request ID; lead exports and token connects are always audited.
- **OpenAPI:** new paths and schemas land with the implementation; the contract test must stay exact.
- **Rate limits:** public forms and events separate from the operational API limiter; auth remains
  `throttle:login`.
- **Caching:** public content cached with tags and purged on publish; admin responses are `no-store`.
- **No secrets in frontend:** OAuth secrets, provider tokens and webhook secrets live only in env or
  the encrypted database columns.

## 5. Migration impact

- No changes to existing `/api/v1` operations.
- New middleware aliases: `marketing.permission` can reuse `permission`; add `public.throttle` and
  `signed.preview` (Laravel signed URLs) only if needed.
- Extend `RbacSeeder` with the marketing permissions/roles and retire the unused `content.manage`
  grant from non-super-admin roles only after the new permissions exist.
- Extend the OpenAPI contract test to include the new public and platform paths; no route may be
  excluded.
