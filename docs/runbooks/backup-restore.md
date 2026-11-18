# Backup and restore runbook

## Targets

- RPO 15 minutes (binlog-backed point-in-time recovery); nightly logical snapshot retained 35 days,
  monthly retained 12 months, encrypted and stored off-host.
- RTO 2 hours for a full restore into a provisioned database.

## Nightly backup

```
cd backend
php artisan db:backup --path=backups/prod-$(date +%Y%m%d).json.gz
```

The snapshot is a gzip JSON document containing `SHOW CREATE TABLE` DDL and all rows. Copy
`storage/app/private/backups/*` to encrypted object storage (server-side encryption + lifecycle rule).
Also take the provider's native MySQL snapshot/binlog backup; the logical dump is the portable fallback.

## Restore drill (non-destructive)

```
php artisan db:restore backups/prod-20261115.json.gz --table-prefix=drill_ --verify --drop
```

This recreates every table under `drill_*`, verifies row counts against the snapshot and drops the
staging tables. Run it monthly and record the output in the operations log.

## Full restore

On a host whose database user may create databases:

```
php artisan db:restore backups/prod-20261115.json.gz --database=brathamatu_restore --verify
```

Restoring over the active database requires `--force` and is refused in `APP_ENV=production` without it:

```
php artisan down --retry=120
php artisan db:restore backups/prod-20261115.json.gz --force
php artisan migrate --force
php artisan health:check
php artisan up
```

If the app user cannot create databases, provision an empty database manually and grant the app user
rights before running `--database=<name>`.

## Notes

- Snapshots contain personal and financial data; encrypt at rest, restrict access, and delete per the
  retention policy in `docs/PRIVACY_RETENTION.md`.
- Restores rebuild tables listed in the snapshot; extra tables in the target database are left untouched.
- Staging-mode restores strip foreign keys because MySQL constraint names are database-global; row-level
  integrity is still verified by count comparison.
