# Phase 18 — External integrations evidence

Date: 2026-11-15
Environment: local (`http://127.0.0.1:5173`, API `http://127.0.0.1:8000/api/v1`)
Tenant: Demo Supermarket (`01M78B8C1J3HRW14MB5FB2PATA`)
User: Demo Supermarket Owner (`owner@demosupermarket.test`)

## Delivered

- `integration_connections`, `integration_mappings`, `integration_sync_runs`, `integration_conflicts`, `integration_webhook_events` tables.
- Encrypted credentials and webhook secrets (`encrypted:array` / `encrypted` casts); API payloads expose only `has_credentials` and `has_webhook_secret`.
- CSV/row import and HTTPS API pull, canonical field mapping per entity (`products`, `movements`).
- Conflict detection (`name_mismatch`, `missing_required_fields`) with `accept_external`, `keep_local`, `ignored` resolutions.
- Public HMAC-SHA256 webhook receiver: `POST /api/v1/integrations/webhooks/{connection}` with `X-Brathamatu-Signature`.
- SSRF guard: remote endpoints must be https and resolve to a public IP.
- Hourly `integrations:sync` scheduled command for connections with `settings.auto_sync`.
- Business UI at `/business/integrations` (connections, mapping, sync, runs, conflicts, webhooks) gated by `integration.manage`.
- `integration.manage` granted to business-owner (inherited), business-admin and pos-manager.

## Browser walkthrough

1. Signed in as Demo Supermarket Owner and opened **Business → Integrations** (new nav entry).
2. Created connection **Browser ERP Bridge** (provider `erpnext`, CSV transport, webhook secret set).
3. Saved product mapping: `name → item_name`, `sku → item_code`, `cost_price → buying_price`.
4. Ran a product import with two rows:
   - `BROWSER-INT-SUGAR` (Browser Integrated Sugar, 240)
   - `BROWSER-INT-MILK` (Browser Integrated Milk, 65)
   Result banner: `completed · 2 applied · 0 skipped · 0 conflict(s)`; Last synced updated; catalogue version published.
5. Runs tab listed the run (2 in / 2 applied / 0 skipped / 0 conflicts).
6. Sent a signed webhook `stock.movement` for `BROWSER-INT-SUGAR` quantity 5 (reference `WEBHOOK-BROWSER-1`); response `signature_valid: true, status: processed`. Webhooks tab listed the event as valid/processed; inventory balance for the SKU became `5.000`.
7. Imported a conflicting row for the same SKU with name `Renamed Integrated Sugar`; Conflicts tab showed `name_mismatch`.
8. Resolved with **Accept external**, then re-ran the import: conflict count returned to 0 open and the product name updated to `Renamed Integrated Sugar`.
9. `Test` returned `CSV connections accept mapped rows through the sync endpoint.`; `Pause`/`Resume` flipped the status badge.

DB verification (tinker):

```
Browser Integrated Sugar | 2026-11-15 15:49:44
Browser Integrated Milk   | 2026-11-15 15:49:44
runs=2 events=1
variant=BROWSER-INT-SUGAR balances=5.000
Renamed Integrated Sugar | pending=0 accepted=1
```

## Defects found and fixed during verification

- Empty `field_map`/`options`/`settings` serialized as JSON `[]`, breaking the typed frontend parser → serializers now cast to objects.
- Conflict list treated the default `pending` state as resolved and hid the action buttons → UI now treats `pending` as open, and connection conflict counts only count pending conflicts.
- Mapping success notice was cleared immediately by the post-save refetch → notice reset now keyed to entity/connection changes only.

## Automated coverage

`php artisan test --filter=IntegrationTest` — 5 passed (59 assertions):

- connections listed without leaking secrets
- CSV import upserts catalogue and stock movements
- name conflicts recorded and resolvable
- webhooks require a valid signature and process events
- integrations isolated per tenant

Full suite: **130 passed (1570 assertions)**. Frontend `tsc -b` and `npm run build` pass.
