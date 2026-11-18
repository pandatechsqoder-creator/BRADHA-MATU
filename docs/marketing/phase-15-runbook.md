# Phase 15 — Production launch runbook

Status: readiness tooling and documentation complete; the live cut-over is blocked only by the
owner-supplied inputs listed below. `php artisan marketing:launch-check` is the gate: it exits non-zero
while a blocking item remains.

## Owner inputs required before cut-over

| Input | Where it is used | Current state |
| --- | --- | --- |
| Domain and DNS control | canonical host, sitemap, Search Console | not supplied |
| Hosting with PHP 8.4, MySQL 8, Redis, queue worker, scheduler | application runtime | not supplied |
| TLS certificate | HTTPS, HSTS, secure cookies | not supplied |
| Contact email and/or phone | footer, contact section, quote confirmation | **missing — launch blocker** |
| Legal entity name and governing law | terms page, privacy notice | pending |
| Google Cloud project + OAuth client + Search Console/GA4/GTM access | Phase 9 workspace | pending |
| Meta app + review + Page/Instagram access | Phase 10 workspace | pending |
| Outbound email/SMS/WhatsApp provider approval | automation `notify_external` | not approved (steps stay blocked) |
| Offsite backup target | nightly `db:backup` copy | pending |
| Final asset origins (if any) | SPA CSP | pending |

## Server requirements

- PHP 8.4 with `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `zip`, `redis`.
- MySQL 8 or MariaDB equivalent, `utf8mb4`.
- Redis for cache, sessions and queues (or database drivers with documented trade-offs).
- A queue worker (`php artisan queue:work --tries=3`) supervised by systemd/supervisor.
- The scheduler cron entry: `* * * * * php /path/artisan schedule:run`.

## Deployment sequence

1. Build assets off-host: `cd frontend && npm ci && npm run build`; publish `frontend/dist` to the web
   root configured in the Laravel `public` directory.
2. Run Composer under the application's PHP version so optimized classmaps and platform checks use
   the production runtime. On this cPanel host:
   `/opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/composer install --no-dev --optimize-autoloader`.
   Avoid the unqualified `composer` command, which may use the older system CLI PHP.
3. Copy `backend/.env.production.example` to `.env` and fill every blank:
   `APP_KEY`, database, Redis, mail, contact details, integration IDs, backup target.
4. `php artisan key:generate` (once), `php artisan migrate --force`.
5. Seed only the required reference data: `RbacSeeder` and `MarketingContentSeeder` (never
   `DemoDataSeeder` in production).
6. `php artisan storage:link`; confirm `storage/` and `bootstrap/cache/` are writable.
7. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
8. `php artisan health:check` must report `"status": "ok"`.
9. `php artisan marketing:launch-check` must report `ready_with_warnings` or `ready` — any `fail`
   blocks launch.
10. `php artisan db:backup` and copy the snapshot to the offsite target.

## DNS, TLS and indexing

- Point the apex/`www` records at the host; decide the canonical host once.
- Issue the certificate, force HTTPS at the proxy, set the canonical host redirect only after the
  decision is final (`MARKETING_CANONICAL_HOST`).
- Verify `https://<domain>/robots.txt` returns the production rules (no `Disallow: /`).
- Verify `https://<domain>/sitemap.xml` lists public pages and articles only.
- In Search Console: add the domain property, verify ownership, submit the sitemap, check the URL
  inspection for `/`, `/request-quote` and one landing page.
- Confirm `X-Robots-Tag: noindex, nofollow` on `/login`, `/app`, `/platform`, `/api/*` and signed
  preview URLs.

## GA4 and consent

- Add the GA4 measurement ID and GTM container to settings once the owner supplies them; do not
  hardcode them in templates.
- Confirm analytics requests only run after the visitor accepts analytics consent; the API returns
  `CONSENT_REQUIRED` otherwise.
- Record conversions server-side from form submissions; do not send PII to GA4.

## Smoke test at cut-over

| Step | Expected |
| --- | --- |
| Load `/` at 390, 768 and 1440 px | Home renders without JavaScript; banner and footer visible |
| Submit `/request-quote` | Success confirmation; lead appears in `/platform/marketing/leads` |
| Staff login and marketing overview | Works; public-site link present |
| Create, review and publish a test page | Live at its slug; preview URL is noindex |
| Open `robots.txt`, `sitemap.xml`, `/rss.xml` | Correct production content |
| Check response headers | `nosniff`, frame denial, referrer policy, HSTS |
| Run `e2e/hardening.spec.ts` against the live host | 4 passed; axe JSON shows zero violations |
| Run `php artisan db:backup` + `db:restore --table-prefix=drill_ --verify` | Row counts verified |
| Automation kill switch | Stays paused until a provider is approved |

## Rollback

- Keep the previous release directory and the pre-deploy backup; switch the symlink back and restore
  the snapshot with `db:restore` if a schema change is implicated.
- Re-run `config:clear`/`config:cache` after any `.env` change.
- If indexing rules are wrong, set `MARKETING_INDEXABLE=false` and redeploy config before contacting
  Search Console.

## Monitoring and follow-up

- Poll `/up` (or `health:check`) every minute from an external monitor; alert on failure and queue
  depth growth.
- Alert on failed jobs and on backups older than 48 hours.
- Review Search Console coverage and Core Web Vitals after 7 and 28 days.
- Keep the automation kill switch on until an outbound provider is approved and tested.
- Prune development demo rows (`DemoDataSeeder` data and Phase 13 E2E workflows) before importing
  production content.
