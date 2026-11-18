# Phase 15 — Production launch readiness report

Status: **engineering readiness complete** (2026-11-17). The live cut-over has **not** happened and
cannot start until the owner supplies hosting, domain/TLS, contact details and legal sign-off. The new
`marketing:launch-check` command is the launch gate and currently reports one hard blocker
(`contact_details`) plus the expected integration and provider warnings.

## Objective

Make launch a checklist, not a guess: verify configuration, content, contact details, integrations and
backups with one command; document the deployment, DNS/TLS, Search Console, analytics-consent,
smoke-test, rollback and monitoring steps; and give staff a concise guide to the website tools.

## Delivered

### Launch gate (`php artisan marketing:launch-check`)

Reports a table and JSON, exits `1` on any `fail`:

| Check | Rule |
| --- | --- |
| `environment` | In production: `APP_DEBUG=false`, HTTPS `APP_URL`, secure session cookie; otherwise a warning |
| `indexing` | Production must have `MARKETING_INDEXABLE=true`; other environments warn when indexing is on |
| `homepage` | A page marked as home must be published |
| `published_pages` | Warns below five published pages |
| `published_articles` | Warns when no article is published |
| `contact_details` | **Fails** when neither a contact email nor phone is configured (the public site shows a pending state instead of inventing details) |
| `google_connection`, `meta_connection` | Warn until a provider connection is active |
| `search_console`, `ga4` | Warn until a property is synced |
| `backups` | Warn when no snapshot exists or the latest is older than 48 hours |
| `outbound_provider` | Always warns; outbound steps remain blocked by design until a provider is approved |

Covered by `LaunchCheckTest` (2 tests, 7 assertions): missing contact/homepage fails, confirmed
contact plus a published homepage passes with warnings.

### Current readiness snapshot (development database, 2026-11-17)

`docs/evidence/phase-15-launch/launch-check.txt` records the real run:

- homepage published ✅; 35 published pages ✅; 3 published articles ✅;
- contact details **fail** — owner has not confirmed an email or phone;
- Google, Meta, Search Console and GA4 warn — no approved connections yet;
- backup present and fresh ✅; outbound provider warn (blocked by design).

### Deployment runbook

[phase-15-runbook.md](phase-15-runbook.md) lists the owner inputs, server requirements, deployment
sequence, DNS/TLS and indexing steps, GA4/consent rules, the cut-over smoke test, rollback and
monitoring. It explicitly forbids running `DemoDataSeeder` in production and keeps the automation kill
switch on until a provider is approved.

### Staff guide

[staff-guide.md](staff-guide.md) explains roles, page review/publishing, media rules, navigation and
content library, SEO diagnostics, blog workflow, leads and consent, campaigns, analytics limits,
automation and the kill switch, plus safe publishing habits.

## Verification

| Check | Result |
| --- | --- |
| `LaunchCheckTest` | 2 passed, 7 assertions |
| `marketing:launch-check` against the dev database | Exits non-zero with `contact_details` as the only fail |
| Full backend suite (with the launch-check tests) | 288 passed, 3,055 assertions |

## Blocked items (owner action)

| Item | Owner action |
| --- | --- |
| Contact email and/or phone | Confirm the real details; set `MARKETING_CONTACT_EMAIL`/`PHONE` or update settings, then re-run the launch check |
| Domain, DNS and TLS | Provide access; follow the runbook DNS/TLS section |
| Hosting, queue worker, scheduler | Provide the production host; deploy per the runbook |
| Legal entity and governing law | Confirm for the terms page |
| Google and Meta credentials, Meta app review | Complete the Phase 9/10 prerequisites |
| Search Console and GA4 properties | Provide verified access; submit the sitemap |
| Outbound email/SMS/WhatsApp provider | Select and approve a provider before enabling external steps |
| Offsite backup storage | Choose the target; copy the nightly snapshot |
| Final content and asset sign-off | Review Phases 2–4 copy and any imagery |

## Exit gate

The Phase 15 exit gate is **not yet met**: it requires a live domain with SSL, verified production
configuration, Search Console/GA4 consent behaviour, approved connections, initial content and staff
sign-off. Everything that can be prepared without those inputs is complete; the launch check will flip
from `not_ready` to `ready_with_warnings` once contact details are confirmed, and to `ready` when the
integration warnings are also resolved.
