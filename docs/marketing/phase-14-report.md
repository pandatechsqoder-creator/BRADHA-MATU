# Phase 14 — Security and operational hardening report

Status: **engineering complete** (2026-11-17). The site now ships hardened response headers, upload
validation against real MIME content, extended data-retention purges, a tested backup/restore drill, a
production configuration health probe, non-indexable private surfaces, and documented WCAG A/AA and
performance results. No critical or high findings remain open in the phase scope.

## Objective

Before launch, prove that the public site and the platform behind it are safe and operable: private
routes stay out of search, uploads cannot smuggle active content, personal data ages out on a schedule,
a database snapshot can be restored and verified, unsafe production configuration fails the health
check, and the key public pages pass an automated accessibility and performance budget.

## Delivered

### Response headers and indexing (`SecurityHeaders`, `PublicIndexingHeaders`)

- `SecurityHeaders` applies `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`,
  `Cross-Origin-Opener-Policy: same-origin` to every response; API responses additionally get
  `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'`; HTTPS
  responses get `Strict-Transport-Security: max-age=31536000; includeSubDomains`.
- `PublicIndexingHeaders` sets `X-Robots-Tag: noindex, nofollow` on `/api/*`, `/app`, `/business`,
  `/pos`, `/platform`, `/workforce`, authentication and account paths, preview URLs, 404s and 410s.
  Public marketing pages keep their normal indexable headers; previews additionally carry noindex.
- `robots.txt` keeps app/auth/API paths disallowed; non-production environments return
  `Disallow: /` for every crawler.
- The SPA shell (`frontend/index.html`) now carries `<meta name="robots" content="noindex, nofollow">`
  as defense in depth. Public content is rendered by Blade and does not depend on JavaScript.

### Upload hardening (`MediaLibraryService`)

- Allowlist by extension and real content: jpeg, png, webp, gif, pdf; 8 MB maximum; images are
  re-encoded through GD, so an SVG or a text file renamed to `.png` is rejected with
  `VALIDATION_FAILED` before any row is written (covered by `HardeningTest`).
- Images above 6000 px on either side or 30 megapixels are refused; dimensions are recorded and
  media is served with `nosniff`.
- Media rows are never hard-deleted by upload paths; replacements create a new immutable version.

### Data retention

`config/privacy.php` gained four windows, all environment-tunable and enforced by the existing daily
`retention:purge` command (02:30, `withoutOverlapping`):

| Data | Table/column | Default window | Env |
| --- | --- | --- | --- |
| Analytics events | `analytics_events.occurred_at` | 400 days | `RETENTION_MARKETING_ANALYTICS_DAYS` |
| Conversion events | `conversion_events.occurred_at` | 730 days | `RETENTION_MARKETING_CONVERSION_DAYS` |
| Tracking preferences | `tracking_preferences.updated_at` | 180 days | `RETENTION_MARKETING_TRACKING_DAYS` |
| Automation runs | `automation_runs.created_at` (+ steps) | 365 days | `RETENTION_AUTOMATION_RUNS_DAYS` |

`--dry-run` reports what would be purged without deleting; every real purge is audit-logged.
Lead, consent-ledger and audit records are intentionally not purged by this window set.

### Backup and restore drill

- `php artisan db:backup` writes a portable gzip JSON snapshot (schema + rows) into
  `storage/backups/backup-<timestamp>.json.gz`.
- `php artisan db:restore <file>` restores into the default database; `--table-prefix=<name>` restores
  into prefixed staging tables for a non-destructive drill, `--verify` compares restored row counts
  against the snapshot, `--drop` removes staged tables afterwards (foreign keys are dropped on staged
  copies because constraint names are database-global in MySQL).
- Drill executed 2026-11-17 against the development database: backup written (205 tables, 282.0 KiB),
  restored 205 tables / 4,774 rows into `drill_*` staging tables, row counts verified, staging tables
  dropped. Numbers are representative of the dev dataset, not a production size claim.

### Production configuration health probe

