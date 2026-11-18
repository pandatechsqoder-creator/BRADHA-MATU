# Google and Meta integration prerequisites

Status: Phase 0 findings for planning (2026-11-16)
These are external prerequisites. Code cannot substitute for account eligibility, app review or API
allowlisting; lead times must be planned before Phases 9 and 10.

## 1. Shared requirements

- Separate OAuth applications/clients per environment (local, staging, production). No shared secrets.
- Client IDs/secrets in environment variables or a secret manager; never in the frontend, repo or logs.
- Redirect URIs registered exactly for each environment; HTTPS in staging and production.
- Tokens encrypted at rest (`encrypted:array`), scoped to least privilege, refreshable, revocable.
- OAuth `state` bound to the initiating platform user, single-use, expiring; PKCE where the provider
  supports it.
- Webhooks: verify signature/token, reject replays, process idempotently, store raw payload with
  retention, never log secrets.
- Disconnect must revoke tokens where the provider supports it and clear stored credentials.
- Integration health screen: status, account/property, granted scopes, last sync, errors, reconnect,
  disconnect and audit history.
- Test accounts: use provider test users/assets where available; production connections are an owner
  action after approval.
- No promise of features the API does not support; show unavailable states honestly.

## 2. Google

Phase 9 code is delivered and verified with faked Google APIs; the steps below remain owner actions.
Until the OAuth client credentials exist, `/platform/marketing/integrations/google` shows an explicit
setup-required state, refuses to start OAuth (`GOOGLE_NOT_CONFIGURED`) and never displays Google data.

### 2.1 Account and project setup

1. Google Cloud project per environment (or shared project with separate OAuth clients).
2. OAuth consent screen configured; support email, privacy policy and terms URLs published.
3. OAuth client type “Web application”; redirect URIs per environment.
4. Enable the required APIs.
5. Verify domain ownership where Google requires it (Search Console and Business Profile).

### 2.2 APIs and scopes (request only what each phase uses)

| Capability | API | Scope |
| --- | --- | --- |
| Business Profile accounts/locations | Business Profile Account Management / Business Information | `https://www.googleapis.com/auth/business.manage` |
| Business Profile reviews/insights | Business Profile APIs where available | `business.manage` (feature availability varies) |
| Search Console | Search Console API | `https://www.googleapis.com/auth/webmasters.readonly` |
| GA4 reporting | Google Analytics Data API | `https://www.googleapis.com/auth/analytics.readonly` |
| Tag Manager | Tag Manager API | `https://www.googleapis.com/auth/tagmanager.readonly` (edit only if explicitly approved) |
| Identity | OpenID Connect | `openid`, `email`, `profile` |

Notes:

- Business Profile API access is **allowlisted**; request access early. Some profile features and
  review responses are not available to every account or API version. Build the UI around available
  capabilities, with explicit “not supported for this account” states.
- Search Console requires the connected Google account to have property permission. Property
  verification is an owner action.
- GA4 requires a property ID and appropriate access; the Data API is read-only.
- GTM is powerful and risky: read-only by default; editing tracking configuration requires a separate
  permission and audit. Consent must gate non-essential tags.
- OAuth consent-screen verification is required for sensitive/restricted scopes; unverified apps are
  limited to test users. Plan review time before launch.

### 2.3 Quotas and sync

- Respect per-API quotas; schedule incremental syncs (hourly/daily) via queues with backoff.
- Store raw metrics by date and property; never recompute history from current totals.
- Handle token expiry (`invalid_grant`) by marking the connection expired and prompting reconnect.
- Log errors with redaction; surface them on the integration dashboard.

## 3. Meta

Phase 10 code is delivered and verified with faked Meta Graph responses; the steps below remain owner
actions. Until the app credentials exist, `/platform/marketing/integrations/meta` shows an explicit
setup-required state, refuses to start OAuth (`META_NOT_CONFIGURED`) and never displays Meta data.

### 3.1 Account and app setup

1. Meta Business Portfolio (Business Manager) with the pages, Instagram professional account and ad
   accounts that will be connected.
2. Meta developer app per environment; app secret stored server-side.
3. Business verification where required for advanced access.
4. App review for each permission actually used; provide screencasts and a clear use case.
5. Webhook subscription with a verify token and HMAC signature validation.
6. Conversions API and Pixel configuration only when the tracking plan and consent policy are approved.

