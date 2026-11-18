# Production readiness checklist

Legend: [x] engineering-verified locally · [ ] requires owner/infrastructure action.

## Security

- [x] Tenant isolation covered by tests for every domain (platform bypass only in platform controllers).
- [x] Auth: Sanctum bearer tokens, 24h expiry, login throttled per email+IP, API throttled per user/IP.
- [x] RBAC/permission middleware on tenant, platform and workforce routes.
- [x] Security headers + API CSP live; HSTS on HTTPS; CORS restricted to `FRONTEND_URL`.
- [x] Webhook HMAC verification and SSRF guard for integrations.
- [x] Integration credentials encrypted at rest and never serialised to clients.
- [x] Audit trail with request id/IP for financial, workflow, support and retention events.
- [ ] `expose_php=Off`, `display_errors=Off`, TLS 1.2+ and WAF at the edge.
- [x] `DatabaseSeeder` runs RBAC only in `production`; demo catalogue/POS fixtures cannot be seeded there.
- [ ] Production database seeded with RBAC plus the real platform administrator account.
- [ ] Rotate all local/demo secrets; use a managed secret store for DB/Redis/provider keys.
- [ ] Penetration test on staging; no critical/high findings.

## Reliability and performance

- [x] Health probes (DB, cache, queue, storage, migrations) and `health:check` exit codes.
- [x] Rate limits; scheduler (`integrations:sync`, `retention:purge`) registered.
- [x] Load evidence recorded (`docs/evidence/phase-20/`); dev-server vs warm-worker results separated.
- [ ] PHP-FPM sizing/opcache validated on staging hardware; MySQL/Redis tuning applied.
- [ ] Queue workers supervised (2+), failed-job alerts, retry policy confirmed.
- [ ] Load test at expected peak on staging; p95 target agreed with the business.

## Data protection

- [x] Retention windows configured and enforced by `retention:purge` (audit logs excluded).
- [x] Privacy data inventory and DSR procedure documented (`docs/PRIVACY_RETENTION.md`).
- [ ] Legal/accounting sign-off of retention periods, processors and privacy notice.
- [ ] Encrypted off-host backups with restore drill recorded monthly (`docs/runbooks/backup-restore.md`).

## Deployment

- [x] CI pipeline defined (tests, audits, build).
- [x] Deployment, rollback, backup/restore and monitoring runbooks written.
- [ ] Staging environment matching production topology; smoke test after every deploy.
- [ ] Uptime/error monitoring and on-call routing configured (Phase 21/ops).
- [ ] Provider cut-over: M-Pesa/card/bank, SMTP/SMS, storage bucket; sandbox flags off.

## Acceptance

- [x] Backend suite: 145 passed (1705 assertions); frontend 18 tests; build clean.
- [x] Browser verification recorded for Phases 10–19.
- [ ] Phase 21 Playwright acceptance suite green on staging and final sign-off.
