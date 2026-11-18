# Monitoring and incident response runbook

## Probes

- `GET /api/v1/health` — load-balancer probe every 30s. 200 = all checks ok, 503 = degraded.
  Checks: database latency, cache read-back, queue reachability/depth, writable storage, pending
  migrations.
- `php artisan health:check` — cron/agent probe; exits non-zero when degraded and prints JSON.
- `/up` — framework liveness probe (no dependencies).

## Alert thresholds

| Signal | Warning | Critical |
| --- | --- | --- |
| Health endpoint | 1 non-200 | 3 consecutive non-200 (2 min) |
| Database latency | > 100 ms p95 | > 500 ms p95 or connection errors |
| Queue depth | > 500 for 10 min | > 5000 or workers not processing |
| Failed jobs | > 10/hour | > 100/hour |
| Disk usage | > 75% | > 90% |
| 5xx rate | > 1% for 5 min | > 5% for 5 min |
| Login 429 rate | spike vs baseline | sustained 429 for legitimate users |

## Logs and tracing

- API response envelope and `X-Request-Id` correlate frontend reports with `storage/logs/laravel.log`
  and audit entries (`audit_logs.request_id`).
- Log level `warning` in production; never log secrets or full credentials.
- Ship logs to the central platform and retain per the privacy policy (default 90 days for application
  logs; audit logs follow the accounting retention policy and are never purged by `retention:purge`).

## Incident checklist

1. Confirm scope from health checks, 5xx rate and queue depth.
2. Check recent deploys and `php artisan schedule:list` / `queue:failed`.
3. Mitigate: roll back the release (see deployment runbook), restart workers, or fail over the database.
4. Preserve evidence: export logs and audit entries by request id; do not delete data.
5. If personal data may be breached, follow the privacy incident process in `docs/PRIVACY_RETENTION.md`.
6. Record timeline, root cause and follow-up actions.
