# Phase 9 — Google integrations report

Status: **engineering complete** (2026-11-17). Live Google sync requires owner-provided Google Cloud
OAuth credentials, verified Search Console/GA4 access and Business Profile API allowlist. The workspace
shows an explicit setup-required state until then and never invents Google metrics.

## Objective

Connect Google Search Console, GA4, Business Profile and Tag Manager through OAuth, import real
provider data on demand and on a queue, keep tokens encrypted, surface errors and expiries honestly,
and let an owner disconnect and clear synced data. Business Profile reviews are not exposed by the
current API access, so the product reports them as unsupported instead of estimating them.

## Delivered

### Schema

`2026_11_17_100000_create_marketing_integration_tables.php` adds marketing-specific tables, kept
separate from the tenant `integration_connections` used by the operational platform:

- `oauth_connections` (provider, account label, encrypted credentials, scopes, status, actor,
  last sync/error);
- `google_business_accounts` and `google_business_locations`;
- `google_search_properties` and `google_search_metrics` — the daily unique row uses a SHA-256
  `dimension_hash` so the composite index stays within MySQL’s 3072-byte key limit while long
  query/page dimensions remain storable;
- `ga4_properties`, `ga4_metrics`, `gtm_containers`;
- `marketing_integration_sync_runs` and `marketing_integration_logs`.

Models live under `app/Models/Marketing/`; credentials cast to `encrypted:array` and are never
returned by an endpoint.

### OAuth

`GoogleOAuthService` implements the authorization-code flow with PKCE:

- state is random, stored server-side in the cache keyed by its SHA-256, single-use (`Cache::pull`),
  expiring after 10 minutes and bound to the initiating user;
- code verifier stays server-side; the authorization URL carries the S256 challenge,
  `access_type=offline`, incremental scopes and `prompt=consent`;
- token exchange and refresh use the configured client, with `GOOGLE_NOT_CONFIGURED`,
  `GOOGLE_OAUTH_FAILED` and `GOOGLE_REAUTH_REQUIRED` error codes;
- the public callback consumes state and redirects the app to the integrations page with
  `google=connected` or `google=error&reason=…`;
- disconnect revokes the token best-effort and clears local credentials and synced records.

### Syncs

`GoogleIntegrationService` queues runs (`SyncMarketingGoogle`, 3 tries with backoff) and executes:

| Type | Provider data |
| --- | --- |
| `search_console` | Sites list, then 28-day daily search analytics (clicks, impressions, CTR, position) |
| `ga4` | Account summaries/properties, then 28-day sessions, total users and conversions |
| `business` | Accounts and locations (name, address, phone); logs reviews as unsupported |
| `gtm` | Accounts and containers (read-only) |

A token expiring within a minute is refreshed before the call. `401` marks the connection expired and
requires reconnection; `403` becomes `GOOGLE_PERMISSION_DENIED`; other API errors become
`GOOGLE_API_ERROR`; network failures become `GOOGLE_API_UNREACHABLE`. Each run records status,
timestamps, imported counts and error; each connection action and sync trigger is audited
(`marketing.integration.google.*`).

### Admin UI

`/platform/marketing/integrations/google` (permission `integrations.google.manage`) provides:

- connection status, account, connected-by, last successful sync, token expiry, granted scopes;
- an owner setup-required panel while OAuth credentials are absent, with connect disabled;
- capability list including the explicit “Business Profile reviews — unavailable” state;
- synced counts for Search properties/rows, GA4 properties/rows, Business accounts/locations and GTM
  containers;
- per-type sync buttons and “Sync all”, recent run history with imported counts/errors, and an
  integration log;
- connect/reconnect and confirm-guarded disconnect actions, plus callback success/error banners.

The Marketing overview shortcut and Website navigation entry are permission-gated. OpenAPI documents
all five operations, and the contact form remains unchanged.

## API and permissions

| Endpoint | Method | Permission |
| --- | --- | --- |
| `/api/v1/platform/marketing/integrations/google` | GET | `integrations.google.manage` |
| `/api/v1/platform/marketing/integrations/google` | DELETE | `integrations.google.manage` |
| `/api/v1/platform/marketing/integrations/google/connect` | GET | `integrations.google.manage` |
| `/api/v1/platform/marketing/integrations/google/callback` | GET | public, throttled |
| `/api/v1/platform/marketing/integrations/google/sync` | POST | `integrations.google.manage` |

Tenant roles remain excluded. A new platform role `marketing-integration-admin` carries
`integrations.google.manage` and `integrations.meta.manage` for Phase 10.

## Verification

| Check | Result |
| --- | --- |
| `php artisan test --filter=GoogleIntegrationApiTest` | 12 passed, 88 assertions |
| Full backend suite | 231 passed (2625 assertions) |
| OpenAPI contract test | Included in the full suite, pass |
| Frontend `tsc --noEmit` | clean |
| Vitest | 18 passed |
| Playwright `e2e/google-integrations.spec.ts` | 3 passed (1.8 min) |

Backend tests use faked Google endpoints and cover: unconfigured status and connect refusal, PKCE
parameters, encrypted credentials, single-use/user-bound state, denied callback, 409 sync without a
connection, 202 queueing of all four types, Search Console/GA4/GTM/Business imports, the unsupported
reviews log, refresh-expiry → expired connection, 401 reauthorisation, counts/status, disconnect
cleanup and tenant denial.

The browser journey verifies the super-admin navigation, the setup-required workspace with zero
counts and no sync results, API `configured=false`/`reviews=false`, sync `409 GOOGLE_NOT_CONNECTED`,
connect `422 GOOGLE_NOT_CONFIGURED`, the hidden nav and permission denial for editors, and the platform
denial for tenant accounts.

### Evidence

`docs/evidence/phase-9-google-integrations/screens/`

| File | Contents |
| --- | --- |
| `01-google-integrations-unconfigured.png` | Owner setup-required state, honest zero counts, reviews unavailable, disabled sync, empty runs/logs |
| `02-editor-permission-denied.png` | Content editor has no Google nav entry and receives the permission-denied gate |
| `03-tenant-denied.png` | Tenant account receives the platform-administration gate |

## Open items

- Owner must create the Google Cloud OAuth client and set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`
  and `GOOGLE_REDIRECT_URI`; add the production callback URI to the client.
- Owner/ops must verify Search Console and GA4 access and apply for Business Profile API allowlist.
- Live connection and real sync verification must be repeated with an owner Google account once
  credentials exist; current verification uses faked provider responses.
- Business Profile reviews remain unavailable in the current API access and are intentionally not
  stored or displayed.
- Google push webhooks, Search Console URL inspection and GA4 realtime reporting are not part of this
  phase.

## Next

Phase 10 connects Meta Business, Pages, Instagram and ad accounts through OAuth, with approval-gated
publishing, insights and ad reporting. It needs an owner Meta app, app review and confirmed assets.
