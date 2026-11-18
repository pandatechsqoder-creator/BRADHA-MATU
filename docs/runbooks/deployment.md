# Deployment runbook

## Topology

- Nginx/TLS → PHP-FPM 8.4 (8–16 workers per node) → Laravel `backend/`.
- MySQL 8 primary (private network, automated snapshots) + Redis 7 (cache, queue, sessions).
- Static frontend build (`frontend/dist`) served by Nginx or a CDN; API at `/api/v1`.
- Queue workers under Supervisor/systemd; scheduler via cron `* * * * * php artisan schedule:run`.

## Required environment

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
DB_CONNECTION=mysql DB_HOST=<private> DB_PORT=3306 DB_DATABASE=brathamatu DB_USERNAME=... DB_PASSWORD=...
CACHE_STORE=redis QUEUE_CONNECTION=redis REDIS_HOST=<private>
SANCTUM_TOKEN_EXPIRATION=1440
PAYMENTS_SANDBOX=false
MPESA_* / CARD_* / BANK_* = live provider values
MAIL_* = production SMTP
RETENTION_* = agreed privacy windows
```

Server hardening: `expose_php=Off`, `display_errors=Off`, opcache enabled, TLS 1.2+, HSTS at the edge,
database/Redis reachable only from the app subnet.

## cPanel SSH access

Use `scripts/cpanel-ssh.sh` for an interactive cPanel SSH session or to run a
remote command. It uses the existing `community-production` SSH config alias
(host, port, and user stay in SSH config), converts the local PuTTY key to a
temporary OpenSSH key, and removes that temporary file when SSH exits. The
private key and passphrase file remain outside the repository. Never put a
passphrase value in a script, environment file, command line, or chat.

On the current machine, the script defaults to the owner-protected local files
under `~/Videos/ol/communi6 ssh acces/`. Override the locations in another
workspace or account:

```bash
CPANEL_PPK="$HOME/path/to/key.ppk" \
CPANEL_PASSPHRASE_FILE="$HOME/path/to/passphrase-file" \
scripts/cpanel-ssh.sh 'printf "ssh-ok user=%s home=%s\n" "$(id -un)" "$HOME"'
```

The SSH host alias must already exist in `~/.ssh/config`; verify the server's
host key through the account owner's approved process before trusting a new
host.

## Release steps

1. CI must be green (`.github/workflows/ci.yml`): backend tests, `composer audit`, frontend tests/build,
   `npm audit`.
2. Build artifacts with Composer running under the same PHP version as the app so optimized classmaps
   include newly added classes and Composer's platform checks use the correct runtime. On this cPanel
   host, use `/opt/cpanel/ea-php84/root/usr/bin/php /usr/local/bin/composer install --no-dev
   --optimize-autoloader`; do not use the unqualified `composer` command, which may run under the
   server's older default CLI PHP. Then run `npm ci && npm run build`.
3. Put the app in maintenance mode if the migration is not backward compatible:
   `php artisan down --retry=60`.
4. Deploy the new release directory and point the `current` symlink to it.
5. Run `php artisan migrate --force`.
6. Warm caches: `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
7. Restart workers: `php artisan queue:restart`.
8. `php artisan health:check` must exit 0; then `php artisan up`.
9. Smoke test: login, tenant dashboard, POS sale, report export, support ticket (see Phase 21 checklist).

## Rollback

- Point `current` back to the previous release and `php artisan queue:restart`.
- Migrations are forward-only in production; roll back only with a reviewed down-migration and a fresh
  backup (`php artisan db:backup`) taken before the release.
- Verify `/api/v1/health` and the smoke-test list after rollback.

## Queue and database tuning

- Workers: `php artisan queue:work redis --sleep=1 --tries=3 --timeout=90 --max-time=3600`.
  Run at least two workers; use `--queue=default,imports` if imports are split later.
- Redis: `maxmemory-policy noeviction` for queue keys; separate logical DBs for cache and queue.
- MySQL: InnoDB, `innodb_buffer_pool_size` ≈ 60–70% of RAM, `max_connections` ≥ FPM workers + workers,
  slow-query log on, daily backups plus binlogs for point-in-time recovery.
- Health probe `/api/v1/health` from the load balancer every 30s; alert if non-200 for 2 minutes.

## Scheduler (cron)

```
* * * * * cd /var/www/brathamatu/current/backend && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs: `integrations:sync` hourly, `retention:purge` daily 02:30.