`HealthService` gained a `configuration` check after `migrations`. In production it fails when
`APP_DEBUG` is true, `APP_URL` is not HTTPS, or `SESSION_SECURE_COOKIE` is off; outside production it
reports `non-production`. `php artisan health:check` therefore blocks an unsafe launch instead of
silently serving debug output or insecure cookies. `backend/.env.production.example` documents the
required values (production env, debug off, HTTPS URLs, session encryption and secure cookies, Redis,
token expiry, retention windows, integration placeholders).

### Accessibility

Automated WCAG 2 A/AA audits run in `frontend/e2e/hardening.spec.ts` with `@axe-core/playwright`
(`wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`) and write per-page JSON to
`docs/evidence/phase-14-hardening/a11y/`.

| Page | Passes | Incomplete | Violations |
| --- | --- | --- | --- |
| Public home (`/`) | 25 | 1 | 0 |
| Quote form (`/request-quote`) | 25 | 1 | 0 |
| Staff login (`/login`) | 15 | 0 | 0 |
| Automation workspace | 21 | 0 | 0 |

Fixes made to reach zero violations:

- Footer consent link colour raised to `#94a3b8` (was 3.75:1 on the dark footer);
- App-wide light-surface text token moved from gray-400 to gray-500 (53 occurrences in 28 files),
  lifting secondary text above 4.5:1;
- Dark sidebar section labels kept at gray-400, which is 6.7:1 on the ink surface.

### Performance budget

The same spec measures the public home and quote form with `PerformanceNavigationTiming` (local dev,
warm): home TTFB 1,224 ms, DOMContentLoaded 1,629 ms, 2 resource requests, 28,809 bytes transferred,
48,613 bytes of HTML. The enforced budgets are TTFB < 1,500 ms, DOMContentLoaded < 3,000 ms,
HTML < 250 KB and total transfer < 1.5 MB; figures are recorded in
`docs/evidence/phase-14-hardening/performance.json`. The production build ships a single 1.2 MB JS
bundle (305 KB gzip); code splitting is listed as a launch follow-up, not a blocker for the public
Blade pages, which load no app JavaScript.

## Verification

| Check | Result |
| --- | --- |
| `HardeningTest` (retention purge, unsafe-config probe, upload rejection) | 3 passed, 25 assertions |
| Full backend suite (`php artisan test`) | 286 passed, 3,048 assertions (152.6 s) |
| `npx tsc --noEmit` | clean |
| `npm run build` | 394 modules, built in 8.28 s |
| `npx vitest run` | 18 passed (6 files) |
| `npx playwright test e2e/hardening.spec.ts` | 4 passed (50.9 s) |

The Playwright run covers: public home and quote form WCAG checks, staff login and automation
workspace WCAG checks, the performance budget on public entry pages, and noindex behaviour
(robots.txt `Disallow: /` in non-production plus the SPA meta tag on private routes).

## Evidence

| Item | Path |
| --- | --- |
| Screens have accessible public home, quote form, staff login, automation workspace | `docs/evidence/phase-14-hardening/screens/01..05-*.png` |
| axe results per page | `docs/evidence/phase-14-hardening/a11y/*.json` |
| Performance measurements | `docs/evidence/phase-14-hardening/performance.json` |

## Open items

- Copy the nightly backup off-host (object storage or a second machine) and set a retention policy;
  the command exists but scheduling/offsite storage is an owner decision.
- Code-split the SPA bundle before heavy staff usage; public marketing pages are unaffected.
- The development database still contains Phase 13 E2E workflows and test leads; prune them before
  production cut-over (demo rows are harmless to functionality).
- Add a report-only CSP for the SPA once the production asset origins are final.

## Exit gate

Documented security review ✅, recovery drill with verified row counts ✅, accessibility results with
zero A/AA violations on the audited pages ✅, performance results inside budget ✅, no critical or high
findings ✅. Phase 15 (production launch and owner sign-off) is next and requires owner-supplied
contact details, legal decisions and hosting access.
