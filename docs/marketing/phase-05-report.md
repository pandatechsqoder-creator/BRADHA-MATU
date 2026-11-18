# Phase 5 report — lead capture and CRM

Status: **engineering complete and verified** (2026-11-16); owner copy/legal/contact sign-off pending
Plan: [Phase 5 in backlog.md](backlog.md#phase-5--lead-capture-and-crm-l)
Previous: [phase-04-report.md](phase-04-report.md)

## Objective

Turn the public website into a measurable acquisition channel: working quotation, POS-demo, contact,
callback and partnership forms; server-side validation, spam controls and idempotent submissions;
consent captured in an append-only ledger; UTM/referrer attribution stored with every lead; and a
platform CRM to list, filter, assign, work and export leads.

Rules enforced: a lead never creates a stocktake job; the consent ledger is authoritative; exports
need `leads.export`, are rate-limited and audited; duplicate submissions return the original
reference instead of creating a second lead.

## Delivered

### Schema and consent ledger

Migration `2026_11_16_140000_create_marketing_lead_tables` creates:

- `marketing_leads` — reference (`LD-yymmdd-XXXXX`), unique `submission_token`, type, status, business,
  contact, email, phone, industry, town/county, branches, catalogue size, service requested,
  frequency, message, source, UTM fields, landing page, referrer, consent flags/IP/timestamp,
  metadata, assignee/owner, activity/conversion timestamps, soft deletes and filter indexes.
- `lead_activities` — timeline entries (`note`, `call`, `email`, `sms`, `whatsapp`, `status_change`,
  `assignment`, `consent`) with author and metadata.
- `lead_assignments` — assignment history with assignee, assigner, assigned/unassigned timestamps and
  reason.
- `lead_exports` — requester, filters, row count and SHA-256 checksum for every CSV export.
- `marketing_consents` — append-only ledger for subject (`lead`), channel (`marketing`, `email`,
  `sms`, `whatsapp`, `analytics`), status (`granted`/`withdrawn`), source, policy version
  (`2026-11-16`), IP, user agent and occurrence time.

### Public forms

One capture pipeline (`LeadService::capture`) serves both entry points:

- JSON API: `POST /api/v1/public/forms/{form}` — `quote`, `pos-demo`, `contact`, `callback`,
  `partnership`; returns `201 {reference, created, message}`.
- No-JS web fallback: `POST /forms/{form}` — validates, redirects back with errors or a
  `lead_success` flash containing the reference.
- Seeded `form` CMS section renders on `/request-quote` (quote), `/book-demo` (POS demo) and
  `/contact` (contact, callback, partnership). The form posts with CSRF, an encrypted `form_token`
  (2 s–2 h human timing), landing page, UTM query parameters, a hidden `website` honeypot and explicit
  transactional/marketing consent checkboxes linking to the privacy notice.
- Validation: email or phone required; business name required for quote, POS demo and partnership;
  message required for contact and partnership; phone required for callback; transactional consent
  accepted.
- Spam: honeypot submissions return a generic success without persisting; `throttle:public-forms`
  allows 12/min per IP and 4/min per email|phone|ip hash.
- Idempotency: `Idempotency-Key` header or client `submission_token` is hashed; otherwise a hash of
  type, email, phone, message and date. Replays return the original reference with `created:false`,
  including after a unique-constraint race.
- Attribution: source, UTM source/medium/campaign/term/content, landing page and referrer are stored;
  capturing writes the initial timeline note, consent rows and an audit entry
  (`marketing.lead.captured`).

### Lead pipeline

APIs under `/api/v1/platform/marketing` (all envelope, validation, permission and audit consistent):

| Endpoint | Permission | Purpose |
| --- | --- | --- |
| `GET /leads` | `leads.view` | Search, status/type/source/assignee/campaign/date filters, pagination, status/type counts |
| `GET /leads/{lead}` | `leads.view` | Full record with activities, assignments and consents |
| `POST /leads/{lead}/status` / `PATCH /leads/{lead}` | `leads.manage` | Pipeline status change (`won` sets `converted_at`) and message edit |
| `POST /leads/{lead}/assign` | `leads.manage` | Assignment history, status `new → assigned`, notification to the assignee |
| `GET/POST /leads/{lead}/activities` | `leads.view` / `leads.manage` | Timeline and manual note/call/email/SMS/WhatsApp entries |
| `GET/POST /leads/{lead}/consents` | `leads.view` / `leads.manage` | Consent history and grant/withdraw decisions |
| `GET /leads/assignees` | `leads.manage` | Platform users eligible for assignment |
| `GET /leads/export` | `leads.export` | CSV (max 5,000 rows) with checksum, `lead_exports` row, audit entry and 6/min rate limit |

Callers without `leads.export` see masked contact data in list responses
(`p****@example.test`, `***678`). Tenant roles are excluded from `leads.*` entirely.

Permissions: `leads.view`, `leads.manage`, `leads.export` are seeded with a new `lead-agent` role;
content-manager and marketing-manager get all three, content-publisher gets view + manage, and
content-editor/seo-manager get view. Super admins keep the platform bypass and receive every
permission in the current-user payload.

### Admin UI (TanStack)

| Route | Permission | Screen |
| --- | --- | --- |
| `/platform/marketing/leads` | `leads.view` | Stats (all/new/open/won), search and status/type/date filters, attributed rows, CSV export (only with `leads.export`) |
| `/platform/marketing/leads/$leadId` | `leads.view` | Enquiry details, attribution, status/assignment/activity controls (`leads.manage`), activity history, assignment history, consent ledger with grant/withdraw |

The Website sidebar section links to Leads for users with `leads.view`. CSV export downloads through
the authenticated API client and applies the same filters as the visible list — search, status, type,
source, assignee, campaign and date range (a regression found during browser verification: export
previously ignored search/source/assignee/campaign; fixed by sharing `LeadService::applyFilters` and
covered by a new test).

Every new operation is recorded in [docs/api/openapi.yaml](../api/openapi.yaml); the contract test
requires an exact operation match and passes.

## Verification

| Check | Result |
| --- | --- |
| Backend suite | **200 passed** (2244 assertions) |
| Lead capture, pipeline API and public page tests | pass, including idempotency, honeypot, rate limit, PII masking, assignment notifications, consent withdrawal, export auditing/rate limit and export filter parity |
| OpenAPI contract test | passes (exact operation match) |
| TypeScript (`npx tsc --noEmit`) | clean |
| Vitest | **18 passed** |
| Playwright lead journey (`npx playwright test e2e/leads.spec.ts`) | **2 passed** (2.0 min) |
| Pint (changed PHP files) | formatted (Pint's Windows summary renderer crashes after formatting; files were formatted) |

Browser journey (serial):

1. Visitor submits the quote form on `/request-quote` with UTM parameters and both consent boxes; the
   no-JS redirect shows the reference, and the lead, attribution, consents and initial activity exist.
2. Super admin finds the lead by reference in `/platform/marketing/leads`, sees the attributed row and
   opens the detail screen.
3. Sets the status to contacted, assigns the lead with a reason, adds an activity note and records an
   SMS consent withdrawal; the API detail confirms status, assignee, activity and ledger rows.
4. Exports CSV: the download contains only the filtered lead and its masked-free contact/attribution.
5. Content editor sees masked email (`p****@phase5.test`), no export button and the export-permission
   hint; a tenant owner is blocked with the platform-only message.

Commands:

```bash
cd backend && php artisan migrate --force
cd backend && php artisan test
cd frontend && npx tsc --noEmit && npm test
cd frontend && npx playwright test e2e/leads.spec.ts
```

The Playwright config starts or reuses the backend on `http://127.0.0.1:8000` and Vite on
`http://127.0.0.1:5173`; it requires MySQL and Redis.

Evidence in [`docs/evidence/phase-5-leads-crm/screens/`](../evidence/phase-5-leads-crm/screens/):

| File | Shows |
| --- | --- |
| `01-public-quote-form.png` | Seeded quote form with consent checkboxes and attribution hidden fields |
| `02-public-quote-success.png` | No-JS redirect confirmation with the lead reference |
| `03-leads-list.png` | Pipeline overview with stats and filters |
| `04-leads-filtered.png` | Reference search with the attributed quotation row |
| `05-lead-detail.png` | Enquiry details, attribution, timeline and consent ledger |
| `06-lead-worked.png` | Contacted status, assignment, note and SMS withdrawal |
| `07-leads-export.csv` | Filtered export of the single lead |
| `08-leads-exported.png` | Export screen after download |
| `09-editor-masked-pii.png` | Editor view with masked email and no export control |
| `10-tenant-denied.png` | Tenant user blocked from the platform pipeline |

## Open items

- Owner sign-off on the form copy, consent wording, privacy notice and contact details (the forms
  read contact values from settings and never invent them).
- Email/SMS delivery for lead notifications is limited to in-app notifications in this phase; outbound
  messaging depends on the Phase 9/10 provider credentials.
- CRM pagination defaults to 25 leads per page; exports cap at 5,000 rows by design.

## Next

Phase 6 — technical SEO tooling and diagnostics.
