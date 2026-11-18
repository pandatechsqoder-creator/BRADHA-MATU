# Phase 12 — Consent-aware analytics and reporting report

Status: **engineering complete** (2026-11-17). Consent-gated event collection, server-side conversion
recording, lead funnel, campaign performance, landing-page attribution, honest search reporting and the
`/platform/marketing/analytics` workspace are live. Nothing is sent to any third-party analytics
provider by this phase: events are stored in the BRATHAMATU database and reported from it.

## Objective

Give the marketing team trustworthy reporting without surveillance-style tracking: nothing is ingested
until a visitor grants analytics consent, raw contact details never enter analytics tables, form
submissions are recorded server-side so conversions reconcile with the lead pipeline, and reports show
an honest "no data" state instead of estimates when a provider source is not connected.

## Delivered

### Schema

`2026_11_17_130000_create_analytics_tables.php` (guarded with `hasTable`) adds:

- `analytics_events` — event name, occurred time, session id, optional campaign (`marketing_campaigns`,
  null on delete), path, referrer, UTM JSON, device, consent snapshot, payload JSON and a keyed IP hash;
  indexed by event/time, session, campaign and path;
- `conversion_events` — event name, optional lead (`marketing_leads`, null on delete), optional campaign,
  value/currency, occurred time, consent snapshot and metadata;
- `tracking_preferences` — one row per session with necessary/analytics/marketing choices and the
  consent policy version, updated on every decision.

### Consent model

The public layout renders a consent banner (`data-consent-banner`, `role="dialog"`) with **Accept
analytics** and **Necessary only**. The choice is stored in `localStorage` under `brathamatu.tracking`
with policy version `2026-11`; the banner hides and a **Cookie preferences** control in the footer allows
re-opening it. Marketing UTMs persist under `brathamatu.utm` so attribution survives navigation.

- **Necessary only**: the decision itself is recorded, but no analytics event is ever sent — no page
  view and no interaction tracking, now or later. The server also refuses events for unconsented
  sessions with
  `CONSENT_REQUIRED` (422), so a tampered client cannot bypass the banner.
- **Accept analytics**: the decision is posted to `/public/analytics/consent`, a `page_view` is sent and
  interactions are tracked — `cta_click` (links carrying `data-analytics-cta`), `phone_click` (`tel:`),
  `whatsapp_click` (wa.me / whatsapp.com) and no others.
- **Withdrawal**: recording a withdrawn preference stops ingestion immediately; the API test proves the
  first batch is accepted (202), the consent is then withdrawn, and the next batch is rejected (422) with
  no further rows stored.

### Event ingestion

`AnalyticsService::ingest` accepts only allowlisted names — `page_view`, `cta_click`, `phone_click`,
`whatsapp_click`, `form_start`, `download_start` — at most 25 per request, throttled at 120 requests per
minute per IP (`public-events`). Every event is attributed to a campaign by exact UTM campaign match
(UTM, name or slug; drafts and cancelled campaigns excluded). Client timestamps are clamped (±5 minutes
future, one day past) so replays cannot skew reports. Payloads are sanitised recursively: depth ≤ 3,
strings capped at 500 characters, and any key matching
`/email|phone|name|address|contact|message|subject/i` is dropped. IP addresses are stored only as a
keyed SHA-256 hash.

### Conversions

Form submissions record conversions server-side from `LeadService::capture`, so no browser consent
state can block or fabricate them: quote, POS demo, contact, callback and partnership submissions map to
`quote_request`, `pos_demo_request`, `contact_submission`, `callback_request` and
`partnership_request` with the lead, UTM campaign and landing path. The funnel report reconciles lead
rows with conversion rows (`reconciled` plus a note), and the overview reports lead-matched conversions
separately from raw events.

### Reports and dashboard

`GET /platform/marketing/analytics/{overview,funnel,campaigns,landing-pages,search,events}` and
`GET /platform/marketing/campaigns/{campaign}/performance`, all under `analytics.view` (performance also
requires `campaigns.view`); `days` is clamped 1–365 with a 30-day default.

