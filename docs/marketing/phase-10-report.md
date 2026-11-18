# Phase 10 — Meta integrations and social publishing report

Status: **engineering complete** (2026-11-17). Live Meta connection and publishing require an owner
Meta app with Facebook Login, app review for the publishing/insights permissions and confirmed
Business/Page/Instagram/ad assets. The workspace shows an explicit setup-required state until then,
never invents Meta metrics and never spends ad budget.

## Objective

Connect Meta Business, Facebook Pages, Instagram business accounts and ad accounts through Facebook
Login; import Pages, assets, insights and read-only ad reporting; and publish Facebook/Instagram posts
through an approval-gated workflow where the author can never approve their own post. Tokens are
encrypted at rest and never returned by any endpoint.

## Delivered

### Schema

`2026_11_17_110000_create_meta_integration_tables.php` (guarded with `hasTable`) adds:

- `meta_business_accounts`, `meta_pages` (Page token `encrypted` at the model), `meta_instagram_accounts`;
- `meta_ad_accounts` and `meta_campaign_records` (budget, spend, impressions, clicks, results);
- `social_posts` (Page or Instagram target, optional JPEG media asset, status, schedule/publish
  timestamps, external Meta ID, error, insights) and `social_post_approvals` (requested/approved/
  rejected decisions with notes and actor).

`oauth_connections` from Phase 9 is reused with `provider = meta`; credentials stay `encrypted:array`.

### OAuth and Graph access

`MetaOAuthService` implements Facebook Login with a single-use, user-bound state (cache keyed by
SHA-256, 10-minute expiry). It exchanges the code for a short-lived token, immediately upgrades it to
a long-lived token via `fb_exchange_token`, reads the profile (`me?fields=id,name,email`) and revokes
permissions best-effort on disconnect. Meta does not support PKCE for this flow, so the state alone
protects the callback.

`MetaGraphClient` maps provider responses to explicit errors:

| Condition | Error |
| --- | --- |
| HTTP 401 / codes 102, 190, 463, 467 | `META_REAUTH_REQUIRED` (409) |
| HTTP 403 / codes 10, 200, 299, 803 | `META_PERMISSION_DENIED` (403) |
| Network failure | `META_API_UNREACHABLE` (502) |
| Other non-2xx | `META_API_ERROR` (422) |

### Syncs

`MetaIntegrationService` queues `SyncMarketingMeta` runs (3 tries, backoff 30/120 s):

| Type | Imported data |
| --- | --- |
| `assets` | Businesses (tolerated permission denial), Pages with Page tokens, Instagram business accounts, ad accounts |
| `insights` | 28-day Page impressions/engagements and published-post insights (per-post failures logged) |
| `ads` | Campaigns and 28-day campaign insights; budget cents converted to decimal; zero-spend campaigns kept |

Expired tokens mark the connection `expired` and require reconnection. Runs record counts and errors;
connection, sync and publish events are audited (`marketing.integration.meta.*`, `marketing.social_post.*`)
and written to the integration log. Disconnect revokes permissions, clears Meta assets and keeps post
records for audit.

### Social publishing workflow

`SocialPostService` enforces:

- draft/rejected posts are editable; other states are locked (422 `SOCIAL_POST_LOCKED`);
- a Page is required before submitting for review; Instagram posts require a JPEG media asset;
- submit → review, approve/reject require `social.approve` and reject the author approving their own
  post (`SOCIAL_APPROVAL_CONFLICT`);
- schedule requires approval and a future timestamp; the scheduler (`marketing:publish-scheduled`)
  publishes due posts alongside CMS pages and articles;
- publish requires approval and a connected Meta account. Facebook posts go to `/{page-id}/feed`
  (message + optional link); Instagram uses a media container (`/media`) followed by `media_publish`.
  Failures set the post to `failed` with the provider message, produce audit/log entries and can be
  retried by an approver.

No campaign or spend actions are exposed: ad data is read-only reporting, and the capability list
states "Ad spend changes — read-only".

### Permissions

New permissions `social.publish` and `social.approve`:

| Role | social.publish | social.approve |
| --- | --- | --- |
| content-manager, marketing-manager, marketing-integration-admin | yes | yes |
| content-editor, content-publisher, seo-manager | yes | no |

Tenant roles remain excluded from the platform marketing surface.

### Admin UI

- `/platform/marketing/integrations/meta` (`integrations.meta.manage`): setup-required panel, connection
  status/scopes/expiry, capabilities with a read-only ad-spend badge, synced counts, connected Pages
  with Instagram handles, per-type syncs, run history, logs, connect/reconnect and confirm-guarded
  disconnect with callback banners. A link points to social publishing.