### 3.2 Permissions to request (phase-gated)

| Capability | Permissions |
| --- | --- |
| List/manage Pages | `pages_show_list`, `pages_read_engagement`, `pages_manage_posts` |
| Instagram professional account | `instagram_basic`, `instagram_content_publish`, `instagram_manage_insights` |
| Ads reporting | `ads_read` |
| Ads management (drafts/activation) | `ads_management` (only when spend approval is live) |
| Business assets | `business_management` |

Notes:

- Publishing and spending require explicit internal approval **and** provider permissions. An editor
  or automation must never be able to spend money.
- Ad account currency, timezone and spend caps must be read and displayed before activation.
- Meta API versions change; pin a version and schedule upgrades.
- App review lead time is outside our control; Phases 10–11 must be deliverable behind a feature flag
  while approval is pending.

## 4. Environment variables (proposed)

```
# Google
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://<host>/api/v1/platform/marketing/integrations/google/callback
GOOGLE_OAUTH_SCOPES=openid,email,profile,...
GOOGLE_BUSINESS_ACCOUNT_ID=          # optional default
GOOGLE_ANALYTICS_PROPERTY_ID=        # optional default

# Meta
META_APP_ID=
META_APP_SECRET=
META_REDIRECT_URI=https://<host>/api/v1/platform/marketing/integrations/meta/callback
META_WEBHOOK_VERIFY_TOKEN=
META_GRAPH_VERSION=
META_PIXEL_ID=                       # optional
META_CONVERSIONS_TOKEN=              # optional, encrypted
META_AD_ACCOUNT_ID=                  # optional default

# Public forms / tracking
MARKETING_CAPTCHA_DRIVER=none        # none|turnstile
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET=
MARKETING_ANALYTICS_RETENTION_DAYS=
```

No provider secret is exposed to the React app or Blade templates.

## 5. Security and privacy controls

- OAuth tokens and page tokens encrypted at rest; access limited to backend services.
- Least-privilege scopes; revoke on disconnect; rotate app secrets on a schedule.
- Webhook signature verification and idempotency (unique provider event IDs).
- Rate limits and backoff for provider calls; circuit breaker to protect the queue.
- Consent state attached to analytics/conversion events; no advertising identifiers without consent.
- Lead and event retention configured; PII not sent to providers without a lawful basis.
- Audit every connect, disconnect, scope change, sync trigger, publish and spend action.
- Kenya Data Protection Act 2019 review with qualified counsel before production launch (consent,
  notices, retention, cross-border transfer).

## 6. Owner actions and lead times

| Action | Owner | When | Risk if late |
| --- | --- | --- | --- |
| Confirm brand, domains, contact details | Owner | Before Phase 1 | Blocks content and OAuth redirect URIs |
| Create Google Cloud project and OAuth client | Owner/ops | Before live Google sync (code delivered) | Blocks Google connection |
| Apply for Business Profile API access | Owner/ops | **Immediately** | Long allowlist lead time |
| Verify Search Console property and GA4 access | Owner/ops | Before live Google sync (code delivered) | No search/analytics data |
| Create Meta app and start app review | Owner/ops | Before live Meta publishing (code delivered) | Blocks publishing and ad reporting |
| Confirm Meta Business, Pages, Instagram and ad accounts | Owner | Before live Meta publishing (code delivered) | Cannot test assets |
| Approve spend limits and approver roles | Owner | Before Phase 11 | No activation, by design |
| Approve consent policy and retention periods | Owner/counsel | Before Phase 5/12 | Non-compliant tracking |
| Choose email/SMS/WhatsApp providers | Owner | Before Phase 13 | Automation limited to in-app notifications |

## 7. Fallbacks while approvals are pending

- Google: show connection status and an explanatory empty state; never fake metrics.
- Meta: the workspace shows the setup-required state and drafts/approvals still work; publishing stays
  blocked until the app, permissions and assets exist. Ad data is read-only; no endpoint changes spend.
- Analytics: server-side conversion events (form submissions) remain available without GA4/Meta.
- Automation: in-app notifications and task reminders work without external providers.
