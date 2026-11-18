# Phase 11 — Campaign management report

Status: **engineering complete** (2026-11-17). Campaign planning, approval workflow, activation,
creative, tracking links and results are live. Provider-side ad spend is not changed by this
platform: activating a BRATHAMATU campaign requires an approved campaign plus the `campaigns.spend`
permission, and spend recorded against a campaign is reported input, not a provider action.

## Objective

Give the marketing team campaign objects with objectives, channels, audiences, budgets, landing-page
links, UTM tracking, creative and results, governed by a separation-of-duties approval flow: drafts →
review → approved → active, with pause/resume/complete/cancel, immutable change history and audit. No
campaign bypasses approval, budget/audience/creative changes after approval force re-approval, and the
author can never approve their own campaign.

## Delivered

### Schema

`2026_11_17_120000_create_campaign_tables.php` (guarded with `hasTable`) adds:

- `marketing_campaigns` — ULID public ID, name, objective, channel, status, audience JSON, budget
  amount/currency (default GBP), start/end dates, optional landing page (`cms_pages`, null on delete),
  UTM JSON, owner/approver/creator, approval/activation timestamps and results JSON;
- `campaign_assets` — campaign creative (copy, image, email, social post) with optional media asset,
  content JSON and status;
- `campaign_approvals` — requested-by, decided-by, decision (pending/approved/rejected), notes and
  decision time;
- `campaign_revisions` — numbered snapshots with change summaries, unique per campaign and revision.

### Domain rules

`CampaignService` enforces the workflow:

| Transition | Requirement |
| --- | --- |
| `draft` / `rejected` → edit | only drafts and rejected campaigns are editable; otherwise 422 `CAMPAIGN_LOCKED` |
| → `review` | budget above zero (`CAMPAIGN_BUDGET_REQUIRED`) and a start date (`CAMPAIGN_DATES_REQUIRED`); writes a pending approval row |
| → `approved` / `rejected` | campaign must be in review (`CAMPAIGN_NOT_IN_REVIEW`); owner and creator are refused (`CAMPAIGN_APPROVAL_CONFLICT`) |
| → `active` | an approved decision is required (`CAMPAIGN_APPROVAL_REQUIRED`) and the route additionally requires `campaigns.spend` |
| pause / resume / complete / cancel | explicit status checks (`CAMPAIGN_STATUS_INVALID`); resume returns to active |
| creative add/remove | drafts and rejected campaigns only |
| results | only live campaigns (active, paused, completed) |

Every transition records a revision snapshot and an audit entry (`marketing.campaign.*`), so the change
history can never be rewritten. Editing an approved campaign resets it to draft and clears the approver,
forcing the full review again. Landing pages are validated by ULID lookup (`CAMPAIGN_LANDING_PAGE_NOT_FOUND`).

Tracking: each campaign presents a `utm_url` built from the configured public URL, the landing-page slug
(falling back to the campaign slug) and its `utm_source`, `utm_medium` and `utm_campaign` values so the
team copies one ready-to-use link.

Results entry records reach, impressions, clicks, leads, conversions, spend and notes as reported
figures. No provider metric is fabricated or extrapolated.

### Permissions

New permissions `campaigns.view`, `campaigns.manage`, `campaigns.approve` and `campaigns.spend`:

| Role | view | manage | approve | spend |
| --- | --- | --- | --- | --- |
| content-manager, marketing-manager | yes | yes | yes | yes |
| content-editor, content-publisher, seo-manager | yes | yes | no | no |
| platform-owner | yes | no | yes | no |
| read-only-executive, lead-agent, marketing-integration-admin | yes | no | no | no |

Tenant roles remain excluded from the platform marketing surface. The demo `marketing-manager` account
`marketing@brathamatu.test` was added to `DemoDataSeeder`.

### Admin UI

`/platform/marketing/campaigns` (`campaigns.view`):