- `/platform/marketing/social` (`social.publish`): composer with Page/channel selection, caption with a
  2,200-character counter, optional link and JPEG media picker; status-filtered post table with
  submit/approve/reject/schedule/publish/delete actions, approval history, Meta IDs and failure
  messages. Approve/reject buttons appear only with `social.approve`; the page explains the honest
  "Meta is not connected" state while drafts and approvals still work.

The Marketing overview shortcuts and Website navigation entries are permission-gated.

## API and permissions

| Endpoint | Method | Permission |
| --- | --- | --- |
| `/api/v1/platform/marketing/integrations/meta` | GET / DELETE | `integrations.meta.manage` |
| `/api/v1/platform/marketing/integrations/meta/connect` | GET | `integrations.meta.manage` |
| `/api/v1/platform/marketing/integrations/meta/callback` | GET | public, throttled |
| `/api/v1/platform/marketing/integrations/meta/sync` | POST | `integrations.meta.manage` |
| `/api/v1/platform/marketing/social-posts` | GET / POST | `social.publish` |
| `/api/v1/platform/marketing/social-posts/{post}` | GET / PATCH / DELETE | `social.publish` |
| `/api/v1/platform/marketing/social-posts/{post}/submit-review` | POST | `social.publish` |
| `/api/v1/platform/marketing/social-posts/{post}/schedule` | POST | `social.publish` |
| `/api/v1/platform/marketing/social-posts/{post}/publish` | POST | `social.publish` |
| `/api/v1/platform/marketing/social-posts/{post}/approve` | POST | `social.approve` |
| `/api/v1/platform/marketing/social-posts/{post}/reject` | POST | `social.approve` |

OpenAPI documents every operation; the contract test enforces parity.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=MetaIntegrationApiTest` | 10 passed |
| `php artisan test --filter=MetaSocialPostApiTest` | 10 passed |
| Full backend suite | 250 passed (2747 assertions) |
| OpenAPI contract test | Included in the full suite, pass |
| Frontend `tsc --noEmit` | clean |
| Vitest | 18 passed |
| Playwright `e2e/meta-integrations.spec.ts` | 4 passed (2.1 min) |

Backend tests use faked Graph responses and cover: unconfigured status and connect refusal, Facebook
Login URL/state, encrypted long-lived token, single-use state, denied callback, 409 sync without a
connection, 202 queueing of all three types, asset/insights/ads imports (encrypted Page token, cents →
decimal budget), 401 → expired connection and failed run, disconnect cleanup that keeps posts, tenant
denial, the full draft → review → approve → schedule → Facebook publish lifecycle, author
self-approval refusal, future-time validation, rejection reason, editor denial, Instagram JPEG
requirement plus container/media publish calls, provider failure → `failed`, scheduler publishing, and
tenant isolation.

The browser journey verifies the super-admin navigation, the honest not-configured Meta workspace with
zero counts and disabled sync, API `configured=false`/`META_NOT_CONNECTED`/`META_NOT_CONFIGURED`, draft
creation without Meta (submit disabled until a Page exists), editor nav restrictions and permission
gate, and the tenant platform gate.

### Evidence

`docs/evidence/phase-10-meta-integrations/screens/`

| File | Contents |
| --- | --- |
| `01-meta-integrations-unconfigured.png` | Owner setup-required state, honest zero counts, read-only ad spend, disabled sync, empty Pages/runs/logs |
| `02-social-draft-workflow.png` | Social composer, "Meta is not connected" notice, saved draft row with disabled submit |
| `03-editor-meta-permission-denied.png` | Content editor has no Meta nav entry and receives the permission gate; social publishing remains available |
| `04-tenant-denied.png` | Tenant account receives the platform-administration gate |

## Open items

- Owner must create the Meta app, add Facebook Login, set `META_APP_ID`, `META_APP_SECRET`,
  `META_REDIRECT_URI` and complete app review for `pages_show_list`, `pages_read_engagement`,
  `pages_manage_posts`, `read_insights`, `instagram_basic`, `instagram_content_publish`, `ads_read`
  and `business_management`.
- Owner/ops must confirm Page roles, the Instagram business account linkage and ad-account access.
- Live connection, sync and publishing must be repeated with owner assets; current verification uses
  faked Graph responses only.
- Instagram feed publishing requires a publicly reachable JPEG image URL; the production media disk
  must serve HTTPS URLs before live publishing.
- Reels, stories, comment moderation and paid promotion are out of scope for this phase.

## Next

Phase 11 adds campaign management: objectives, channels, audiences, creative, landing pages, UTM
builder, budgets, approval-before-activation and results.
