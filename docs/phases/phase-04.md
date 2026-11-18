# Phase 4 — Catalogue onboarding, declaration and pricing

Status: **passed** (backend tests, frontend tests, browser acceptance)
Date: 2026-11-11

## Objective

Give a tenant one trustworthy catalogue for stock-taking: products, variants, barcodes,
categories and units that can be managed by hand or imported from CSV/XLSX with validation,
column mapping, preview and row-level errors. The tenant then declares the catalogue accurate,
which issues a catalogue fee invoice under a platform-configurable pricing policy. Request
creation stays blocked until the catalogue is ready.

## Delivered — backend

- **Catalogue schema** (`2026_11_11_050000_create_catalogue_tables.php`):
  - `catalogues` and `catalogue_versions` — every publish supersedes the previous version and
    increments `version_number`; `catalogues.current_version_id` always points at the active one.
  - `products`, `product_variants`, `product_barcodes`, `product_categories`.
  - `unit_of_measure` directory (seeded piece/pack/box/carton/kg/g/ml/litre/metre/roll) plus
    tenant-created custom units and `unit_conversions` (from → to factor, e.g. carton = 12 piece).
  - `catalogue_imports` and `catalogue_import_rows`; `catalogue_declarations`;
    `catalogue_pricing_policies`, `catalogue_pricing_versions`, `catalogue_invoices`.
- **Product management**:
  - `GET|POST /products`, `GET|PATCH|DELETE /products/{product}`,
    `POST /products/{product}/barcodes`, `DELETE /products/{product}/barcodes/{barcode}`,
    `GET|POST /product-categories`, `GET|POST /units`, `POST /units/conversions`.
  - SKU uniqueness per tenant; category resolution by existing ULID or new name; base/packaging
    unit resolution through the unit service; packaging factor creates a conversion row.
  - Products are discontinued (status + inactive variants), never deleted; history is preserved.
  - Mutations publish a new catalogue version and reset `confirmed_at`, so a declaration never
    silently covers later edits.
- **Queued CSV/XLSX import** (`ProcessCatalogueImport` job on Redis):
  - 16 canonical fields (name, variant, SKU, barcode/type, brand, category/subcategory,
    description, unit, packaging unit/factor, tracking mode, status, supplier ref, cost price) with
    header aliases and automatic mapping when headers match.
  - Mapping can be saved and rows revalidated; preview returns per-row status and messages.
  - Row validation: required name, required SKU, duplicate SKU/barcode in file, invalid numbers,
    barcode format/length, unknown units fall back to `piece`, etc.
  - `partial` policy imports valid rows and skips errors; `all_or_nothing` blocks commit while any
    error exists (`422`).
  - Duplicate strategy on commit: `skip` or `update` the existing product matched by SKU/barcode.
  - Error report CSV (`GET …/imports/{import}/errors`) with row number, status, messages and all
    canonical values.
  - XLSX reader handles inline strings and shared strings without an external spreadsheet service.
- **Declaration and invoice**:
  - `POST /catalogues/{catalogue}/declarations` requires the accuracy confirmation, a statement
    (min length), optional exceptions and a declaration version; records actor, IP and version.
  - Readiness requires a catalogue, a current version, products and a declaration for that version.
  - Declaring issues exactly one `catalogue_invoices` row per catalogue version (idempotent by
    version), storing the pricing version used. Declaring the same version twice returns
    `ALREADY_DECLARED` (422).
  - Catalogue summary exposes versions, declarations, latest declaration/invoice and readiness.
- **Pricing policy** (platform):
  - `GET|PATCH /platform/catalogue-pricing` (permission `pricing.manage`) with models
    `banded|flat|per_product|minimum|capped|promotional|negotiated`, currency, count basis,
    base/included/scale/max charges and tax rate.
  - Every save creates a new immutable `catalogue_pricing_versions` row with effective timestamp.
  - Interim defaults preserve the published anchor points: **KSh 100 at 100 products**, linear
    banding to **KSh 2,000 at 6,000 products**, then capped.
  - Estimates (`GET /catalogue-pricing/estimate?product_count=…`) return subtotal, tax, total and
    a human-readable explanation.
- **Authorization and audit**: `catalogue.view|create|update|import|confirm` and
  `pricing.manage`; tenant-scoped records return 404 across tenants. Audit actions include
  `product.created|updated|discontinued|barcode_added|barcode_removed`,
  `product_category.created`, `unit.created`, `unit.conversion_saved`,
  `catalogue.import.uploaded|mapping_updated|committed`, `catalogue.declared`,
  `catalogue.pricing.updated`.
- **Demo data**: the supermarket tenant keeps its deposit-policy setting; the catalogue pricing
  policy is created lazily by `CataloguePricingService` from the interim defaults the first time
  an estimate or invoice is requested, so every environment starts from the published anchors.

## Delivered — frontend

- **`/business/catalogue`** — catalogue workspace with permission-aware tabs:
  - **Products**: searchable/filterable paginated table, add-product form (name, SKU, variant,
    brand, category with datalist, barcode, base unit, packaging unit/factor, cost price),
    category creation, discontinue action, Open link.
  - **Imports**: file upload (CSV/TXT/XLSX, 10 MB), invalid-row policy, history with status and
    counts, import detail with column-mapping editor, preview table, error-report download,
    duplicate strategy and commit.
  - **Units**: unit directory, conversions and add forms.
  - **Declaration**: readiness checklist, live fee estimate, declaration form with authorisation
    checkbox, signed declaration and invoice summary.
  - Stat cards: active products/categories, barcodes, current version, declaration status.
