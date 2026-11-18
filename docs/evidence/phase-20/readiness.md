# Phase 20 — Security hardening, performance and production readiness evidence

Date: 2026-11-15
Environment: local (`http://127.0.0.1:5173`, API `http://127.0.0.1:8000/api/v1`), MySQL 8 `:3307`, Redis `:6379`.

## Delivered

- Global `SecurityHeaders` middleware: `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`, API CSP
  (`default-src 'none'; frame-ancestors 'none'; base-uri 'none'`) and HSTS on HTTPS.
- Named rate limiters: `api` (600/min per authenticated user, 120/min per guest IP) applied to the
  authenticated API group; `login` (10/min per email+IP) applied to `POST /auth/login`.
- Readiness probes (`HealthService`) for database latency, cache read-back, queue reachability/depth,
  writable storage and pending migrations; exposed at `GET /api/v1/health` and `php artisan health:check`
  (non-zero exit when degraded).
- Privacy retention configuration (`config/privacy.php`) and `retention:purge` command with `--dry-run`,
  scheduled daily at 02:30; audit logs are immutable and never purged.
- Backup/restore commands:
  - `php artisan db:backup` — portable gzip JSON snapshot (schema + rows, 146 tables / 2,726 rows).
  - `php artisan db:restore <file> --database=<scratch>` — full restore (requires `--force` to overwrite
    an active database).
  - `php artisan db:restore <file> --table-prefix=drill_ --verify --drop` — non-destructive drill that
    rebuilds every table under a prefix, verifies row counts and removes the staging tables.
- Load probe `backend/scripts/load-test.mjs` and warm in-process benchmark `backend/scripts/bench-http.php`.
- CI pipeline `.github/workflows/ci.yml` (backend tests + `composer audit`, frontend tests/build +
  `npm audit`) and deployment/backup/monitoring runbooks plus production checklist.

## Verification

### Suites

| Check | Result |
| --- | --- |
| `php artisan test` | **145 passed (1705 assertions)**, 78.2s (includes new `ReadinessTest`) |
| `npx tsc -b` | clean |
| `npm run build` | clean, built in 9.87s (chunk-size warning only) |
| `npm test` | **18 passed (6 files)** |

### Live security headers

```
$ curl -s -D - -o /dev/null http://127.0.0.1:8000/api/v1/health
HTTP/1.1 200 OK
X-Request-Id: 01M7G3WH66NGSBWB48CVZ09D3V
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: no-referrer
Permissions-Policy: camera=(), microphone=(), geolocation=()
Cross-Origin-Opener-Policy: same-origin
Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'
Access-Control-Allow-Origin: http://127.0.0.1:5173
Access-Control-Expose-Headers: X-Request-Id
```

`X-Powered-By: PHP/8.4.24` is emitted by the PHP SAPI; set `expose_php=Off` on production web nodes
(recorded in the production checklist).

### Readiness

```
$ php artisan health:check
{
    "status": "ok",
    "checks": {"database": "ok", "cache": "ok", "queue": "ok", "storage": "ok", "migrations": "ok"},
    "diagnostics": {"database_latency_ms": 67.53, "queue_depth": 0, "migrations_total": 25, "migrations_pending": 0}
}
```

### Retention

`php artisan schedule:list` shows `integrations:sync` hourly and `retention:purge` daily at 02:30.
`php artisan retention:purge --dry-run` reported 0 rows per table on the demo dataset (nothing is past
its window yet); `ReadinessTest` proves old read/unread notifications, old report runs and expired
tokens are deleted while recent rows survive, with an immutable `audit_logs` entry `retention.purged`.

### Backup and restore drill

```
$ php artisan db:backup --path=backups/drill-2026-11-15.json.gz
Backup written to ...\storage\app\private\backups\drill-2026-11-15.json.gz (146 tables, 124.2 KiB).

$ php artisan db:restore backups/drill-2026-11-15.json.gz --table-prefix=drill_ --verify --drop
Restored 146 tables / 2726 rows into `panda_stocktake` as `drill_*` staging tables from drill-2026-11-15.json.gz (verified).
```

The local `panda` MySQL user cannot `CREATE DATABASE`, so the drill restores into prefixed staging
tables inside the active database instead of a scratch database. Restore into a separately provisioned
database is supported by `--database` on a deployment host where the app user has create rights.
Procedure and RPO/RTO targets are in `docs/runbooks/backup-restore.md`.

### Load evidence

Dev-server HTTP probe (4 single-threaded PHP built-in workers, 400 requests, concurrency 16) in
`load-test.json`: 400/400 HTTP 200, 2.6 req/s, p50 6.1s, p95 7.2s. This measures the Windows
`php -S`/`artisan serve` limitation (one request per worker), not application throughput.

Warm in-process benchmark (`in-process-benchmark.json`, 300 requests after warm-up, opcache enabled):

```
throughput_rps: 54.7
latency_ms: min 8.6 | p50 13.6 | p95 33.2 | p99 124.1
```

This approximates a warmed PHP-FPM/Octane worker. Production sizing guidance (8–16 FPM workers per
node, opcache on, Redis cache/queue, MySQL connection pooling) is in `docs/runbooks/deployment.md`.

### Dependency audit

- `npm audit --offline`: 0 vulnerabilities across 266 packages (offline advisory cache).
- `composer audit`: could not reach `packagist.org/api/security-advisories` from this machine
  (network timeout). CI runs `composer audit --no-dev` with network access.

## Engineering controls verified

- Tenant isolation tests remain green; platform report/audit rows are forced to `tenant_id = null`.
- Webhook signatures and SSRF guard retained from Phase 18; login and API rate limits now explicit.
- CORS restricted to `FRONTEND_URL`; no `supports_credentials`.
- Secrets are never returned by integration APIs (encrypted credentials / webhook secret).
- Queue worker, scheduler and health-check runbooks documented; restore drill successful.
- Privacy/retention review recorded in `docs/PRIVACY_RETENTION.md`.

## External sign-offs still required

Legal/accounting review and production credential cut-over (M-Pesa/card/bank, email, SMS) are business
decisions tracked in `docs/PRODUCTION_CHECKLIST.md`; the engineering controls above are complete.
