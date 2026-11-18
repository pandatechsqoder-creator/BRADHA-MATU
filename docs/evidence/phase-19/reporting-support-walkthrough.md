# Phase 19 — Reporting, analytics, support and notifications evidence

Date: 2026-11-15
Environment: local (`http://127.0.0.1:5173`, API `http://127.0.0.1:8000/api/v1`)
Tenants: Demo Supermarket, platform (BRATHAMATU operations)
Users: `owner@demosupermarket.test` (business owner), `superadmin@brathamatu.test`

## Delivered

- `support_tickets`, `support_ticket_messages`, `notifications`, `notification_preferences`, `report_runs` tables.
- Report catalogue (inventory valuation, sales summary, stock variance, support summary) with JSON generation, CSV export, persisted run history and audit entries (`report.generated`, `report.exported`).
- Tenant analytics overview: stock value, active products/jobs, 30-day sales, open disputes/tickets, 14-day series, top stock positions, deterministic insights.
- Platform analytics and report queue; platform-scoped report runs and audits are stored with `tenant_id = null` even when the request carries `X-Tenant-Id`.
- Support workflow: tenant create/reply/close/reopen; platform queue, reply (customer vs internal note), status and priority updates, first-response timestamp, SLA due/overdue flags, notification fan-out to platform support roles and to the tenant creator.
- Notification centre: per-user feed, unread counts, mark one/all read, per-channel and per-event preferences (in-app, email, digest cadence).
- AI/intelligence interface contract at `GET /api/v1/tenants/{tenant}/analytics/overview` → `ai`: `enabled: false` plus declared interfaces (`demand_forecast`, `variance_anomaly`, `pricing_recommendation`, `support_triage`).
- RBAC: `support.request` for tenant roles; `support.view` / `support.manage` for platform roles; reports gated by `report.view` / `report.export`.
- UI: `/business/reports`, `/platform/reports`, `/platform/support`, `/notifications`, and the rebuilt `/support` centre; nav entries are permission-gated.

## Browser walkthrough

1. Signed in as Demo Supermarket Owner and opened **Reports & analytics** (`/business/reports`).
   - KPIs rendered live: stock value `Ksh 94,238.00`, 10 active products, 6 active stock-takes, 3 receipts (30d), 4 open discrepancies, 0 open tickets.
   - Insights rendered: **4 discrepancies need attention**, 14-day sales/job trends, category/branch breakdowns, AI interface card (`enabled = false`).
2. Generated **Inventory valuation** for 2026-10-16 → 2026-11-15 (limit 1000): 10 rows, summary totals, table preview; the run appeared under recent runs.
3. Exported the same report as CSV: download triggered and notice **“CSV export downloaded and audit-logged”**.
4. Opened **Support** (`/support`) and created ticket **“POS terminal 2 cannot process card payments”** (category `pos`, priority `high`); alert: `Ticket 6VR8RPHZ created. Our team has been notified.`
5. Signed out, signed in as `superadmin@brathamatu.test`, opened **Support queue** (`/platform/support`).
   - Counters: OPEN 1, PAST SLA 0, VISIBLE TICKETS 1.
   - Queue row showed tenant `Demo Supermarket · high · 1 messages · open`.
6. Sent a platform reply: **“Thanks for reporting this. We have escalated to the payments team and will update you within 2 hours.”** → notice `Reply sent to the customer.`, message count 2, `First response` set to `15 Nov 2026, 19:33`.
7. Set the ticket status to **Resolved** via the detail control → notice `Status set to resolved.`, OPEN counter 0, badge `resolved`.
8. Signed back in as the tenant owner, opened **Notifications** (`/notifications`).
   - Feed showed `Support replied to POS terminal 2 cannot process card payments` (with reply excerpt) and `Support ticket … is now resolved`; unread badge `2`.
   - **Mark all read** cleared the badge to `0` and disabled the button.
   - Preferences: switched email digest to **Daily digest**, saved → `Notification preferences saved.`
9. Returned to **Support**: thread showed the platform reply and `resolved` status. Replied again (tenant): `Reply sent.`; ticket automatically reopened to `open` with 3 messages.
10. Reloaded **Reports & analytics** after the copy fix; insight now reads `4 discrepancies need attention`.

DB verification (tinker):

```
tickets: 01M7G0PTD31EQJ5Y6Y6VR8RPHZ | open | high | first_response_at=2026-11-15 16:33:14
messages: 3 (owner, super admin, owner) — all customer-visible
notifications: platform users 1,5 ← new ticket + customer reply; tenant user 10 ← reply + resolved (read_at set)
notification_preferences: user 10 digest_frequency=daily, event map persisted
report_runs: inventory_valuation ×2 (json, csv; 10 rows; filters from/to/limit)
audit_logs: report.generated ×2, report.exported ×1, support.ticket.created, support.ticket.replied ×2, support.ticket.updated
```

## Defects found and fixed during verification

- Insight copy pluralisation produced `4 discrepancyies` → now `4 discrepancies need attention` (and singular form handled).
- Platform report runs and audit rows created while `X-Tenant-Id` was present inherited the tenant id → platform scope now forces `tenant_id = null` with `saveQuietly()`.
- Nested route binding `tenants/{tenant}/support-tickets/{ticket:uuid}` failed without a `Tenant::tickets()` relation → relation added.

## Automated coverage

`php artisan test --filter=ReportingSupportTest` — **11 passed (107 assertions)**:

- tenant reports list/generate/export, audit + `report_runs`
- unknown report type and invalid period rejected
- reports tenant-isolated
- analytics overview KPI/series/AI contract
- support lifecycle tenant ↔ platform with notifications and mark read / mark all read
- tenant close and reopen
- notification preferences (in-app disable suppresses delivery)
- permission denial without `support.request`
- platform reports generate/export and tenant user denied
- platform support queue gated by `support.view`
- report generation audited with request context

Full suite: **141 passed (1677 assertions)**. Frontend `tsc -b` and `npm run build` pass.