- **`/business/catalogue/products/{productId}`** — product detail: summary cards, variant and
  barcode management, edit form, discontinue action.
- **`/platform/catalogue-pricing`** — policy form (model, basis, charges, tax), live sample
  estimates at 100/500/1,000/3,000/6,000/10,000 products, and version history.
- **Dashboard** — catalogue product count, branch count, and readiness items for branches and
  “Catalogue confirmed” driven by the real readiness payload.
- **Navigation** — “Catalogue” in the business sidebar and “Catalogue pricing” in platform nav.
- Cost price is only rendered when the backend includes it; workers never receive tenant charge
  or margin data.

## API surface added

See `docs/api/openapi.yaml` (v1.0.0-phase4):

- `GET /tenants/{tenant}/catalogue`
- `GET /tenants/{tenant}/catalogues/{catalogue}/versions`
- `GET|POST /tenants/{tenant}/catalogues/{catalogue}/declarations`
- `GET|POST /tenants/{tenant}/catalogue/imports`
- `GET /tenants/{tenant}/catalogue/imports/{import}`
- `PATCH /tenants/{tenant}/catalogue/imports/{import}/mapping`
- `POST /tenants/{tenant}/catalogue/imports/{import}/commit`
- `GET /tenants/{tenant}/catalogue/imports/{import}/errors` (CSV)
- `GET|POST /tenants/{tenant}/products`
- `GET|PATCH|DELETE /tenants/{tenant}/products/{product}`
- `POST /tenants/{tenant}/products/{product}/barcodes`
- `DELETE /tenants/{tenant}/products/{product}/barcodes/{barcode}`
- `GET|POST /tenants/{tenant}/product-categories`
- `GET|POST /tenants/{tenant}/units`
- `POST /tenants/{tenant}/units/conversions`
- `GET /catalogue-pricing/estimate`
- `GET|PATCH /platform/catalogue-pricing`

## Verification

- Backend: `php artisan test` — **38 passed** (238 assertions). Phase 4 coverage
  (`CatalogueTest`, 8 tests / 95 assertions):
  - create product → declare → invoice; readiness flips to ready; duplicate declaration rejected
  - CSV import upload (202, integer counts) → queue → auto-mapping → preview errors → commit;
    error CSV contains row messages
  - second import updates duplicate rows with `duplicate_strategy=update`
  - `all_or_nothing` commit blocked while errors exist
  - XLSX inline-string parsing
  - platform pricing policy update creates a new version and estimate anchors hold
    (100 products → 100; 6,000 → 2,000)
  - tenant isolation and permission guards for catalogue records
  - **regression:** product update returns version flag, publishes a new catalogue version,
    writes `product.updated`, and serialises `attributes` as a JSON object
- Frontend: `npm run build` passes (TypeScript + Vite, expected chunk-size warning);
  `npm test -- --run` — **13 passed**.
- Browser acceptance (Chrome, `http://127.0.0.1:5173`):
  1. Signed in as the Bright Mart owner; dashboard readiness showed 2 branches and the catalogue
     pending.
  2. Created the initial product manually; detail page rendered variants and barcodes; edited the
     product and added a barcode (both persisted and audited).
  3. Uploaded `brightmart-catalogue.csv` (5 rows, 2 intentional errors). The queue worker
     processed it; auto-mapping matched all 9 present columns; preview flagged the duplicate SKU
     and the missing product name; error CSV downloaded.
  4. Committed with “skip” duplicates: catalogue moved to **v4 with 4 active products, 3
     categories and 5 barcodes**; products appeared in the Products tab with packaging strings.
  5. Units tab: added custom unit **Dozen** (factor 12) and conversion **dozen → piece = 12**;
     the unit appeared immediately in the product form's base-unit selector.
  6. Declaration tab: fee estimate showed **Ksh 100.00** for 4 products (banded, includes up to
     100); signed with authorisation checked and the exceptions note; readiness became
     “Catalogue ready”, declaration “Signed”, invoice **CAT-20261111-RMVIL2 · Ksh 100.00 ·
     issued**.
  7. As `superadmin@brathamatu.test`: `/platform/catalogue-pricing` loaded the policy (banded,
     KSh 100 / 100 products, KSh 2,000 cap / 6,000) and sample estimates; changing tax to 16%
     saved a new pricing version (v2) with tax applied to estimates while v1 remained in history.
  8. Platform audit trail showed `catalogue.pricing.updated`, `catalogue.declared`,
     `catalogue.import.uploaded|committed`, `unit.created`, `unit.conversion_saved`,
     `product.created|updated|barcode_added` with actor, tenant and request correlation.
- Evidence screenshots: `docs/evidence/phase-04/`
  - `dashboard-readiness.png`
  - `catalogue-products.png`
  - `import-committed.png`
  - `declaration-invoice.png`
  - `platform-catalogue-pricing.png`

## Notes / deferred

- Import processing requires a running queue worker
  (`php artisan queue:work redis`). Uploads return `202 queued`; the UI polls every 1.5 s while
  the import is `queued|processing`.
- Declaring is per catalogue version. Any later product edit publishes a new version and clears
  `confirmed_at`, forcing a fresh declaration before requests can proceed.
- The banded model is linear interpolation between the published anchor points; commercial
  confirmation may replace it with a different model without schema changes.
- The catalogue invoice is recorded as `issued` for this phase; settlement, allocation and ledger
  posting arrive with payments in Phase 7. Issued invoices keep the pricing version they were
  created with.
- Demo and browser-test accounts remain local/staging only and must never be enabled in
  production.
