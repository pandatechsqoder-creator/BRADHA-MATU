# Privacy and retention review

Date: 2026-11-15
Scope: BRATHAMATU Stocktake (Laravel API, React app, MySQL, Redis). This is an engineering review, not
legal advice; the owner must obtain Kenyan counsel sign-off (Data Protection Act, 2019) before launch.

## Data inventory

| Data | Examples | Storage | Retention |
| --- | --- | --- | --- |
| Account identity | name, email, phone, password hash, verification state | `users`, `tenant_memberships`, `stock_taker_profiles` | Life of account; deleted/anonymised on DSR |
| Tenant business data | branches, catalogue, inventory, stock-takes, counts, reviews | tenant-scoped tables | Life of tenant contract |
| Financial records | quotes, payments, ledger journals, payouts, refunds | `payments`, `ledger_*`, `payouts` | 7 years (accounting policy) |
| Workforce records | applications, skills, availability, assignments, earnings | workforce tables | 7 years for payroll; 24 months for unsuccessful applicants |
| Support | tickets, messages, SLA timestamps | `support_tickets`, `support_ticket_messages` | Active + 24 months after closure (`closed_tickets_days=730`) |
| Notifications | in-app feed, preferences | `notifications`, `notification_preferences` | Unread 180 days; read 90 days (`retention:purge`) |
| Integration events | webhook payloads, sync runs, conflicts | `integration_webhook_events`, `integration_sync_runs` | 90 days for webhooks; sync runs 24 months |
| Report history | report runs and filters | `report_runs` | 365 days |
| Audit trail | actor, action, before/after snapshots, IP, request id | `audit_logs` | Immutable; retained per accounting/security policy (never purged by the automated job) |
| Tokens | Sanctum personal access tokens | `personal_access_tokens` | Tokens expire after 24h (`SANCTUM_TOKEN_EXPIRATION=1440`); expired rows removed after 7 days |

## Controls

- Tenant isolation via `BelongsToTenant` global scope and `TenantContext`; platform queries explicitly
  bypass scope only for platform-wide reporting.
- Financial confidentiality: workers never receive tenant charge/margin; tenants never receive worker
  compensation (enforced by resources and policies).
- Integration credentials and webhook secrets are encrypted at rest; API payloads expose only presence
  flags.
- Passwords hashed with bcrypt; login rate-limited per email+IP; API rate-limited per user/IP.
- Audit logs capture security-relevant actions with request id and IP; append-only through the service.
- Backups are encrypted in transit/at rest by the storage layer and follow the same retention as source
  records.

## Data subject requests (DSR)

1. Verify identity through the account email.
2. Export: generate a JSON extract for the user/tenant from the records listed above. No self-service DSR
   endpoint exists yet; run it with DBA review and log the action in `audit_logs`.
3. Erasure: anonymise personal identifiers (`users`, profiles, ticket messages authored by the user) while
   retaining financial and audit records under the applicable legal basis, or delete where no obligation
   exists.
4. Record the request, decision and legal basis; respond within the statutory window.

## Retention execution

`php artisan retention:purge` runs daily at 02:30 (see `docs/runbooks/deployment.md`). Use `--dry-run` to
preview. It never touches `audit_logs`, ledger entries, payments, payouts or stock-take records.

## Open items for legal/accounting sign-off

- Confirm the 7-year financial retention and the 24-month support/applicant windows.
- Confirm cross-border storage/processor list (hosting, email/SMS, M-Pesa) and DPAs with processors.
- Approve the privacy notice, cookie policy (frontend uses local storage for the bearer token; no
  analytics/tracking scripts were found) and breach-notification workflow.
- Decide whether tenant administrators need self-service export/erasure tooling.