- **Overview** — consented sessions, page views, conversions, lead-matched conversions, conversion rate,
  consent decisions/grant rate/policy version, a ≤30-point daily series and top events. The response
  states `unconsented_excluded: true`.
- **Funnel** — every lead pipeline stage (new → assigned → contacted → qualified → quote sent → follow up
  → won → lost → archived), conversion events, converted leads and the reconciliation note.
- **Campaigns** — up to 50 campaigns with analytics sessions/conversions, recorded results and
  cost-per-lead (recorded spend ÷ recorded leads when present, otherwise analytics conversions).
- **Landing pages** — top 25 paths by page views and sessions, with conversions counted from conversion
  metadata path.
- **Search** — Google Search Console totals when synced; otherwise `available: false` with
  `NO_SEARCH_DATA` and an explicit "never estimates or invents search metrics" notice.
- **Recent events** — the latest 50 consented events without payloads.
- **Campaign performance** — summary, events, sessions, page views, conversions, recorded results, a
  daily series and recent conversions flagged by lead match.

`/platform/marketing/analytics` renders the same data: period selector (7/30/90 days), session /
conversion / lead-matched / consent stat cards, a daily traffic bar chart, the lead funnel, a campaign
table with spend and cost per lead, landing pages, the search card (data or honest unavailable state),
recent events and the "Consent required before ingestion / No raw PII stored / Server-side form
conversions" badges.

### Permissions

New permission `analytics.view`:

| Role | analytics.view |
| --- | --- |
| platform-owner, content-manager, marketing-manager, seo-manager | yes |
| read-only-executive, lead-agent, marketing-integration-admin | yes |
| content-editor, content-publisher | no |
| tenant roles | excluded (platform surface) |

`AppShell` shows **Analytics** under Website only with `analytics.view`, and the marketing overview
links to it.

### Seed and OpenAPI

`DemoDataSeeder::seedAnalyticsDemo` (idempotent) creates an active "Demo Supermarket Launch" campaign,
consented sessions, page views across the home, stock-taking, supermarkets and POS pages, and reconciled
demo leads/conversions so the dashboard is populated after seeding. The event and reporting endpoints are
documented in `docs/api/openapi.yaml`, keeping the exact-contract test green.

## Verification

- Analytics API tests: `PublicAnalyticsApiTest` (6) and `MarketingAnalyticsApiTest` (7) — 13 passed,
  74 assertions.
- Full backend suite: **272 passed (2904 assertions)** including `OpenApiContractTest`.
- Frontend: `npx tsc --noEmit` clean; Vitest **18 passed**; `npm run build` passed (392 modules,
  11.41 s).
- Playwright `e2e/analytics.spec.ts` — **5 passed (2.2 m)**:
  1. declined analytics never sends events and the choice can be reopened (9.6 s);
  2. accepted analytics stores consented traffic with campaign attribution (17.6 s);
  3. a public form submission becomes a reconciled server-side conversion (20.4 s);
  4. the analytics workspace shows funnel, campaign, landing-page and honest search states (25.8 s);
  5. editors and tenants cannot open the analytics workspace (35.8 s).

## Evidence

`docs/evidence/phase-12-analytics/screens/`:

| File | Shows |
| --- | --- |
| `01-consent-banner.png` | Public home with the analytics consent banner before any decision |
| `02-analytics-dashboard.png` | Analytics workspace: stat cards, daily chart, funnel, campaign table, landing pages, honest search state |
| `03-editor-permission-denied.png` | Editor account denied the analytics workspace (no nav item, permission notice) |
| `04-tenant-denied.png` | Tenant account blocked by the platform-administration gate |

## Open items

- Search performance stays `NO_SEARCH_DATA` until the owner connects Google Search Console (owner
  credentials, Phase 9 prerequisite); GA4 data remains provider-owned and is not imported.
- The consent banner copy and the `2026-11` policy version should be confirmed against the final
  privacy/cookie policy text during owner sign-off; retention for analytics rows should be confirmed in
  Phase 14.
- No external analytics vendor, pixel or tag manager is loaded by this implementation.

## Next

Phase 13 — marketing automation: workflow triggers, conditions, delayed steps, consent checks,
approved notifications, execution logs and a global kill switch.