- stat cards for draft, awaiting approval, approved and active campaigns, plus status filtering;
- campaign form with objective, channel, landing page (published pages only), budget and currency,
  start/end dates, audience, UTM fields and save-as-draft;
- campaign table with objective, channel, budget, dates, owner, status badge and the copy-ready UTM
  tracking URL;
- status-aware actions: edit, submit for review, approve/reject (approvers only), activate (visible;
  blocked with an explicit spend-permission notice when the account lacks `campaigns.spend`),
  pause/resume, complete;
- detail panel with creative add/remove, approval history (single terminal decision per request),
  revision history, results entry and a results summary.

The Marketing overview shortcut and the Website navigation entry are permission-gated.

## API and permissions

| Endpoint | Method | Permission |
| --- | --- | --- |
| `/api/v1/platform/marketing/campaigns` | GET / POST | `campaigns.view` / `campaigns.manage` |
| `/api/v1/platform/marketing/campaigns/{campaign}` | GET / PATCH | `campaigns.view` / `campaigns.manage` |
| `…/{campaign}/submit-review` | POST | `campaigns.manage` |
| `…/{campaign}/approve` | POST | `campaigns.approve` |
| `…/{campaign}/reject` | POST | `campaigns.approve` |
| `…/{campaign}/activate` | POST | `campaigns.manage` + `campaigns.spend` |
| `…/{campaign}/pause`, `/resume`, `/complete`, `/cancel` | POST | `campaigns.manage` |
| `…/{campaign}/assets` | POST | `campaigns.manage` |
| `…/{campaign}/assets/{asset}` | DELETE | `campaigns.manage` |
| `…/{campaign}/results` | POST | `campaigns.manage` |

OpenAPI documents every operation; the contract test enforces parity.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=CampaignApiTest` | 9 passed (83 assertions) |
| Full backend suite | 259 passed (2830 assertions) |
| OpenAPI contract test | Included in the backend suite, pass |
| Frontend `tsc --noEmit` | clean |
| Frontend `npm run build` (`tsc -b && vite build`) | pass |
| Vitest | 18 passed |
| Playwright `e2e/campaigns.spec.ts` | 4 passed (2.7 min) |

Backend tests cover draft creation with landing page, UTM and decimal budget; submit validation for
budget and dates; the full draft → review → approve → activate → complete workflow with separation of
duties; rejection returning to draft and locking an approved campaign; creative locked after approval;
results limited to live campaigns; list filters and counts; audit entries; `campaigns.spend` denial for
editors; and tenant isolation.

The browser journey verifies: drafting a campaign with budget, audience and UTM tracking plus a
published landing page and a live tracking URL; submission to review with the owner blocked from
approving their own campaign; a marketing manager approving, activating and recording results
(single decision per approval row); and an editor seeing the spend-permission ban plus a tenant account
hitting the platform gate.

### Evidence

`docs/evidence/phase-11-campaigns/screens/`

| File | Contents |
| --- | --- |
| `01-campaign-draft.png` | Campaign form filled with objective, channel, landing page, budget, dates, audience and UTM, with the draft row and tracking URL |
| `02-campaign-review-owner-blocked.png` | Campaign in review and the owner's self-approval refusal dialog |
| `03-campaign-active-results.png` | Approved and active campaign, single approval decision, results recorded and the UTM tracking URL |
| `04-campaign-spend-permission-denied.png` | Content editor sees the spend-permission requirement and cannot activate |
| `05-tenant-denied.png` | Tenant account receives the platform-administration gate |

## Open items

- Owner must confirm who holds `campaigns.approve` and `campaigns.spend` in production and set budget
  thresholds per approver.
- Provider-side budget caps for Meta/Google ads remain configured in those platforms; BRATHAMATU does
  not push budgets to providers in this phase.
- Results are entered/reported figures. Automated attribution from analytics arrives in Phase 12.

## Next

Phase 12 adds consent-aware analytics and reporting: event tracking, conversion events, lead funnel,
campaign performance, landing-page reports, search performance and attribution dashboards.
