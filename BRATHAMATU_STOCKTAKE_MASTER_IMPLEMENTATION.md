# BRATHAMATU STOCKTAKE PLATFORM
## Master Implementation Specification and Single Source of Truth

**Document status:** Master product, architecture, database, API, security, delivery and acceptance specification  
**Version:** 1.0  
**Date:** 11 November 2026  
**Owner / Platform operator:** BRATHAMATU  
**Backend:** Laravel 13  
**Frontend:** TanStack React application  
**Database:** MySQL 8  
**API style:** Versioned REST API, `/api/v1`  
**Primary market and operating context:** Kenya; KES; M-Pesa, bank and card payments  
**Development approach:** Phased delivery, browser-based testing at every phase, no phase accepted without evidence.

---

# 0. DOCUMENT AUTHORITY AND PROJECT RULES

This document is the authoritative source of truth for the BRATHAMATU Stocktake platform. Every developer, coding agent, backend engineer, frontend engineer, tester and deployment operator must follow it.

If code, an issue, an old prompt, a generated screen or an informal discussion conflicts with this document, this document takes precedence until an explicitly approved change is recorded in its Change Control Register.

## 0.1 Product vision

Build a professional, secure, multi-tenant stock-taking SaaS and managed stock-taking marketplace operated by BRATHAMATU. The platform serves businesses that need accurate physical inventory counts, and a managed workforce of verified stock takers who carry out assignments under BRATHAMATU's operational controls.

The platform must also include BRATHAMATU POS as an integrated point-of-sale product. Businesses using BRATHAMATU POS and businesses using external POS/ERP systems, spreadsheets or manual records must all be able to use the stock-taking marketplace.

The system must support:
- Individual businesses, growing SMEs, chains, franchises, agencies and enterprise organisations.
- Multiple branches, warehouses, departments, shelves, zones and stock locations.
- On-demand, weekly, fortnightly, monthly, quarterly, half-yearly, annual and custom-date stock-taking.
- Full physical stock takes, cycle counts, spot checks, blind counts, guided counts, double counts and recounts.
- Tenant-owned product catalogues as the authoritative reference for count work.
- BRATHAMATU review, quotation, deposit collection, job approval, assignment, quality control, tenant sign-off and stock-taker payouts.
- Stock-taker onboarding, verification, training, scheduling, performance and progression.
- Financial accounting, payments, reconciliation, refunds, payouts, disputes and auditable financial history.
- Browser-first operational workflows, responsive layouts and accessible user experiences.
- Future scale to millions of registered accounts and many concurrent businesses, without prematurely splitting the application into microservices.

## 0.2 Non-negotiable implementation principles

1. Laravel is the authoritative backend. Business rules, validation, permissions, pricing, financial calculations and workflow transitions must never exist only in the browser.
2. TanStack is the frontend. It consumes the documented API and must not connect directly to the database.
3. Use a modular monolith initially, with clear domain boundaries and service interfaces. Extract services only when measured scale or operational needs justify it.
4. Use tenant isolation on every tenant-owned record, query, file, export, search operation, notification and background job.
5. A tenant's catalogue is the source of truth for product identity and the reference used for a stock-taking job.
6. Stock takers may record physical quantities and authorised observations only. They cannot edit tenant catalogue master data.
7. A stock-taking job is a managed commercial engagement. BRATHAMATU controls review, pricing, assignment, quality, payout and dispute resolution.
8. Tenant charge and stock-taker compensation are separate confidential financial values. A stock taker must not see the tenant's commercial charge or BRATHAMATU margin.
9. Financial transactions, count submissions, approvals and audit records must be immutable or revisioned. Do not silently overwrite history.
10. Every important workflow must have explicit statuses, permissions, validation, notifications, audit events and tests.
11. All phases must be browser-tested using real application routes and realistic seeded data.
12. Do not claim a feature is complete because a page renders. It is complete only when backend, frontend, permissions, validation, error states, audit trail and browser tests pass.
13. Do not use fake production integrations. Use sandbox adapters and clearly labelled test mode until real credentials and certification are supplied.
14. Never make destructive schema or data changes without migration safety, backup strategy and review.
15. Preserve this document and update it through controlled change requests rather than replacing it with scattered prompts.

## 0.3 Scope boundaries and configurable decisions

The platform architecture must support these choices without hardcoding unconfirmed commercial values:
- Catalogue fee formula: known proposed points are KSh 100 for 100 products and KSh 2,000 for catalogues up to 6,000 products, with a stated maximum charge of KSh 2,000 even above 6,000 products. The exact calculation between these points is pending confirmation. Implement versioned, admin-configurable pricing tiers/formulas and do not silently select a formula.
- Job deposit: amount or percentage, minimum/maximum, due date and refund rules must be configurable by BRATHAMATU. The exact default percentage is pending confirmation.
- BRATHAMATU POS commercial relationship: support separately billed jobs, preferential prices and subscription-included access as configurable product/plan policies. Do not assume one is approved.
- Recount cost responsibility: configurable after investigation. Possible outcomes include BRATHAMATU bearing the cost, stock taker bearing it when a verified counting error is established, tenant paying for a requested additional recount, or split allocation.
- Recurring schedule commercial model: support reminder-only, proposed job generation requiring tenant authorisation, and prepaid/contracted service schedules. No recurring paid job or charge may occur without valid tenant authorisation and configured contractual authority.
- Tax treatment, accounting recognition, payment holding structure, marketplace legal obligations and any regulated escrow/payment activity require Kenyan legal and accounting review before production launch.

Represent these as settings, policy records or pricing versions with effective dates, not scattered constants.

---

# 1. PRODUCT AND BUSINESS MODEL

## 1.1 Parties

### BRATHAMATU
Platform owner and operator. BRATHAMATU:
- Owns and administers the platform.
- Approves tenant organisations and platform users where required.
- Reviews stock-taking requests and catalogue readiness.
- Determines final tenant quotation and separate stock-taker compensation.
- Sets pricing policies, deposits, service fees and compensation bands.
- Approves and assigns stock takers.
- Monitors job delivery and quality.
- Handles disputes, recounts, refunds and escalations.
- Authorises stock-taker payouts.
- Operates BRATHAMATU POS and platform integrations.
- Maintains platform-wide financial, security and audit controls.

### Tenant / Business
The business commissioning stock-taking. It:
- Registers its organisation and branches.
- Supplies and maintains an accurate catalogue.
- Declares catalogue accuracy and ownership/authority to submit the data.
- Selects stock-taking scope and schedule.
- Requests jobs and pays deposits/invoices to BRATHAMATU.
- Provides access, site instructions and relevant stock movement cut-off information.
- Reviews completed results, accepts them, requests corrections/recounts or raises a dispute.
- Receives reports and approved reconciliation exports.
- Controls tenant users, roles and access.

### Stock taker
A verified worker assigned by BRATHAMATU. The stock taker:
- Completes onboarding, identity checks and required training.
- Sets skills, availability and service areas.
- Accepts an assignment and its disclosed compensation.
- Acknowledges the job-specific SOP and confidentiality/safety requirements.
- Counts only assigned catalogue products and locations.
- Submits physical quantities and permitted observations/evidence.
- Reports unknown products, barcode problems, damage, expiry and other exceptions.
- Does not edit catalogue records, expected quantities, product identity, tenant prices or job financial terms.
- Responds to authorised correction/recount requests.
- Receives payout only after the applicable acceptance and authorisation process.

## 1.2 Marketplace transaction lifecycle

1. Tenant registers and creates its organisation.
2. Tenant configures branches, warehouses and stock locations.
3. Tenant uploads or creates its catalogue.
4. System validates catalogue data, duplicates, units, variants and barcodes.
5. Tenant confirms the catalogue declaration. The platform stores the declaration, user, time, catalogue version and import provenance.
6. Tenant creates a stock-taking request or recurring schedule.
7. Tenant chooses scope, method, timing, locations, products, required procedures and service requirements.
8. System produces a preliminary estimate using the active pricing policy.
9. Tenant pays the required deposit before formal job submission/commissioning proceeds. The exact amount is determined by configured deposit policy.
10. BRATHAMATU reviews catalogue readiness, scope, complexity, schedule, safety, staffing, travel and special requirements.
11. BRATHAMATU issues the final quote, separately recording tenant charge, tax/fees, expenses and stock-taker compensation.
12. Tenant accepts the quote and pays the required balance or satisfies the approved payment terms.
13. BRATHAMATU verifies payment and approves the job for execution.
14. BRATHAMATU assigns a suitable stock taker or team.
15. Stock taker accepts the disclosed assignment and acknowledges the versioned SOP.
16. Work is performed using the approved catalogue snapshot and stock cut-off rules.
17. Count entries are saved, submitted and locked as revisions.
18. Platform QA and/or supervisor verifies completeness and anomalies. Independent sample counts and recounts may be required.
19. BRATHAMATU submits the report to the tenant.
20. Tenant reviews results and accepts, requests correction/recount or disputes.
21. BRATHAMATU resolves outstanding issues.
22. Tenant confirms successful completion or an authorised resolution is recorded.
23. BRATHAMATU finance authorises the stock-taker payout.
24. Payout is processed and reconciled.
25. Job is closed; reports, evidence, financial records and audit history remain available under retention policy.

**Critical distinction:** expected-versus-counted variance is not automatically stock-taker inaccuracy. If the catalogue/inventory snapshot expected 100 and the worker accurately counts 92, the 8-unit shortage is a tenant inventory variance. Worker accuracy must be assessed against an independently verified physical count, not merely against expected stock.

## 1.3 Financial confidentiality

- Stock taker sees their agreed compensation, approved expenses/reimbursements and payout status only.
- Stock taker must not see tenant invoice amount, platform margin, pricing formula, tenant discounts or platform financial reports.
- Tenant sees its own quote, invoice, deposit, balance, authorised fees and payment history.
- Tenant does not see internal worker compensation or BRATHAMATU margin.
- BRATHAMATU authorised finance/administration roles can see both sides and margin.
- All access to sensitive financial details must be permission-checked and audited.
- Job financial terms become locked at the approved commercial milestone. Amendments require authorised reason, version history and tenant consent where the change affects tenant obligations.

## 1.4 Handling funds and accounting

Implement a proper double-entry financial ledger. Distinguish:
- Tenant deposits and payments.
- Amounts allocated to a job.
- Amounts due to stock takers.
- Platform fees and recognised revenue.
- Taxes and statutory liabilities.
- Refunds, credits, chargebacks and payment reversals.
- Worker expenses and approved reimbursements.
- Payout liabilities and payout settlements.
- Unallocated and unmatched receipts.

Do not treat a database field named `escrow` as legally regulated escrow. Before launch, BRATHAMATU must obtain Kenyan legal/accounting advice on collection, holding, settlement, marketplace obligations, tax treatment and any payment-service licensing implications. Use a licensed payment provider and documented settlement arrangement.

---

# 2. USER TYPES, ROLES AND PERMISSIONS

Use RBAC plus resource policies and tenant scoping. A user may hold more than one role, but permissions must be explicitly granted and scoped. Avoid a single generic dashboard for all users.

## 2.1 Platform roles

| Role | Main responsibilities |
|---|---|
| Platform Super Admin | Full platform control, configuration, security, all tenants, users, financial visibility and emergency access. Every sensitive action audited. |
| Platform Owner / Director | Executive dashboard, commercial policy, performance, approvals and high-level financial reporting. |
| Platform Operations Manager | Tenant requests, job review, scheduling, assignments, workforce operations and service quality. |
| Job Reviewer | Review catalogue, scope, quote requests and job readiness within assigned authority. |
| Workforce Manager | Stock-taker onboarding, verification, skills, availability, assignment and performance. |
| Stock-taking Supervisor | Field oversight, job progress, evidence, QA, recounts and completion checks. |
| Platform Finance Manager | Quotes, invoices, ledger, reconciliation, refunds and payout authorisation within limits. |
| Finance Officer | Routine payment matching, receipts, job allocations and payout preparation; no unauthorised high-value approval. |
| Support Manager | Tickets, escalations, complaints and dispute coordination. |
| Support Agent | Limited support access, no unrestricted financial or clinical/sensitive data. |
| Auditor / Compliance Officer | Read-only access to approved audit, job and finance records; exports logged. |
| Integration Administrator | External POS/ERP connections, API credentials, webhooks and sync operations. |
| Content / Marketing Manager | Public content, service pages, announcements and permitted campaigns. |
| Read-only Executive | Approved aggregate dashboards without operational write access. |

## 2.2 Tenant roles

| Role | Main responsibilities |
|---|---|
| Business Owner | Tenant-wide settings, billing, branch oversight, users, schedules and final approvals. |
| Business Administrator | Organisation configuration, catalogue, users, requests and reports within granted scope. |
| Business Finance Manager | Quotes, invoices, payments, deposits, refunds/credits and tenant financial reports. |
| Branch Manager | Own branch operations, stock-taking requests, branch catalogue/location oversight and branch reports. |
| Inventory Manager | Catalogue, product/variant/unit data, inventory snapshots, stock movement and reconciliation. |
| Stock Controller | Inventory operations and authorised count review. |
| Department / Warehouse Manager | Only assigned departments, warehouses or locations. |
| Job Requester | Create draft requests and submit only within permission/approval policy. |
| Results Reviewer | Review reports, accept work, request recount or raise a dispute. |
| Auditor (Tenant) | Read-only access to approved records and reports. |
| POS Manager | BRATHAMATU POS configuration, sales, shifts, products and reports within branch scope. |
| POS Cashier | POS transactions and assigned shift operations only. |
| Tenant Read-only User | Explicitly permitted read-only data. |

## 2.3 Workforce roles

| Role | Main responsibilities |
|---|---|
| Applicant | Complete registration and verification requirements. |
| Trainee Stock Taker | Training and supervised/low-risk work only. |
| Verified Stock Taker | Eligible for approved assignments within skill and trust limits. |
| Professional Stock Taker | Higher complexity/volume assignments subject to performance. |
| Senior Stock Taker | Complex jobs, mentoring and possible team leadership. |
| Stock-taking Supervisor | Supervisor functions only when separately authorised for a job. |
| Agency Manager | Manage agency profile and eligible team members; cannot see tenant confidential margin. |
| Agency Stock Taker | Work assigned through an approved agency arrangement. |

## 2.4 Permission design

Use permissions such as:
- `tenant.view`, `tenant.update`, `tenant.users.manage`
- `branch.view`, `branch.create`, `branch.update`
- `catalogue.view`, `catalogue.create`, `catalogue.update`, `catalogue.import`, `catalogue.confirm`
- `catalogue.snapshot.view`
- `stocktake.schedule.manage`
- `stocktake.request.create`, `stocktake.request.submit`, `stocktake.request.cancel`
- `stocktake.quote.view`, `stocktake.quote.accept`
- `stocktake.job.review`, `stocktake.job.approve`, `stocktake.job.assign`, `stocktake.job.monitor`
- `stocktake.count.view`, `stocktake.count.submit`
- `stocktake.count.recount.request`, `stocktake.results.review`, `stocktake.results.accept`
- `stocktake.dispute.create`, `stocktake.dispute.resolve`
- `finance.invoice.view`, `finance.payment.record`, `finance.refund.approve`
- `finance.ledger.view`, `finance.payout.prepare`, `finance.payout.approve`
- `pos.sales.create`, `pos.refund.create`, `pos.shift.open`, `pos.shift.close`
- `report.view`, `report.export`
- `audit.view`, `settings.manage`, `integration.manage`

Permissions must be further restricted by tenant, branch, department, assigned job, resource ownership and financial approval limit.

## 2.5 Role dashboards and navigation

Build separate role-specific dashboards:
- Platform operations dashboard: job pipeline, pending reviews, staffing gaps, active jobs, deadlines, exceptions, tenant service levels.
- Platform finance dashboard: deposits, verified receipts, outstanding balances, payout queue, ledger exceptions, reconciliation and cash-flow reports.
- Workforce dashboard: applicants, verification queue, availability, assignments, training, performance and payouts.
- Tenant owner dashboard: stock-taking overview, branches, schedules, catalogue readiness, quotes, payments, reports and team activity.
- Inventory dashboard: catalogue health, imports, stock locations, expected balances, variances and reconciliation.
- Branch dashboard: local stock-taking status, team, upcoming schedules, reports and exceptions.
- Stock-taker dashboard: availability, assigned jobs, SOP, count workspace, tasks, evidence, deadlines, earnings and payout history.
- POS dashboard: sales, shifts, products, stock, purchases, suppliers, returns and stock-taking service access.
- Support dashboard: tickets, assigned disputes, escalations, service-level targets and communication history.

---

# 3. TENANCY AND ORGANISATION STRUCTURE

## 3.1 Tenant model

A tenant represents a business/legal organisation or an approved operating account. A tenant may own multiple branches. A parent organisation may optionally group multiple tenant entities where commercial/legal separation is required.

Recommended hierarchy:

`Platform -> Tenant / Organisation -> Branch -> Department -> Warehouse / Stock Location -> Zone / Shelf / Bin`

Do not assume every business needs every level. Allow optional structures and configurable labels.

## 3.2 Tenant isolation requirements

- Every tenant-owned database row must have `tenant_id` directly or be reachable through a tenant-owned parent with an enforced policy.
- Tenant context must be resolved server-side from authenticated membership and route/resource context, never trusted from a client-supplied tenant ID alone.
- Eloquent global scopes may be used as a defence-in-depth measure, but must not be the only security control.
- Use policies, service-level tenant checks and query scoping.
- Tenant-owned file paths, exports, search documents, cache keys, queues and broadcasts must be tenant-scoped.
- Background jobs must carry explicit tenant context and revalidate access/ownership.
- Prevent insecure direct object references across tenants.
- Super-admin cross-tenant access must be permissioned, reasoned and audited.
- Create automated tests proving Tenant A cannot read, update, export, search or infer Tenant B data.
- Avoid exposing sequential IDs where they could enable enumeration; use UUID/ULID public identifiers where appropriate.

## 3.3 Tenant onboarding

1. Register owner account.
2. Verify email and phone.
3. Create organisation profile: legal/trading name, business type, registration/tax details where appropriate, address, industry, contact and timezone.
4. Accept terms, privacy and service agreement.
5. Create branches and locations or import them.
6. Invite users and assign roles.
7. Select BRATHAMATU POS, external POS/ERP or manual/spreadsheet operating mode.
8. Upload/create catalogue.
9. Resolve validation errors.
10. Confirm catalogue declaration.
11. Configure stock movement cut-off and counting preferences.
12. Complete onboarding readiness checklist.
13. Enable stock-taking request creation when mandatory requirements are met.

---

# 4. CATALOGUE AND PRODUCT MASTER DATA

## 4.1 Catalogue as source of truth

A tenant must provide a catalogue during onboarding before it can commission a stock-taking job. The catalogue is the authoritative reference for product identity and scope. Each job uses an immutable catalogue version/snapshot.

A catalogue may originate from:
- Excel import (`.xlsx`).
- CSV import.
- Manual product entry by authorised tenant users.
- BRATHAMATU POS.
- External POS/ERP API integration.
- Approved bulk data integration.

## 4.2 Product data model

Minimum product fields:
- Tenant ID.
- Catalogue ID and version.
- Internal product UUID/ULID.
- Product name.
- SKU / item code.
- Barcode(s), including barcode type and uniqueness scope.
- Brand.
- Category and subcategory.
- Product description.
- Variant attributes (size, weight, colour, flavour, model, etc.).
- Base unit of measure.
- Packaging unit and conversion factor.
- Product image/media reference.
- Active/inactive/discontinued status.
- Tracking mode: quantity, batch, serial, expiry, or combination.
- Optional supplier reference.
- Optional cost/valuation fields with restricted permissions.
- Created/updated by and timestamps.
- Source system and external reference.

Do not conflate product variants, packaging and units. Example: Product B 500g is one sellable variant/SKU. Product B 1kg may be a separate variant/SKU. A carton containing 12 pieces is a packaging conversion, not automatically a different product identity.

## 4.3 Units and conversions

Support configurable units:
- Piece, each, unit.
- Pack, box, carton, case.
- Kilogram, gram, litre, millilitre.
- Metre, roll, bundle.
- Other tenant-defined units.

Store conversion definitions explicitly. Never silently assume one carton equals a particular number of pieces. A count may be captured in a permitted package unit and converted by a verified conversion rule. Preserve the original entered quantity and unit as well as the normalized quantity.

## 4.4 Catalogue import workflow

1. Upload file to private temporary storage.
2. Validate extension, MIME, size and malware policy.
3. Parse in a queued job; do not block the browser for large imports.
4. Map source columns to canonical fields.
5. Preview sample rows and mapping.
6. Validate required fields, SKU/barcode collisions, units, variants and duplicate candidates.
7. Show row-level errors with downloadable error report.
8. Allow authorised user to correct source/mapping and retry.
9. Import valid rows according to an explicit partial/all-or-nothing policy selected for the import.
10. Create a new catalogue version.
11. Show import summary and audit trail.
12. Require tenant confirmation of catalogue accuracy before commissioning.
13. Keep original file provenance and import history under retention policy.

Never silently merge ambiguous duplicate products. Provide duplicate resolution tools and preserve old identifiers/aliases when approved.

## 4.5 Catalogue declaration

Capture:
- Declaring user.
- Tenant.
- Catalogue version.
- Declaration text/version.
- Timestamp and IP/device metadata as policy allows.
- Uploaded file/import ID.
- Confirmation that products, variants, SKUs, barcodes, units, branches and locations are accurate to the tenant's knowledge.
- Any exceptions/known limitations.
- Acceptance of responsibility for maintaining catalogue accuracy.

## 4.6 Catalogue pricing

Create `catalogue_pricing_policies` and versioned pricing rules. Preserve the proposed points:
- KSh 100 for a catalogue of 100 products.
- KSh 2,000 for catalogues up to 6,000 products.
- Maximum catalogue charge of KSh 2,000, including catalogues above 6,000 products.

The calculation between these points remains unconfirmed. Admin must configure:
- Pricing model: flat, per-product, banded, minimum, capped, promotional or negotiated.
- Effective date/version.
- Product-count definition (active products, variants, sellable SKUs, etc.).
- Minimum and maximum charge.
- Currency.
- Taxes/fees.
- Discounts/waivers and authorisation.
- Repricing rules for future catalogue updates.
- Invoice and payment reference.

Catalogue registration fee is distinct from stock-taking job revenue. Do not charge again for an unchanged catalogue unless an approved policy says so. Record each priced catalogue version and invoice.

---

# 5. STOCK-TAKING METHODS AND COUNT ENGINE

## 5.1 Supported count methods

- Full physical stock take.
- Cycle count.
- Spot check.
- Blind count.
- Guided count.
- Double count / independent verification.
- Recount.
- Zone/section count.
- Batch/lot count.
- Serialised item count.
- Expiry-focused count.
- Damage/condition inspection.

A job may combine methods by branch, location, category or product class.

## 5.2 Stock-taker restricted workspace

The count screen must show only what is required:
- Job instructions and SOP.
- Assigned branch/location/zone.
- Read-only product identity: name, image, SKU, barcode, variant and approved unit.
- Search and barcode scanning.
- Physical quantity input.
- Permitted package/unit selection from catalogue-approved conversions.
- Count condition flags: good, damaged, expired, quarantined, missing label, unknown item, inaccessible, etc.
- Evidence capture when required.
- Notes and exception reporting.
- Save progress and sync status.

Stock takers cannot:
- Change product names, SKUs, barcodes, categories or variants.
- Create, edit or delete catalogue products.
- Change expected stock or stock ledger.
- Change unit conversions or packaging definitions.
- Edit tenant prices/costs.
- Change the job scope or financial terms.
- Approve their own recount or final results.

## 5.3 Unknown products and catalogue exceptions

If a worker finds an unlisted product, mismatched barcode, wrong label, unexpected variant or packaging issue:
1. Do not allow worker to create/edit the catalogue product.
2. Create an exception record.
3. Capture barcode if present, photo, description, location, observed unit and notes.
4. Notify authorised tenant inventory manager and BRATHAMATU supervisor.
5. Allow authorised tenant user to resolve/add product through catalogue workflow.
6. If the job snapshot changes, create a formally approved snapshot amendment or a separate supplemental count scope. Preserve the original snapshot and history.

## 5.4 Count capture and revision rules

- Count sessions are tied to a job, assignment, catalogue snapshot and location.
- Each count entry records product/variant, location, raw quantity, raw unit, normalized quantity, worker, device/session, timestamp and evidence references.
- Support autosave and explicit submission.
- Preserve original submissions; corrections create revisions with reason, actor and timestamp.
- Prevent duplicate counts unless the method explicitly permits multiple independent counts.
- Record offline capture time and server sync time separately.
- Resolve sync conflicts through explicit rules; never silently overwrite a newer server record.
- Store a unique idempotency key for offline submissions.
- Use decimal-safe quantity types where fractional units are permitted; define precision per unit.
- Validate negative quantities and impossible values; require privileged exception workflows for unusual values.
- Lock submitted entries at the relevant workflow milestone. Reopening requires permission and reason.

## 5.5 Blind counting

Blind count should be the default for physical stock-taking where appropriate. Stock taker sees product identity and count unit but not expected quantity or valuation. Guided count may be enabled by authorised job configuration. The selected mode must be explicit in the job SOP and audit log.

## 5.6 Evidence

Evidence may include:
- Product/shelf/location photographs.
- Barcode images/scans.
- Damaged/expired product photographs.
- Signatures or acknowledgements.
- Site check-in/out evidence.
- Supervisor verification.
- Exception documents.

Evidence files must be private, access-controlled, tenant/job scoped, virus-checked where practical, and delivered through short-lived signed URLs. Avoid unnecessary personal data in photographs.

## 5.7 Offline-first counting

The field count interface should support limited offline operation:
- Download authorised job scope, product identity, approved units and SOP to a protected local cache.
- Encrypt local sensitive data where supported.
- Queue count submissions and evidence metadata with unique IDs.
- Display unsynced/syncing/synced/failed states.
- Retry safely and idempotently.
- Detect server-side changes and conflicts.
- Expire local job access after completion/revocation.
- Provide clear warning that offline changes are not submitted until sync completes.
- Do not cache tenant financial data or unrelated tenant records.

Offline capability may be delivered in a later phase, but database/API contracts must allow it.

---

# 6. STOCK-TAKING JOBS, QUOTATIONS AND APPROVALS

## 6.1 Job request fields

- Tenant and requesting user.
- Request reference.
- Job title and description.
- Branches, warehouses, departments, zones and locations.
- Product/category/variant scope.
- Catalogue version and snapshot.
- Count method and expected duration.
- Preferred start date/time and deadline.
- Priority and business criticality.
- Industry-specific requirements.
- SOP template/version and custom instructions.
- Safety/access requirements.
- Required team size, skills and qualifications.
- Expected stock movement/cut-off arrangement.
- Required evidence and sign-offs.
- Deposit/payment status.
- Attachments.
- Tenant approval status.
- Audit history.

## 6.2 Job review and quotation

BRATHAMATU review considers:
- Catalogue completeness and quality.
- Number of products/SKUs and locations.
- Branch count and geographic distance.
- Complexity, unit conversions, batches/serials/expiry.
- Count method, double count and QA sampling.
- Working hours, access and safety.
- Required worker number and skill level.
- Travel, accommodation and approved expenses.
- Deadline and priority.
- Historical job complexity and service policy.

Quote stores separately:
- Tenant base service charge.
- Scope/complexity charges.
- Travel and other approved expenses.
- Discounts.
- Taxes and statutory fees.
- Total tenant charge.
- Deposit required and received.
- Balance due and due date.
- Worker compensation budget and individual compensation.
- Platform fee/margin calculation.
- Quote version, validity, approver and timestamps.

The quote must not expose worker compensation to the tenant or tenant invoice to the worker.

## 6.3 Deposit

A deposit is required before a tenant's stock-taking job request proceeds to formal commissioning/review, according to configured policy. Support:
- Fixed or percentage deposits.
- Minimum/maximum amounts.
- Job-type/tenant-specific policies.
- Deposit deadline.
- Payment retry and expiry.
- Deposit allocation to final invoice.
- Cancellation/refund policy.
- Partial payment where policy permits.
- Deposit receipt and reconciliation.
- Deposit transfer to amended job only with authorised audit record.

Deposit is credited against the final job amount, not an additional fee, unless the quote explicitly identifies a separate non-refundable charge and that treatment is legally reviewed and disclosed.

## 6.4 Job status model

Use explicit status transitions. Suggested states:
- `draft`
- `awaiting_deposit`
- `deposit_pending_verification`
- `submitted`
- `under_review`
- `needs_tenant_information`
- `quote_issued`
- `awaiting_quote_acceptance`
- `awaiting_balance_payment`
- `payment_pending_verification`
- `approved_for_execution`
- `awaiting_assignment`
- `assigned`
- `awaiting_worker_acceptance`
- `scheduled`
- `in_progress`
- `paused`
- `count_submitted`
- `quality_review`
- `recount_required`
- `awaiting_tenant_review`
- `tenant_correction_requested`
- `disputed`
- `completion_confirmed`
- `payout_pending_authorisation`
- `payout_processing`
- `payout_completed`
- `closed`
- `cancelled`
- `refunded`
- `rejected`

Do not permit arbitrary status edits. Use a workflow transition service that validates actor, current state, target state, required data and audit reason.

## 6.5 Job amendments and cancellation

- Scope/date/team changes after approval require a versioned amendment.
- Financial impact must be recalculated and approved.
- Tenant consent required where tenant price, scope or obligation changes.
- Worker consent required where assignment terms/compensation materially change.
- Preserve old quote, SOP, scope and snapshot.
- Cancellation policy defines fees, worker compensation for completed work, refund and expense treatment.
- Every cancellation records actor, reason, timestamp and financial outcome.

---

# 7. RECURRING AND ON-DEMAND STOCK-TAKING

This is a core product capability. A business may request a stock take whenever needed, every month, every year or on another schedule.

## 7.1 Schedule frequencies

Support:
- On demand / one-time.
- Weekly.
- Fortnightly.
- Monthly.
- Every two months.
- Quarterly.
- Half-yearly.
- Annually.
- Custom interval.
- Specific date list.
- Financial year-end or business-defined period.
- Category/location-specific cycles.

Schedules support timezone, local preferred time window, start date, end date, next due date, recurrence rule, blackout dates, holidays, grace periods, reminders and pause/resume.

## 7.2 Multiple schedules per tenant

A single business may create multiple schedules with different scopes. Example:
- Main warehouse: monthly.
- Perishable goods: weekly.
- Electronics: quarterly.
- All branches: annual full stock take.
- Unexpected shortage investigation: on demand.

Each schedule defines branches, locations, product scope, count method, SOP, preferred time, deadline, budget/approval settings, contact person and recurrence.

## 7.3 Three commercial schedule modes

Support all three as policy-controlled options:
1. **Reminder-only:** notify the tenant when stock-taking is due; tenant creates and pays for each job.
2. **Proposed job generation:** generate a proposed request for tenant review; tenant explicitly approves and pays deposit before commissioning.
3. **Prepaid/contracted service:** schedule under a separately accepted service agreement with authorised billing and cancellation terms. No charge beyond the agreement's authority.

Never silently charge a tenant or create a paid job from a reminder. Record consent, agreement version, authorised limits and billing authority.

## 7.4 Occurrence lifecycle

- Upcoming.
- Reminder sent.
- Due.
- Proposal generated.
- Awaiting tenant authorisation.
- Request created.
- Deposit pending.
- Commissioned.
- Skipped by tenant.
- Rescheduled.
- Missed/overdue.
- Cancelled.
- Completed.

A schedule occurrence is not itself a job. It may produce a proposed request or link to a commissioned job. Keep recurrence history even when jobs are cancelled or skipped.

## 7.5 Schedule notifications and operations

- Configurable advance reminders (e.g. 30, 14, 7, 1 days; values configurable).
- Notify tenant owner and selected branch/inventory contacts.
- Notify BRATHAMATU operations of expected demand.
- Generate staffing forecast from future schedules.
- Allow tenant to reschedule, skip or pause within policy.
- Track missed schedules and overdue stock takes.
- Show upcoming demand in platform operations dashboard.
- Prevent duplicate occurrences with unique schedule/occurrence keys.
- Handle month-end dates, leap years, timezone/DST rules and recurrence changes safely.

## 7.6 Suggested schedule tables

- `stocktaking_schedules`
- `stocktaking_schedule_scopes`
- `stocktaking_schedule_occurrences`
- `stocktaking_schedule_notifications`
- `stocktaking_schedule_agreements`
- `stocktaking_schedule_history`

---

# 8. STOCK-TAKER WORKFORCE AND PERFORMANCE

## 8.1 Onboarding

Capture only necessary information:
- Legal name and contact details.
- Email and verified phone.
- Identity verification status and restricted verification references.
- Profile photograph if justified.
- Location/service areas.
- Skills, industries and experience.
- Qualifications/certificates.
- Languages.
- Availability and preferred work times.
- Transport/travel capability if relevant.
- Emergency contact only where operationally justified and protected.
- Tax/payment compliance details where required.
- M-Pesa or bank payout method, verified and access-restricted.
- Training completion and terms acceptance.
- References/background checks where lawful and proportionate.

Sensitive identity and payout data must be encrypted/restricted and retained only as long as necessary.

## 8.2 Assignment matching

Recommend stock takers using:
- Availability and schedule.
- Skills and qualifications.
- Industry experience.
- Geography/travel feasibility.
- Job complexity.
- Performance and quality confidence.
- Workload and fatigue safeguards.
- Language and customer requirements.
- Agency/team arrangement.
- Conflict of interest and prior tenant feedback.

Final assignment remains subject to BRATHAMATU authority. Record recommendation reasons and human override.

## 8.3 Worker progression

Suggested levels:
- Applicant.
- Trainee.
- Verified.
- Professional.
- Expert.
- Senior / Supervisor.

Progression is governed by configurable criteria, completed verified jobs, independent accuracy, procedure compliance, reliability, customer feedback, training and disciplinary status. Do not rank workers on raw expected-stock variance.

## 8.4 Fair performance metrics

Use:
- Verified count accuracy against independent recount/sample.
- Completion and deadline performance.
- SOP/procedure compliance.
- Evidence compliance.
- Recount rate attributable to verified worker error.
- Tenant feedback with moderation.
- Dispute rate and outcomes.
- Number of verified jobs and sample confidence.
- Attendance/reliability.
- Training/certification status.

Display confidence/sample size. Avoid allowing a single review or one small job to dominate a worker's score. Keep an appeal/correction process.

## 8.5 Tenant reviews

Reviews only after eligible completed jobs. Suggested dimensions:
- Professionalism.
- Punctuality.
- Communication.
- Attention to detail.
- Procedure compliance.
- Overall service quality.

Prevent duplicate, retaliatory or unrelated reviews. Allow moderation and dispute/appeal. Do not expose private tenant comments beyond authorised users.

---

# 9. QUALITY ASSURANCE, VERIFICATION AND RECONCILIATION

## 9.1 QA controls

- Required-field and duplicate validation.
- Location/product scope completeness.
- Barcode and unit validation.
- Unusual quantity/anomaly detection.
- Evidence requirement validation.
- Time and movement checks.
- Supervisor review.
- Risk-based independent sample counts.
- Double count for high-risk/high-value products.
- Mandatory recount for defined conditions.
- Audit of every QA decision.

## 9.2 Independent verification

Independent count records must identify verifier, product, location, method, raw/normalized quantity, timestamp and evidence. Compare original count with independent count using configured tolerances. A mismatch triggers investigation, not automatic blame.

## 9.3 Discrepancy types

- Shortage.
- Surplus.
- Damaged.
- Expired.
- Missing.
- Unknown/unlisted product.
- Wrong location.
- Barcode mismatch.
- Unit/packaging mismatch.
- Batch/serial mismatch.
- Inaccessible/unverified.
- Count disagreement.
- Stock movement during count.
- Catalogue error.

## 9.4 Stock cut-off and movement control

Before counting, establish an agreed cut-off timestamp and process for sales, purchases, transfers, returns and production movements. Support:
- Freeze movement where operationally possible.
- Controlled movement log during count.
- Snapshot expected inventory at cut-off.
- Record movement transactions during count.
- Calculate expected balance at count time.
- Reconcile post-cut-off movement transparently.

For BRATHAMATU POS, use sale/purchase/transfer event data. For external POS, support API or uploaded movement logs. For manual businesses, provide signed movement log templates and supervised cut-off process.

## 9.5 Reconciliation

Produce:
- Expected quantity.
- Verified physical quantity.
- Variance quantity and percentage.
- Approved unit cost/valuation where accessible.
- Variance value.
- Damage/expiry categories.
- Root cause and notes.
- Proposed adjustment.
- Approver and approval date.
- Adjustment reference and ledger linkage.

Stock takers cannot approve inventory adjustments. Tenant-authorised inventory/finance roles approve changes. Preserve original snapshots and before/after values.

## 9.6 Recount responsibility

Build a configurable investigation outcome:
- Worker error confirmed: compensation/cost allocation may be charged to worker only according to fair, disclosed contract and applicable law.
- Tenant requested additional count outside agreed scope: tenant may be charged.
- Catalogue or tenant data issue: tenant/BRATHAMATU allocation per contract.
- BRATHAMATU process/assignment failure: platform bears cost.
- Unclear or shared responsibility: split/waive/escalate.

Do not automatically deduct disputed costs from worker earnings. Require evidence, reason, authorised approval, worker notice and appeal. Avoid unlawful wage deductions.

---

# 10. TENANT RESULTS REVIEW AND DISPUTES

## 10.1 Tenant review

After BRATHAMATU QA, publish results to authorised tenant reviewers. Provide:
- Summary and detailed product/location results.
- Exceptions and evidence.
- Reconciliation report.
- Review deadline.
- Accept completion.
- Request correction.
- Request recount.
- Raise dispute.
- Add comments and attachments.
- Download reports.

A configurable review window may be used (example 48 hours), with reminders. Silence should escalate to BRATHAMATU under policy; do not automatically treat silence as acceptance for every contract.

## 10.2 Dispute workflow

Statuses:
- Open.
- Evidence requested.
- Under investigation.
- Recount authorised.
- Awaiting tenant response.
- Awaiting worker response.
- Resolution proposed.
- Accepted.
- Escalated.
- Resolved.
- Closed.

Possible resolutions:
- Accept original results.
- Correct clerical error.
- Partial/full recount.
- Reassign verification.
- Adjust job fee.
- Partial/full refund.
- Partial/full worker payout.
- Platform absorbs cost.
- Split cost.
- Reject unsupported claim.

Store evidence, communications, decision rationale, approver, financial effect and appeal outcome.

---

# 11. FINANCE, PAYMENTS, LEDGER AND PAYOUTS

## 11.1 Payment methods

Design provider adapters for:
- M-Pesa Daraja STK Push and C2B/Paybill/Till flows as applicable.
- Card gateway.
- Bank transfer.
- Manual bank deposit confirmation by authorised finance user.
- Future providers.

Do not hardcode provider credentials. Use environment secrets or secret manager. Separate sandbox and production. Validate callback authenticity, amount, currency, merchant reference and transaction status.

## 11.2 Payment reliability

- Idempotent payment initiation and callback processing.
- Unique provider transaction identifiers.
- Unique internal payment references.
- Signature/token validation where provider supports it.
- Duplicate callback protection.
- Retry-safe processing.
- Reconciliation against provider statements.
- Pending/failed/reversed states.
- No job activation solely on a browser redirect.
- Server-side confirmation from trusted provider callback or verified finance action.
- Record raw callback safely with secrets/PII redacted and retention controls.

## 11.3 Double-entry ledger

Create immutable journal entries with:
- Journal ID and reference.
- Transaction date and posting date.
- Currency.
- Debit and credit accounts.
- Amount.
- Source type/source ID.
- Tenant/job linkage where applicable.
- Description and metadata.
- Created by/system actor.
- Reversal reference where applicable.

Every journal must balance per currency. Correct mistakes through reversal and replacement, not destructive editing.

## 11.4 Finance accounts (illustrative chart)

- Cash / payment provider clearing.
- Bank clearing.
- M-Pesa clearing.
- Card clearing.
- Tenant receivables.
- Tenant deposits / customer funds liability.
- Job funds payable/held liability (subject to legal/accounting design).
- Stock-taker payable.
- Worker expense payable.
- Platform service revenue.
- Catalogue revenue.
- POS revenue.
- Tax payable.
- Refund liability.
- Chargeback/settlement clearing.
- Platform operating expense.

Final chart and recognition rules require accountant approval.

## 11.5 Payout controls

- Stock taker sees their own approved earnings and payout status.
- Payout method must be verified.
- Payout request/queue generated only after completion conditions.
- Finance officer prepares; authorised manager approves according to limits.
- Maker-checker for high-value or risk-triggered payouts.
- Duplicate payout prevention.
- Payout provider reference and receipt.
- Failed payout retry and reconciliation.
- Reversal/adjustment records.
- No self-approval.
- Payout audit and downloadable statement.

## 11.6 Refunds, credits and cancellations

Support full/partial refunds, credits, deposit treatment, cancellation fees, chargebacks and reversals. Every refund requires reason, original payment link, approval limit, payment provider outcome and ledger posting.

## 11.7 Financial reports

- Daily collections.
- Deposits received/outstanding.
- Job revenue.
- Catalogue fees.
- Tenant receivables.
- Worker compensation and payables.
- Payout ageing.
- Refunds and chargebacks.
- Platform margin.
- Taxes.
- Payment provider reconciliation.
- Bank/M-Pesa/card clearing.
- Profit and loss and balance sheet reports subject to accountant-approved setup.
- Tenant statement and worker earnings statement.

---

# 12. BRATHAMATU POS — INTEGRATED PRODUCT

BRATHAMATU POS is a major product integrated with stock-taking, not a decorative add-on.

## 12.1 POS modules

- Business/branch setup.
- Terminals and devices.
- Products, variants, barcodes and units.
- Sales and sale items.
- Receipts/invoices.
- Customers.
- Suppliers.
- Purchases and goods received.
- Inventory balances and movements.
- Returns/refunds.
- Discounts/promotions.
- Cash drawer and shifts.
- Cash, M-Pesa and card payments.
- Multi-branch transfers.
- Staff and granular POS permissions.
- Daily closing and cash reconciliation.
- Reports and audit history.
- Offline-capable POS architecture where required.

## 12.2 BRATHAMATU POS and stock-taking

A BRATHAMATU POS tenant can:
- View catalogue readiness.
- Commission stock-taking.
- Select branches and locations.
- Select full or cycle count.
- Choose schedule.
- View estimates/quotes and pay deposits.
- Track assigned workers and progress.
- Review discrepancies and reports.
- Request recounts and approve completion.

Using BRATHAMATU POS makes service commissioning easier, but must not automatically create a paid stock-taking job. Tenant must explicitly authorise the service and charges unless covered by a valid prepaid agreement.

## 12.3 Commercial relationship

Support configurable policies:
- POS users can request stock-taking, jobs billed separately.
- POS users receive preferential stock-taking rates.
- Selected POS subscription plans include service access/allowance, with any excess jobs billed separately.

Do not treat inclusion of access as unlimited free labour. Clearly define job allowance, limits, rollover, branches, overage and expiry in plan terms.

## 12.4 External POS/ERP support

Businesses without BRATHAMATU POS must be supported through:
- Excel/CSV imports.
- Manual catalogue and movement uploads.
- REST API.
- Webhooks.
- Scheduled integrations where available.

Integration requirements:
- Encrypted credentials.
- Least-privilege access.
- Connection health.
- Sync status and timestamps.
- Field mapping.
- Duplicate handling.
- Retry and dead-letter queues.
- Conflict resolution.
- Audit logs.
- Tenant-controlled disconnect/revocation.
- Data export and deletion policy.

---

# 13. REPORTING, ANALYTICS AND PLATFORM INTELLIGENCE

## 13.1 Tenant reports

- Stock take summary.
- Product-level count.
- Branch comparison.
- Warehouse/location comparison.
- Expected versus physical quantities.
- Shortage/surplus.
- Damaged/expired/missing items.
- Batch/serial/expiry reports.
- Valuation where permitted.
- Count completion and exceptions.
- Reconciliation and approved adjustments.
- Historical trend.
- Stock-taker service report.
- Schedule compliance.
- POS inventory movement.

## 13.2 Platform reports

- Tenant growth and activity.
- Job pipeline and completion.
- Revenue and margin.
- Deposits and outstanding balances.
- Worker supply, availability and utilisation.
- Worker accuracy and quality confidence.
- Disputes, recounts and refunds.
- Branch/industry demand.
- SLA performance.
- Payment reconciliation.
- Integration health.
- Security and audit events.

## 13.3 Export formats

Support PDF, Excel and CSV with:
- Tenant branding where permitted.
- Report filters and generation timestamp.
- Data scope and catalogue snapshot version.
- Access control and export audit.
- Background generation for large reports.
- Expiring private download links.

## 13.4 AI and analytics

Future-ready interfaces for:
- Anomaly detection.
- Count pattern analysis.
- Unusual stock movement.
- Staffing recommendations.
- Demand forecasting.
- Natural-language report summaries.
- Duplicate/poor catalogue detection.

AI must not independently approve financial transactions, alter catalogue master data, change count records or make final worker disciplinary decisions. Human review and traceable explanations are required.

---

# 14. COMMUNICATION, NOTIFICATIONS AND SUPPORT

## 14.1 Channels

- In-app notifications.
- Email.
- SMS.
- WhatsApp through approved provider and consent.
- Realtime in-app updates.

## 14.2 Events

Notify relevant users for:
- Account verification and invitations.
- Catalogue import results and confirmation.
- Catalogue fee and payment.
- Schedule reminders and overdue events.
- Job submission, review and quotation.
- Deposit/balance payment.
- Job approval and assignment.
- Worker acceptance/rejection.
- Start, progress and delay.
- SOP updates.
- QA issues and recounts.
- Results ready and tenant review deadline.
- Dispute updates.
- Completion confirmation.
- Payout authorisation and payment.
- Refunds and support ticket changes.

Notifications must be preference-aware, consent-aware, deduplicated and logged. Avoid leaking confidential information in SMS/email previews.

## 14.3 Support and ticketing

- Tenant support tickets.
- Worker support tickets.
- Payment issues.
- Catalogue import assistance.
- Job scheduling/assignment issues.
- Count and quality disputes.
- POS support.
- Integration support.
- Priority, assignment, SLA, notes, attachments, status and resolution.
- Internal notes separate from customer-visible messages.

---

# 15. DATABASE DESIGN — CANONICAL DOMAIN INVENTORY

This section defines the required database domains and canonical entities. Exact columns, foreign keys, indexes, constraints and migrations must be elaborated in the schema design phase and maintained in an ERD. Do not create duplicate tables with overlapping responsibilities.

Use InnoDB, `utf8mb4`, UTC timestamps stored consistently, explicit currency codes, decimal monetary columns (never float for money), appropriate decimal quantity precision, foreign keys where operationally safe, indexes based on query patterns, and UUID/ULID public identifiers.

## 15.1 Identity and access

- `users`
- `user_profiles`
- `user_contacts`
- `user_verifications`
- `password_reset_tokens`
- `user_sessions`
- `roles`
- `permissions`
- `role_permissions`
- `user_roles`
- `user_permission_overrides`
- `invitations`
- `login_attempts`
- `trusted_devices` (if justified)
- `user_consents`
- `terms_versions`
- `user_terms_acceptances`

## 15.2 Tenancy and organisation

- `tenants`
- `tenant_memberships`
- `tenant_settings`
- `tenant_subscriptions`
- `tenant_domains` (if custom domains are supported)
- `organisations`
- `organisation_relationships`
- `branches`
- `departments`
- `warehouses`
- `stock_locations`
- `stock_zones`
- `stock_bins`
- `tenant_approval_policies`

## 15.3 Catalogue

- `catalogues`
- `catalogue_versions`
- `catalogue_declarations`
- `catalogue_imports`
- `catalogue_import_mappings`
- `catalogue_import_rows`
- `catalogue_import_errors`
- `catalogue_pricing_policies`
- `catalogue_pricing_versions`
- `catalogue_invoices`
- `product_categories`
- `products`
- `product_variants`
- `product_barcodes`
- `units_of_measure`
- `unit_conversions`
- `product_packaging`
- `product_media`
- `product_supplier_links`
- `product_external_mappings`
- `product_attributes`
- `product_attribute_values`
- `catalogue_snapshot_items`
- `catalogue_change_requests`

## 15.4 Inventory and movements

- `inventory_snapshots`
- `inventory_snapshot_items`
- `inventory_balances`
- `inventory_movements`
- `inventory_movement_lines`
- `inventory_batches`
- `inventory_serials`
- `inventory_adjustments`
- `inventory_adjustment_approvals`
- `inventory_cutoffs`
- `inventory_cutoff_movements`
- `inventory_valuation_layers` (if valuation method requires)
- `inventory_reconciliation_runs`

## 15.5 Stock-taking schedules

- `stocktaking_schedules`
- `stocktaking_schedule_scopes`
- `stocktaking_schedule_occurrences`
- `stocktaking_schedule_notifications`
- `stocktaking_schedule_agreements`
- `stocktaking_schedule_history`

## 15.6 Jobs and commercial scope

- `stocktaking_jobs`
- `stocktaking_job_scopes`
- `stocktaking_job_products`
- `stocktaking_job_locations`
- `stocktaking_job_requirements`
- `stocktaking_job_attachments`
- `stocktaking_job_amendments`
- `stocktaking_job_status_history`
- `stocktaking_job_approvals`
- `job_quotes`
- `job_quote_lines`
- `job_quote_versions`
- `job_pricing_breakdowns`
- `job_compensations`
- `job_expenses`
- `job_deposits`
- `job_payment_allocations`
- `job_cancellations`

## 15.7 SOP and execution

- `procedure_templates`
- `procedure_template_versions`
- `job_procedures`
- `job_procedure_steps`
- `job_procedure_acknowledgements`
- `job_checklists`
- `job_checklist_items`
- `job_checklist_responses`
- `job_site_checkins`
- `job_site_checkouts`
- `job_attendance`
- `job_progress_events`

## 15.8 Workforce

- `stock_taker_profiles`
- `stock_taker_verifications`
- `stock_taker_documents`
- `stock_taker_qualifications`
- `stock_taker_skills`
- `stock_taker_industries`
- `stock_taker_service_areas`
- `stock_taker_availability`
- `stock_taker_training`
- `stock_taker_training_completions`
- `stock_taker_agencies`
- `agency_memberships`
- `job_assignments`
- `job_assignment_offers`
- `job_assignment_acceptances`
- `stock_taker_performance_snapshots`
- `stock_taker_reviews`
- `stock_taker_appeals`
- `stock_taker_earnings`
- `stock_taker_payout_methods`

## 15.9 Count sessions and entries

- `count_sessions`
- `count_session_locations`
- `count_entries`
- `count_entry_revisions`
- `count_entry_units`
- `count_entry_observations`
- `count_evidence`
- `count_exceptions`
- `unknown_product_reports`
- `count_device_sessions`
- `count_sync_batches`
- `count_sync_conflicts`
- `count_submission_locks`

## 15.10 QA, verification and reconciliation

- `verification_tasks`
- `verification_samples`
- `independent_counts`
- `independent_count_entries`
- `qa_reviews`
- `qa_findings`
- `recount_requests`
- `recount_assignments`
- `recount_results`
- `discrepancies`
- `discrepancy_evidence`
- `reconciliation_reports`
- `reconciliation_items`
- `reconciliation_approvals`
- `reconciliation_adjustments`

## 15.11 Tenant review and disputes

- `tenant_result_reviews`
- `tenant_result_review_items`
- `completion_confirmations`
- `stocktaking_disputes`
- `dispute_messages`
- `dispute_evidence`
- `dispute_decisions`
- `dispute_cost_allocations`
- `dispute_appeals`

## 15.12 Finance

- `financial_accounts`
- `financial_journals`
- `financial_journal_lines`
- `financial_transactions`
- `invoices`
- `invoice_lines`
- `payments`
- `payment_attempts`
- `payment_callbacks`
- `payment_allocations`
- `payment_provider_accounts`
- `refunds`
- `credits`
- `credit_allocations`
- `chargebacks`
- `stock_taker_payouts`
- `payout_batches`
- `payout_approvals`
- `payout_transactions`
- `platform_fees`
- `tax_rates`
- `tax_transactions`
- `financial_reconciliations`
- `financial_reconciliation_items`
- `financial_adjustments`
- `expense_claims`
- `expense_approvals`
- `currency_rates` (only if multi-currency is enabled)

## 15.13 BRATHAMATU POS

- `pos_terminals`
- `pos_devices`
- `pos_shifts`
- `pos_cash_drawers`
- `pos_cash_movements`
- `pos_sales`
- `pos_sale_items`
- `pos_sale_payments`
- `pos_receipts`
- `pos_customers`
- `pos_suppliers`
- `pos_purchases`
- `pos_purchase_items`
- `pos_goods_receipts`
- `pos_returns`
- `pos_return_items`
- `pos_discounts`
- `pos_promotions`
- `pos_transfers`
- `pos_transfer_items`
- `pos_stock_movements`
- `pos_audit_events`

POS catalogue must map cleanly to the canonical tenant catalogue. Avoid separate duplicate product masters unless a deliberate bounded context and synchronisation policy is approved.

## 15.14 Integrations and API

- `integration_providers`
- `tenant_integrations`
- `integration_credentials` (encrypted secrets or external secret references)
- `integration_field_mappings`
- `integration_sync_runs`
- `integration_sync_items`
- `integration_errors`
- `webhook_endpoints`
- `webhook_subscriptions`
- `webhook_deliveries`
- `api_clients`
- `api_tokens`
- `api_request_logs` (redacted and retention-limited)
- `idempotency_keys`

## 15.15 Support, notifications and content

- `support_tickets`
- `support_ticket_messages`
- `support_ticket_attachments`
- `support_ticket_assignments`
- `notifications`
- `notification_preferences`
- `notification_deliveries`
- `notification_templates`
- `announcements`
- `public_pages`
- `faqs`
- `knowledge_base_articles`
- `feedback_submissions`

## 15.16 Platform, audit and security

- `platform_settings`
- `feature_flags`
- `service_plans`
- `plan_features`
- `subscriptions`
- `subscription_items`
- `subscription_usage`
- `audit_logs`
- `security_events`
- `access_logs`
- `admin_impersonation_sessions`
- `data_export_requests`
- `data_deletion_requests`
- `retention_policies`
- `scheduled_tasks`
- `system_health_events`

## 15.17 Database standards

- Use `DECIMAL` for money and fractional quantities; never PHP/SQL floating point for currency calculations.
- Store currency as ISO currency code per monetary value.
- Define quantity precision and rounding policy per unit of measure.
- Use unique constraints for tenant-scoped SKU/barcode rules, payment references, provider transaction IDs, occurrence keys and idempotency keys.
- Index every high-volume foreign key and common filter combination.
- Add composite indexes including `tenant_id` for tenant queries.
- Use soft deletes only for records where reversible hiding is valid. Financial journals, count submissions, audit events and payment callbacks must not be hard-deleted.
- Use immutable event/history tables for status changes.
- Add `created_by`, `updated_by` where meaningful, and explicit system actor attribution.
- Add optimistic locking/version columns for critical editable records.
- Keep private evidence in object storage; database stores metadata and object key.
- Avoid storing large JSON blobs as a substitute for relational structure. JSON is suitable for provider payload metadata and extensible attributes with validation.
- Every migration must have a rollback plan, but destructive rollback must not be run against production data without an approved recovery plan.

---

# 16. API CONTRACT — LARAVEL BACKEND

## 16.1 API conventions

Base path: `/api/v1`

- JSON request/response by default.
- Bearer token/session authentication as selected for the deployment architecture.
- Use consistent response envelope.
- Use ISO-8601 timestamps in UTC with tenant timezone for presentation.
- Use UUID/ULID public IDs.
- Validate every request on backend.
- Return standard HTTP status codes.
- Paginate collections.
- Support filtering, sorting and search with allowlists.
- Apply tenant scoping and policies on every endpoint.
- Version breaking changes.
- Publish OpenAPI 3 specification.
- Generate/maintain typed frontend API client from the contract where practical.
- Do not return internal stack traces or secrets.

Suggested success response:

```json
{
  "data": {},
  "meta": {
    "request_id": "01J...",
    "timestamp": "2026-11-11T00:00:00Z"
  }
}
```

Suggested paginated response:

```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 0,
    "last_page": 1
  },
  "links": {
    "first": null,
    "last": null,
    "next": null,
    "prev": null
  }
}
```

Suggested error response:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The supplied data is invalid.",
    "details": {
      "field_name": ["A clear validation message."]
    },
    "request_id": "01J..."
  }
}
```

## 16.2 Endpoint domains

### Authentication and identity
- `/auth/register`
- `/auth/login`
- `/auth/logout`
- `/auth/refresh` (if token architecture requires)
- `/auth/forgot-password`
- `/auth/reset-password`
- `/auth/verify-email`
- `/auth/verify-phone`
- `/auth/mfa/*`
- `/me`
- `/users`
- `/users/{id}`
- `/users/{id}/roles`
- `/invitations`
- `/consents`

### Tenant and organisation
- `/tenants`
- `/tenants/{id}`
- `/tenants/{id}/members`
- `/tenants/{id}/settings`
- `/organisations`
- `/branches`
- `/departments`
- `/warehouses`
- `/stock-locations`
- `/stock-zones`

### Catalogue
- `/catalogues`
- `/catalogues/{id}`
- `/catalogues/{id}/versions`
- `/catalogues/{id}/declarations`
- `/catalogues/imports`
- `/catalogues/imports/{id}`
- `/catalogues/imports/{id}/preview`
- `/catalogues/imports/{id}/commit`
- `/catalogues/imports/{id}/errors`
- `/products`
- `/products/{id}`
- `/products/{id}/barcodes`
- `/product-categories`
- `/units`
- `/unit-conversions`
- `/catalogue-pricing/estimate`

### Inventory
- `/inventory/snapshots`
- `/inventory/balances`
- `/inventory/movements`
- `/inventory/cutoffs`
- `/inventory/adjustments`
- `/inventory/reconciliations`

### Schedules
- `/stocktaking-schedules`
- `/stocktaking-schedules/{id}`
- `/stocktaking-schedules/{id}/pause`
- `/stocktaking-schedules/{id}/resume`
- `/stocktaking-schedules/{id}/skip-occurrence`
- `/stocktaking-schedules/{id}/reschedule-occurrence`
- `/stocktaking-schedules/{id}/generate-request`
- `/stocktaking-schedules/{id}/history`
- `/stocktaking-schedule-occurrences`

### Jobs and quotes
- `/stocktaking-requests`
- `/stocktaking-jobs`
- `/stocktaking-jobs/{id}`
- `/stocktaking-jobs/{id}/submit`
- `/stocktaking-jobs/{id}/review`
- `/stocktaking-jobs/{id}/request-information`
- `/stocktaking-jobs/{id}/quote`
- `/stocktaking-jobs/{id}/accept-quote`
- `/stocktaking-jobs/{id}/approve`
- `/stocktaking-jobs/{id}/amend`
- `/stocktaking-jobs/{id}/cancel`
- `/stocktaking-jobs/{id}/status-history`
- `/stocktaking-jobs/{id}/progress`

### Workforce and assignment
- `/stock-takers`
- `/stock-takers/{id}`
- `/stock-takers/{id}/verification`
- `/stock-takers/{id}/qualifications`
- `/stock-takers/{id}/availability`
- `/stock-takers/{id}/performance`
- `/stock-takers/{id}/earnings`
- `/stocktaking-jobs/{id}/assignment-recommendations`
- `/stocktaking-jobs/{id}/assignments`
- `/assignments/{id}/accept`
- `/assignments/{id}/decline`
- `/assignments/{id}/reassign`

### SOP and execution
- `/procedure-templates`
- `/stocktaking-jobs/{id}/procedure`
- `/stocktaking-jobs/{id}/procedure/acknowledge`
- `/stocktaking-jobs/{id}/checklists`
- `/stocktaking-jobs/{id}/check-in`
- `/stocktaking-jobs/{id}/check-out`

### Counting
- `/stocktaking-jobs/{id}/count-sessions`
- `/count-sessions/{id}`
- `/count-sessions/{id}/entries`
- `/count-entries/{id}`
- `/count-entries/{id}/revisions`
- `/count-sessions/{id}/submit`
- `/count-sessions/{id}/sync`
- `/count-exceptions`
- `/unknown-product-reports`
- `/count-evidence`

### QA and reconciliation
- `/verification-tasks`
- `/verification-tasks/{id}`
- `/independent-counts`
- `/qa-reviews`
- `/recount-requests`
- `/recount-requests/{id}/approve`
- `/discrepancies`
- `/reconciliation-reports`
- `/reconciliation-reports/{id}/approve`
- `/inventory-adjustments`

### Tenant review and disputes
- `/stocktaking-jobs/{id}/results`
- `/stocktaking-jobs/{id}/review`
- `/stocktaking-jobs/{id}/accept`
- `/stocktaking-jobs/{id}/request-recount`
- `/stocktaking-jobs/{id}/disputes`
- `/disputes`
- `/disputes/{id}`
- `/disputes/{id}/evidence`
- `/disputes/{id}/resolve`
- `/disputes/{id}/appeal`

### Finance
- `/quotes`
- `/invoices`
- `/invoices/{id}`
- `/payments/initiate`
- `/payments/{id}`
- `/payments/callbacks/{provider}` (provider-specific protected routes)
- `/payments/history`
- `/deposits`
- `/refunds`
- `/credits`
- `/finance/ledger`
- `/finance/reconciliations`
- `/stock-taker/payout-methods`
- `/stock-taker/earnings`
- `/stock-taker/payouts`
- `/admin/payouts`
- `/admin/payouts/{id}/approve`

### BRATHAMATU POS
- `/pos/terminals`
- `/pos/shifts`
- `/pos/shifts/{id}/open`
- `/pos/shifts/{id}/close`
- `/pos/sales`
- `/pos/sales/{id}`
- `/pos/receipts`
- `/pos/purchases`
- `/pos/suppliers`
- `/pos/returns`
- `/pos/transfers`
- `/pos/reports`
- `/pos/stocktaking`

### Integrations
- `/integrations/providers`
- `/integrations`
- `/integrations/{id}/connect`
- `/integrations/{id}/sync`
- `/integrations/{id}/logs`
- `/webhooks`
- `/webhooks/{id}`
- `/api-clients`

### Reports, notifications and support
- `/reports`
- `/reports/{id}`
- `/reports/{id}/export`
- `/notifications`
- `/notification-preferences`
- `/support/tickets`
- `/support/tickets/{id}`
- `/announcements`
- `/admin/audit-logs`
- `/admin/security-events`
- `/admin/settings`
- `/admin/feature-flags`
- `/admin/health`

## 16.3 API contract requirements

For every endpoint, document:
- HTTP method and path.
- Authentication requirement.
- Allowed roles and permissions.
- Tenant/branch/resource scope.
- Request body and field types.
- Validation rules.
- Response schema and examples.
- Error codes.
- Pagination/filter/sort rules.
- Idempotency requirements.
- Audit event.
- Side effects/events/notifications.
- Rate limit.
- Whether operation is synchronous or queued.

## 16.4 API security

- Use Sanctum or another deliberately selected Laravel-compatible auth approach; document cookie/session vs bearer-token model before frontend integration.
- Secure cookies, CSRF protection where cookie auth is used, token expiry/revocation where token auth is used.
- MFA for platform admins and finance approvers.
- Rate limit login, password reset, OTP, import, payment and sensitive endpoints.
- Validate uploaded files and enforce private storage.
- Use policies and tenant-scoped queries.
- Use idempotency keys for payments, count sync, job creation and other retry-prone operations.
- Sign outbound webhooks and retry with backoff.
- Redact secrets and personal data from logs.
- Add request correlation IDs.
- Use CORS allowlist, secure headers and HTTPS.
- Keep OpenAPI documentation access-controlled where it exposes internal operations.

---

# 17. LARAVEL BACKEND IMPLEMENTATION STANDARDS

## 17.1 Architecture

Use Laravel 13 with modular domain organisation. Suggested top-level domains:
- Identity
- Tenancy
- Organisation
- Catalogue
- Inventory
- Scheduling
- Stocktaking
- Workforce
- Counting
- Quality
- Reconciliation
- Finance
- POS
- Integrations
- Notifications
- Support
- Reporting
- Platform Administration

Suggested code organisation may use `app/Domain/<Domain>` with Actions, DTOs, Models, Policies, Services, Events, Listeners and Jobs. Follow Laravel conventions where possible; do not create unnecessary abstraction layers.

## 17.2 Backend rules

- Controllers remain thin.
- Form Requests validate input.
- Policies authorise resources.
- Services/actions implement business workflows.
- Use transactions for multi-table business operations.
- Use queued jobs for imports, exports, notifications, sync and heavy processing.
- Use events for domain changes and integrations.
- Use API Resources for stable response schemas.
- Use enums/value objects for statuses and domain values where appropriate.
- Use database constraints in addition to application validation.
- Avoid mass assignment vulnerabilities.
- Never trust tenant IDs, prices, totals, roles, payment status or workflow status from frontend.
- Centralise financial calculations and rounding.
- Use explicit service contracts for payment providers and external POS systems.
- Use structured logs and request IDs.
- Keep secrets out of source control.

## 17.3 Laravel project foundation

- Laravel 13.
- PHP version supported by Laravel 13 and selected deployment.
- MySQL 8.
- Redis for queues/cache/locks.
- Laravel queue workers and scheduler.
- Laravel Reverb or an approved realtime provider for live updates.
- Private S3-compatible object storage for evidence and exports.
- Search engine (Meilisearch/OpenSearch) when product/tenant scale justifies it.
- Pest or PHPUnit.
- Laravel Pint and static analysis.
- API documentation generation and validation.
- Health checks and queue monitoring.

---

# 18. TANSTACK FRONTEND IMPLEMENTATION STANDARDS

## 18.1 Frontend architecture

Use a dedicated TanStack React application. Recommended baseline:
- React + TypeScript.
- TanStack Router for route definitions and protected layouts.
- TanStack Query for server state, caching and mutations.
- TanStack Table for large data grids.
- TanStack Form or a consistent typed form library.
- Zod or equivalent schema validation aligned with API schemas.
- Vite build tooling.
- A consistent component system and accessible primitives.
- API client generated or typed from OpenAPI where practical.

Choose exact compatible versions during repository setup and lock them. Do not mix multiple routing/data-fetching/form libraries without a documented reason.

## 18.2 Frontend structure

Suggested feature organisation:
- `src/app` — providers, app shell, route configuration.
- `src/features/auth`
- `src/features/platform-admin`
- `src/features/tenants`
- `src/features/organisation`
- `src/features/catalogue`
- `src/features/inventory`
- `src/features/schedules`
- `src/features/stocktaking-jobs`
- `src/features/workforce`
- `src/features/counting`
- `src/features/quality`
- `src/features/reconciliation`
- `src/features/finance`
- `src/features/pos`
- `src/features/integrations`
- `src/features/reports`
- `src/features/support`
- `src/components`
- `src/lib/api`
- `src/lib/auth`
- `src/lib/permissions`
- `src/lib/formatting`
- `src/styles`
- `src/test`

## 18.3 Frontend experience requirements

- Distinct app shells and sidebars per role.
- Responsive desktop, tablet and mobile layouts.
- Clear loading, empty, error, success and permission-denied states.
- Accessible keyboard navigation and labels.
- Search/filter/sort/pagination on large lists.
- Server-side pagination for high-volume data.
- Optimistic updates only where safe; financial/workflow state must be server-confirmed.
- Confirm destructive actions and show impact.
- Show audit history for sensitive records to authorised users.
- Do not expose controls the current user cannot use; backend remains final authority.
- Keep API errors understandable and actionable.
- Avoid giant monolithic components.
- No direct database access.
- No frontend-only business rules.
- Use consistent KES formatting and timezone display.
- Clearly distinguish tenant price from worker compensation by role.

## 18.4 Required browser routes

At minimum:
- `/login`, `/register`, `/forgot-password`, `/verify`
- `/app` role-aware dashboard
- `/platform/*`
- `/business/*`
- `/workforce/*`
- `/pos/*`
- `/support/*`
- `/profile`, `/settings`, `/notifications`

Route guards improve UX but never replace backend authorisation.

---

# 19. UI/UX DESIGN SYSTEM AND REQUIRED SCREENS

Build a polished, professional SaaS interface. Visual design should be consistent, fast, readable and operationally useful rather than decorative.

## 19.1 Shared components

- App shell and role-aware sidebar.
- Header with tenant/branch switcher where allowed.
- Breadcrumbs.
- Global search.
- Notification centre.
- Profile and session controls.
- KPI cards.
- Data tables with server pagination.
- Filter panels.
- Status badges.
- Activity timeline.
- Audit history.
- Confirmation dialogs.
- Form fields and validation.
- File uploader with progress.
- Empty/loading/error states.
- Report preview/export.
- Responsive mobile navigation.

## 19.2 Tenant screens

- Business onboarding wizard.
- Organisation profile.
- Branch/warehouse/location manager.
- Team members and role assignments.
- Catalogue dashboard.
- Catalogue import wizard.
- Product list/detail/editor.
- Catalogue declaration.
- Catalogue fee estimate/invoice/payment.
- Inventory snapshots and movement history.
- Stock-taking overview.
- New job request wizard.
- Quote review and acceptance.
- Deposit/balance payment.
- Schedule calendar/list/editor.
- Upcoming occurrences and missed schedule view.
- Job progress/detail.
- Results and evidence review.
- Recount/dispute workflow.
- Reconciliation and adjustment approval.
- Reports and exports.
- Billing and payment history.
- Integration setup and sync logs.
- Support tickets.

## 19.3 Stock-taker screens

- Application and onboarding.
- Verification status.
- Profile, skills and service area.
- Availability calendar.
- Training and certifications.
- Assignment offers.
- Assignment detail and disclosed compensation.
- SOP acknowledgement.
- Job check-in.
- Count workspace with barcode/search.
- Offline sync status.
- Exceptions/evidence capture.
- Submit count.
- Recount/correction tasks.
- Job history.
- Reviews and performance.
- Earnings and payout methods.
- Payout history.
- Support.

## 19.4 BRATHAMATU screens

- Executive overview.
- Tenant management.
- Tenant onboarding review.
- Catalogue/import monitoring.
- Catalogue pricing policies.
- Job request queue.
- Job review and quote builder.
- Deposit/payment verification.
- Job approval and assignment.
- Workforce directory and verification.
- Availability and assignment planner.
- Live job operations.
- SOP template management.
- QA and independent verification.
- Recount/dispute case management.
- Tenant result approval monitoring.
- Finance ledger and reconciliation.
- Payout queue and maker-checker approval.
- Refunds and credits.
- POS administration.
- Integration monitoring.
- Support centre.
- Reports/analytics.
- Roles/permissions.
- Platform settings and feature flags.
- Audit/security centre.

## 19.5 POS screens

- POS sales terminal.
- Product lookup/barcode.
- Cart and checkout.
- Payment collection.
- Receipt.
- Open/close shift.
- Cash drawer.
- Product/catalogue.
- Purchases and receiving.
- Suppliers.
- Customers.
- Returns/refunds.
- Transfers.
- Inventory.
- Reports.
- Stock-taking service centre.

---

# 20. SECURITY, PRIVACY AND COMPLIANCE

## 20.1 Security controls

- HTTPS everywhere.
- Secure password hashing using Laravel defaults.
- MFA for privileged users and finance approvers.
- Rate limiting and brute-force protection.
- Session/device management.
- Least privilege.
- Tenant isolation tests.
- CSRF/XSS/SQL injection protections.
- Output encoding.
- File upload validation and private storage.
- Signed temporary download URLs.
- Secret management and rotation.
- Encryption at rest for sensitive values where appropriate.
- Audit logging for sensitive reads and all critical writes.
- Dependency scanning and patching.
- Backup encryption.
- Disaster recovery drills.
- Security incident response procedure.
- No secrets or payment credentials in Git, frontend bundles or logs.

## 20.2 Privacy

- Collect minimum necessary personal data.
- Document purposes and retention.
- Obtain appropriate notices/consents.
- Support correction and lawful deletion requests while preserving legally required financial/audit records.
- Restrict identity documents and payout details.
- Avoid exposing tenant product costs to workers unless explicitly required and authorised.
- Define evidence/photo retention.
- Provide tenant data export and offboarding process.
- Review Kenya Data Protection Act and applicable regulations with qualified counsel.

## 20.3 Audit events

Audit at minimum:
- Login, logout, MFA and failed access.
- Role/permission changes.
- Tenant switching and privileged impersonation.
- Catalogue import, confirmation and amendment.
- Schedule creation/change/skip.
- Job request, quote, approval, assignment and amendment.
- SOP acknowledgement and version change.
- Count submission/revision/reopen.
- Evidence access/export.
- QA decision and recount.
- Tenant acceptance/dispute.
- Payment, refund, ledger posting and payout.
- POS refund/void/discount/shift close.
- Integration credential and sync changes.
- Report/export generation.
- Platform settings changes.

Audit records should include actor, tenant context, action, subject, timestamp, request ID, reason where required and relevant before/after metadata. Redact secrets and unnecessary personal data.

---

# 21. PERFORMANCE, SCALE AND RELIABILITY

Design for growth to millions of registered users, but distinguish registered users from concurrent active users and peak transactions.

## 21.1 Baseline architecture

- Stateless Laravel API instances behind a load balancer.
- MySQL 8 primary with backups and tested restore.
- Redis for cache, queues, distributed locks and rate limits.
- Horizontally scalable queue workers.
- Private object storage/CDN for approved static assets.
- Realtime service for job progress/notifications.
- Search index for large catalogue search.
- Centralised logs, metrics and error monitoring.
- Separate staging and production environments.

## 21.2 Performance requirements

- Paginate all potentially large collections.
- Avoid N+1 queries.
- Add indexes based on measured query plans.
- Use queued processing for large imports/exports and heavy reports.
- Chunk large catalogue imports.
- Use database transactions and locks carefully.
- Cache low-risk reference/configuration data with tenant-aware keys.
- Use read replicas only when justified.
- Consider partitioning/archive strategy only after measured table growth.
- Load-test catalogue search, count submissions, imports, reports and payment callbacks.
- Set measurable SLOs before launch; do not invent a production capacity claim without load-test evidence.

## 21.3 Reliability

- Queue retry/backoff and dead-letter handling.
- Idempotent callbacks and jobs.
- Health/readiness endpoints.
- Scheduled task monitoring.
- Database backup and restore tests.
- Object storage lifecycle and backup policy.
- Payment reconciliation.
- Integration sync recovery.
- Graceful degradation if realtime/search provider is unavailable.
- Incident runbooks and escalation.

---

# 22. TESTING STRATEGY AND BROWSER ACCEPTANCE

Every phase must be tested at three levels: backend, frontend and real browser workflow.

## 22.1 Backend tests

- Unit tests for pricing, unit conversions, recurrence and financial calculations.
- Feature tests for endpoints and workflows.
- Policy tests for every role and tenant boundary.
- Database constraints and migration tests.
- Payment callback idempotency tests.
- Queue/event tests.
- Import parsing and duplicate tests.
- Count revision/offline sync tests.
- Ledger balance and reversal tests.
- API schema/contract tests.

## 22.2 Frontend tests

- Component tests.
- Form validation tests.
- Route guards and role navigation tests.
- Query/mutation state tests.
- Error/empty/loading state tests.
- Accessibility checks.
- API contract/type checks.

## 22.3 Browser end-to-end tests

Use Playwright (recommended) or an equivalent browser automation framework. Run against a real local/staging browser application with seeded test accounts.

Test at minimum:

### Tenant onboarding
1. Register tenant owner.
2. Verify test email/phone using test adapter.
3. Create organisation.
4. Add branches and users.
5. Upload catalogue.
6. Resolve duplicate/invalid rows.
7. Confirm catalogue.
8. View catalogue fee and test payment.

### Stock-taking request
1. Create request.
2. Select branches, products and method.
3. Choose date and SOP.
4. Pay deposit in sandbox.
5. BRATHAMATU reviews and requests clarification.
6. Issue final quote.
7. Tenant accepts and pays balance.
8. BRATHAMATU approves and assigns worker.

### Worker execution
1. Worker logs in.
2. Sees only assigned job.
3. Accepts disclosed compensation.
4. Acknowledges SOP.
5. Checks in.
6. Counts products.
7. Cannot edit catalogue or expected quantity.
8. Reports unknown item.
9. Saves/submits count.
10. Supervisor performs QA/recount.

### Tenant completion
1. Tenant reviews results.
2. Requests correction or accepts.
3. Dispute flow is tested.
4. Completion confirmation recorded.
5. Payout is prepared and approved.
6. Worker sees payout status.
7. Finance ledger balances.

### Recurring schedules
1. Create monthly schedule.
2. Generate next occurrence.
3. Verify reminder-only mode.
4. Verify proposed request requires tenant authorisation.
5. Verify prepaid agreement rules.
6. Pause, resume, skip and reschedule.
7. Verify no duplicate occurrence or unauthorised charge.
8. Test month-end and leap-year recurrence.

### POS
1. Open shift.
2. Make sale with barcode.
3. Process cash/M-Pesa sandbox payment.
4. Verify inventory movement.
5. Close shift and reconcile.
6. Request stock take from POS.
7. Confirm tenant must authorise job/payment.
8. Compare BRATHAMATU POS and stock-taking catalogue snapshot.

### Security
1. Tenant A cannot access Tenant B records.
2. Stock taker cannot access tenant billing or catalogue edit endpoints.
3. Tenant cannot access another tenant's results.
4. Finance officer cannot approve own high-value payout.
5. Invalid payment callback cannot activate job.
6. Replayed callback does not duplicate payment.
7. Unauthorised file URL is rejected.
8. Role changes take effect immediately or after documented session refresh.

## 22.4 Browser test evidence

For each phase, save:
- Test report.
- Screenshots of key workflows.
- Browser console errors.
- Network/API failures.
- Test account roles used.
- Seed data identifiers.
- Known issues and severity.
- Sign-off record.

A phase is not accepted with unresolved critical/high severity defects.

---

# 23. SEED DATA AND TEST ACCOUNTS

Create repeatable seeders/factories. Never use real customer personal data in test environments.

## 23.1 Demo tenants

1. Demo Supermarket — multiple branches, thousands of products, weekly perishables and monthly full counts.
2. Demo Pharmacy — batches, expiry dates, serial/lot tracking and restricted product visibility.
3. Demo Hardware — varied units, packaging and large catalogue.
4. Demo Warehouse — zones, bins, transfers and serialised items.
5. Demo Restaurant — ingredients, units, waste and frequent cycle counts.
6. Demo SME — spreadsheet catalogue and on-demand stock take.
7. Demo Enterprise — many branches, departments, warehouses and external POS integration.

## 23.2 Demo users

Create test accounts for:
- Super Admin.
- Platform Operations Manager.
- Finance Manager.
- Finance Officer.
- Support Agent.
- Tenant Owner.
- Tenant Admin.
- Branch Manager.
- Inventory Manager.
- Tenant Auditor.
- Stock Taker Trainee.
- Verified Stock Taker.
- Senior Stock Taker.
- Agency Manager.
- POS Cashier.

Credentials must be documented only in local/staging seed instructions and never used in production.

## 23.3 Seed scenarios

- Valid catalogue.
- Invalid rows.
- Duplicate SKU/barcode.
- Different product variants.
- Unit conversion and carton/each.
- Missing expected stock.
- Surplus/shortage.
- Damaged and expired items.
- Unknown product.
- Stock movement during count.
- Independent count mismatch.
- Recount confirms worker count.
- Worker count error confirmed.
- Payment pending/failed/success/reversed.
- Duplicate payment callback.
- Partial refund.
- Payout failure and retry.
- Disputed job.
- Monthly/annual schedule.
- Month-end recurrence.
- Offline count sync conflict.

---

# 24. IMPLEMENTATION PHASES AND EXIT CRITERIA

Build in phases. Each phase includes migrations, backend APIs, frontend screens, permissions, seed data, automated tests and browser validation for the scope delivered. Do not build disconnected screens with fake data and call them complete.

## Phase 0 — Repository discovery and technical baseline

- Inspect existing repositories and current code before modifying anything.
- Confirm exact Laravel/PHP/Node versions, database, frontend setup, routes, packages and environment.
- Create architecture decision records.
- Confirm local ports, environment variables and startup commands.
- Create implementation backlog mapped to this master document.
- Produce ERD v1, API/OpenAPI baseline and threat model.
- Confirm no existing functionality is overwritten without review.

**Exit:** repository inventory, baseline build, architecture decisions, test setup and phase backlog approved.

## Phase 1 — Project foundation and local browser launch

Backend:
- Laravel 13 setup.
- MySQL connection and migrations.
- API `/api/v1` skeleton.
- Standard response/error format.
- Logging, request IDs and health endpoint.
- Redis/queue/scheduler foundations where available.

Frontend:
- TanStack React + TypeScript setup.
- Router, Query, API client and design system.
- App shell and error boundaries.
- Login/register placeholder flow backed by real API.

**Exit:** both apps run locally, browser can log in to test environment, health and API smoke tests pass.

## Phase 2 — Identity, roles and tenant isolation

- Authentication, email/phone verification adapters.
- Users, roles, permissions and memberships.
- Tenant registration and organisation setup.
- Role-specific dashboards and navigation foundations.
- Tenant-scoped policies and audit logs.
- Cross-tenant security tests.

**Exit:** all demo roles authenticate and see only authorised data; Tenant A/B isolation tests pass in browser and backend.

## Phase 3 — Organisation, branches and locations

- Branches, departments, warehouses, zones and bins.
- Tenant user invitations and access scopes.
- Organisation settings and timezone.
- Browser management screens.

**Exit:** tenant can configure multiple branches/locations and restrict users by scope.

## Phase 4 — Catalogue onboarding and pricing

- Catalogue/product/variant/unit/barcode schema.
- CSV/XLSX import queue, mapping, preview and error report.
- Manual catalogue management.
- Duplicate handling and versioning.
- Catalogue declaration.
- Configurable catalogue pricing policy and invoice.
- Catalogue search and product detail.

**Exit:** tenant can upload, validate, confirm and version catalogue; stock-take request is blocked until required catalogue readiness is met.

## Phase 5 — Inventory snapshots and movement controls

- Inventory balances and movement history.
- Cut-off process.
- Expected stock snapshots.
- Batch/serial/expiry support.
- External movement upload foundation.
- Inventory dashboards.

**Exit:** system can create reproducible job-linked stock snapshots and preserve movement during count.

## Phase 6 — Stock-taking request, review and quotation

- Job request wizard.
- Scope selection.
- Request workflow.
- Deposit policy configuration.
- Quote builder with separate tenant price and worker compensation.
- Amendments and approval history.
- Tenant quote acceptance.

**Exit:** full request-to-quote flow works with configurable deposit and no financial leakage to worker.

## Phase 7 — Payments and financial ledger

- Payment provider abstraction.
- M-Pesa sandbox adapter and callback handling.
- Card/bank/manual adapters.
- Payment allocation.
- Double-entry ledger.
- Reconciliation and refund foundations.
- Deposit/balance browser flows.

**Exit:** sandbox payments are idempotent, reconciled and posted to balanced ledger; browser redirects alone cannot mark payments successful.

## Phase 8 — Workforce onboarding and assignment

- Stock-taker registration and verification.
- Skills, service areas and availability.
- Training and progression.
- Assignment recommendation.
- Assignment offers, acceptance and reassignment.
- Compensation visibility controls.

**Exit:** verified worker can receive and accept only eligible assignments and sees only own compensation.

## Phase 9 — SOP and job operations

- Procedure templates and versions.
- Job-specific SOP.
- Acknowledgement and checklists.
- Attendance/check-in/out.
- Progress tracking.
- Operations dashboard.

**Exit:** worker cannot start count without required acceptance/check-in and SOP acknowledgement.

## Phase 10 — Count engine

- Blind/guided count modes.
- Count sessions and entries.
- Barcode and search.
- Quantity-only capture.
- Unit conversion.
- Exceptions and unknown product reporting.
- Evidence capture.
- Revision history and submission lock.

**Exit:** worker can count assigned products but cannot modify catalogue or expected quantities; all count submissions are traceable.

## Phase 11 — Offline capability and field reliability

- Offline job package.
- Local queue and sync.
- Idempotent submissions.
- Conflict resolution.
- Retry and sync indicators.
- Device/session audit.

**Exit:** browser field workflow survives offline interruption and synchronises without duplicate/lost entries under tested scenarios.

## Phase 12 — QA, independent verification and recount

- QA rules.
- Supervisor review.
- Independent sampling.
- Double counts.
- Recount workflows.
- Worker accuracy metrics.
- Recount cost investigation policy.

**Exit:** expected stock variance is not treated as worker error; verified count accuracy and recount attribution work correctly.

## Phase 13 — Tenant review, reconciliation and disputes

- Results publishing.
- Tenant review window and reminders.
- Accept/correction/recount/dispute.
- Reconciliation report.
- Adjustment approval.
- Dispute evidence and resolution.

**Exit:** tenant can review, accept or dispute; adjustments require authorised tenant approval; disputes have complete audit history.

## Phase 14 — Completion, payout and finance operations

- Completion confirmation.
- Payout queue.
- Maker-checker approval.
- M-Pesa/bank payout adapters.
- Payout reconciliation.
- Refund/credit/expense handling.
- Finance dashboards and statements.

**Exit:** payout cannot occur before required completion and approval; ledger remains balanced and payout is idempotent.

## Phase 15 — Recurring and on-demand schedules

- All recurrence frequencies.
- Multiple schedules and scopes.
- Reminder-only, proposed job and prepaid agreement modes.
- Occurrence generation.
- Reschedule/skip/pause/resume.
- Notifications and demand forecasting.
- Month-end/leap-year tests.

**Exit:** on-demand and recurring schedules work without duplicate jobs or unauthorised charges.

## Phase 16 — BRATHAMATU POS core

- Terminals, shifts, sales, products and barcode.
- Payments and receipts.
- Purchases, suppliers, returns and transfers.
- Inventory movement integration.
- Cash reconciliation.
- POS roles and dashboards.

**Exit:** POS sale and stock movement are consistent, auditable and visible in stock snapshots.

## Phase 17 — POS stock-taking service integration

- Catalogue readiness.
- Stock-taking request from POS.
- Scope/schedule selection.
- Quote/deposit/payment handoff.
- Job progress and results.
- Plan-specific access/pricing policies.

**Exit:** POS tenant can commission a job only with explicit authorisation or valid prepaid agreement.

## Phase 18 — External POS/ERP integrations

- Provider framework.
- CSV/API sync.
- Credential management.
- Mapping, logs, retries and conflicts.
- Webhooks and scheduled sync.

**Exit:** external-POS tenant can onboard catalogue and movement data without using BRATHAMATU POS.

## Phase 19 — Reporting, support and platform intelligence

- Tenant and platform reports.
- Exports.
- Support tickets and SLAs.
- Notifications and preferences.
- Analytics and future AI integration interfaces.

**Exit:** reports are scoped, audited and accurate; support workflows work end-to-end.

## Phase 20 — Security hardening, performance and production readiness

- Penetration/security review.
- Load tests.
- Queue and database tuning.
- Backups/restore and disaster recovery.
- Monitoring/alerting.
- Privacy and retention review.
- Legal/accounting review.
- Deployment pipeline and runbooks.

**Exit:** production checklist signed off, no critical/high security defects, restore test successful, load-test evidence documented.

## Phase 21 — Final end-to-end browser acceptance

- Execute full role-based scenarios.
- Run Playwright suite on staging.
- Validate every API domain and OpenAPI contract.
- Confirm all navigation links and actions.
- Test desktop/tablet/mobile layouts.
- Verify no fake data remains in production paths.
- Verify audit, permissions, tenant isolation and financial controls.
- Record final acceptance report.

**Exit:** all agreed acceptance criteria pass and release is explicitly approved.

---

# 25. LOCAL DEVELOPMENT AND BROWSER TESTING WORKFLOW

The exact ports and commands must be confirmed during Phase 0 and stored in `.env.example`, README and developer runbook. Do not assume the frontend/backend ports without checking the repository.

Recommended local workflow:
1. Start MySQL.
2. Start Redis if configured.
3. Run Laravel migrations and seeders.
4. Start Laravel API server.
5. Start queue worker.
6. Start Laravel scheduler in development.
7. Start TanStack/Vite frontend.
8. Open frontend in browser.
9. Log in with seeded test account.
10. Complete the current phase's browser acceptance scenarios.
11. Inspect browser console and network panel.
12. Inspect Laravel logs and failed jobs.
13. Run backend and frontend test suites.
14. Record defects and evidence.
15. Fix defects before phase sign-off.

Provide scripts/commands in README once repository details are known. Keep `.env.example` complete but never commit real credentials.

---

# 26. DEPLOYMENT AND ENVIRONMENTS

Environments:
- Local development.
- Shared development/integration.
- Staging/UAT.
- Production.

Production requirements:
- HTTPS and secure headers.
- Separate secrets and databases.
- Automated deployment with migration review.
- Queue workers and scheduler managed by process supervisor/container platform.
- Database backups and restore testing.
- Private object storage.
- Monitoring, error reporting and alerting.
- Centralised logs with redaction.
- Health checks.
- Rollback/recovery runbook.
- Feature flags for controlled release.
- No demo users or test payment credentials in production.

Deployment topology should be selected after workload, budget, hosting constraints and operational capacity are assessed. Keep the application stateless so it can scale horizontally.

---

# 27. ACCEPTANCE CRITERIA — WHOLE PRODUCT

The platform is not complete until all of the following are true:

## Product and tenancy
- Multiple tenants can operate independently.
- Each tenant can create branches, warehouses and locations.
- Tenant data is isolated in all backend and frontend paths.
- Role-specific dashboards and permissions work.
- Platform super admin has controlled global access with audit.

## Catalogue
- Tenant can upload/import/create catalogue.
- Validation and duplicate resolution work.
- Product identity, units and packaging are modelled correctly.
- Tenant declaration is recorded.
- Job uses immutable catalogue snapshot.
- Stock taker cannot edit catalogue.

## Jobs and workforce
- Tenant can request on-demand jobs.
- BRATHAMATU can review and quote.
- Deposit and balance are enforced according to policy.
- Separate tenant charge and worker compensation are protected.
- BRATHAMATU can assign verified workers.
- Worker acknowledges SOP and accepts assignment.
- Worker can record quantity-only counts.
- Unknown product workflow is available.

## Quality and results
- Count history and revisions are preserved.
- QA and independent recount work.
- Worker accuracy is measured against verified counts.
- Tenant can review, accept, request recount or dispute.
- Reconciliation and adjustment approval work.

## Recurrence
- Weekly, fortnightly, monthly, quarterly, half-yearly, annual and custom schedules work.
- Multiple schedules per tenant work.
- Reminder-only, proposed request and prepaid modes work.
- No unauthorised automatic paid job or charge occurs.
- Occurrences cannot duplicate.

## Finance
- Payments are verified server-side.
- Callbacks are idempotent.
- Ledger balances.
- Deposits allocate correctly.
- Refunds and payout workflows are auditable.
- Maker-checker approvals work.
- Worker cannot see tenant financial charge or margin.

## POS and integrations
- BRATHAMATU POS sales and inventory movements work.
- POS can request stock-taking with tenant authorisation.
- External POS businesses can import/sync catalogue and movement data.
- Integrations have logs, retries and credential protection.

## Quality and operations
- Reports are accurate and permission-scoped.
- Notifications work.
- Browser E2E tests pass.
- No critical/high severity security defects remain.
- Backup restore and operational runbooks are tested.
- Production launch has legal/accounting/payment review.

---

# 28. CHANGE CONTROL AND DECISION REGISTER

Every change must be recorded with:
- Change ID.
- Date.
- Requester.
- Requirement/change.
- Reason/business impact.
- Affected domains, database tables, APIs, screens, permissions and tests.
- Security/privacy/financial implications.
- Approval.
- Version introduced.
- Migration/backward compatibility plan.

## 28.1 Decisions confirmed by product owner

- Platform owner/operator is BRATHAMATU.
- Backend is Laravel 13.
- Frontend is TanStack React.
- The product is a multi-tenant stock-taking SaaS and managed marketplace.
- BRATHAMATU reviews and approves jobs.
- Tenant pays BRATHAMATU, not the stock taker.
- BRATHAMATU sets tenant charge and worker compensation separately.
- BRATHAMATU assigns stock takers.
- Tenant reviews results and confirms successful completion.
- BRATHAMATU pays stock taker after successful completion review and payout authorisation.
- BRATHAMATU retains platform margin.
- Tenant catalogue is mandatory and is the authoritative stock-taking reference.
- Stock taker can input physical quantity only and cannot alter catalogue details.
- BRATHAMATU POS is part of the product ecosystem.
- Businesses using external POS must also be supported.
- Businesses may request stock takes on demand, monthly, annually or at other frequencies.
- The system must be developed in phases and tested in a browser.
- The master implementation document is the single source of truth.

## 28.2 Commercial decisions still requiring explicit approval

| Decision | Confirmed facts | Still to decide |
|---|---|---|
| Catalogue pricing | KSh 100 for 100 products; KSh 2,000 for up to 6,000; maximum KSh 2,000 even above 6,000 | Exact formula/bands between price points; what counts as a product |
| Job deposit | Deposit required before job commissioning | Default percentage/fixed amount, minimum, deadline and refund rules |
| BRATHAMATU POS relationship | POS must make stock-taking easy to request | Separately billed, preferential price, plan inclusion or configurable combination |
| Recount cost | Must be investigated and fairly attributed | Exact default responsibility matrix and worker deduction/appeal policy |
| Recurring schedules | On-demand and recurring schedules required | Which commercial modes are enabled by default and contract terms |
| Accounting | Full finance/ledger functionality required | Accountant-approved chart, revenue recognition, tax treatment and fund handling |
| Payments | M-Pesa, card and bank support planned | Provider accounts, production credentials, callback URLs and settlement model |
| Hosting | Scalable production architecture required | Hosting provider, budget, region, RPO/RTO and expected concurrent workload |

Pending decisions must remain configurable and must not block foundational architecture.

---

# 29. DEVELOPER / CODING AGENT EXECUTION INSTRUCTIONS

Any coding agent or developer receiving this document must follow this sequence:

1. Read this entire document before changing code.
2. Inspect the existing backend and frontend repositories, current branches, migrations, routes, components, dependencies, environment files and test suites.
3. Produce a repository assessment: what exists, what works, what is incomplete, what conflicts with this document and what must be preserved.
4. Do not delete or rewrite existing working features without a documented reason and approval.
5. Map existing code to the phases in this document.
6. Identify missing architecture decisions and ask only essential questions. Use configurable policy for unresolved commercial choices.
7. Produce an implementation plan for the next phase only, including backend, database, API, frontend, permissions, tests and browser acceptance.
8. Implement the phase end-to-end. Do not create disconnected UI or mock-only flows unless explicitly labelled as prototype work.
9. Run migrations, automated tests, static analysis and frontend build.
10. Start backend and frontend locally and test the actual browser workflow.
11. Fix all critical/high defects before reporting completion.
12. Provide a concise phase report: files changed, migrations, endpoints, screens, permissions, tests run, browser evidence, known limitations and next phase.
13. Update API documentation, ERD, this document's implementation status and change register when approved.
14. Never silently change business rules, money calculations, tenant boundaries or role privileges.
15. Never expose secrets, real identity documents or production data in test output.

## 29.1 Required phase report template

```text
PHASE:
STATUS: Not started / In progress / Blocked / Passed

REPOSITORY FINDINGS:
- Existing functionality inspected:
- Existing functionality preserved:
- Conflicts or risks:

BACKEND:
- Migrations:
- Models:
- Services/actions:
- Policies/permissions:
- API endpoints:
- Events/jobs:

FRONTEND:
- Routes:
- Screens/components:
- API integration:
- Role navigation:

TESTING:
- Backend tests:
- Frontend tests:
- Build/static analysis:
- Browser E2E:
- Screenshots/evidence:

SECURITY:
- Tenant isolation checks:
- Permission checks:
- Financial/privacy checks:

DEFECTS:
- Critical:
- High:
- Medium/low:

ACCEPTANCE:
- Criteria passed:
- Criteria not passed:
- Evidence:

NEXT PHASE:
- Proposed scope:
```

---

# 30. FINAL PRODUCT RULE

BRATHAMATU Stocktake must be built as one coherent platform with a single trusted backend, clear tenant boundaries, role-specific experiences, reliable stock data, auditable financial controls and browser-verified workflows.

The objective is not merely to create dashboards or CRUD pages. The objective is to deliver a functioning commercial stock-taking operation: businesses onboard their catalogues, request and schedule counts, pay BRATHAMATU, receive verified stock-taking services, review results and reconcile inventory; stock takers perform controlled quantity-only work and receive authorised compensation; BRATHAMATU manages the marketplace, quality, finances, workforce and platform operations.

**No phase is complete until its backend, database, API, frontend, permissions, automated tests and real browser workflow all work together.**

---

# 31. TENANT DOMAIN, REGISTRATION AND EMAIL IMPLEMENTATION STATUS

Implementation date: 2026-11-18

## Implemented

- Tenant domain infrastructure with tenant and subdomain uniqueness.
- Domain normalisation and business-name suggestion engine.
- Atomic reservation, conflict response and expired-reservation cleanup support.
- Public domain suggestion and reservation APIs.
- Public tenant registration, verification code, resend and idempotency support.
- Multi-step tenant registration UI for business details, available link selection, explicit acceptance, email verification and success.
- Tenant owner account, owner role and membership provisioning after verification.
- Secure hashed verification codes and hashed invitation tokens.
- Mandatory initial-password flag and server-side protected-route middleware.
- Initial-password, forgot-password and reset-password API workflows.
- Queue-capable verification and invitation mailables.
- Email provider settings, test, logs and retry records/APIs.
- Super-admin domain listing, reservation, settings, allocation and blocking APIs.
- Frontend initial-password change page and login redirect to password setup.
- Team invitation UI no longer exposes or copies plaintext invitation links.
- Tenant hostname resolution for allocated `*.TENANT_BASE_DOMAIN` subdomains.
- Tenant API context is constrained to the host tenant and requires active membership (or platform-admin access).
- Unknown, nested, unallocated and inactive tenant subdomains return not found.

## Database migrations added

- `2026_11_18_101946_create_tenant_domain_infrastructure.php`
- `2026_11_18_102830_create_tenant_registration_infrastructure.php`
- `2026_11_18_103745_create_email_and_security_infrastructure.php`

## Automated verification

- Domain engine feature tests passed.
- Tenant registration feature tests passed.
- Authentication feature tests passed.
- Frontend TypeScript and Vite production build passed.

## Production requirements still requiring operational configuration

- Configure and validate `TENANT_BASE_DOMAIN`.
- Provision wildcard DNS and wildcard SSL for tenant subdomains.
- Configure real SMTP or transactional provider credentials in protected deployment configuration.
- Configure SPF, DKIM and DMARC for the production sender domain.
- Run Redis-backed queue workers and Laravel scheduler.
- Supply provider webhook endpoints and signature secrets.
- Complete browser acceptance evidence for all registration and invitation screens.
- Complete super-admin frontend domain and email dashboards.

These items are deployment and browser-validation actions, not reasons to expose placeholders or secrets in application responses.

The configured default parent domain is `communitygroup.co.ke`. Application-level
host resolution is implemented, but tenant links are not production-ready until
wildcard DNS, virtual-host acceptance and HTTPS certificates are configured and
verified at the host.

## Tenant registration API contract

- `GET /api/v1/public/domains/suggestions?business_name=...&location=...` returns available, ranked slugs and the configured parent domain.
- `POST /api/v1/public/registration` accepts business/owner details, the explicitly selected `slug`, password confirmation and an optional `Idempotency-Key`. It atomically reserves the slug and starts email verification.
- A selected slug claimed before submission returns `409 DOMAIN_CONFLICT` with refreshed suggestions; the client must ask the user to select and accept a replacement.
- `POST /api/v1/public/registration/verify` accepts only the attempt UUID and six-digit code. Verification codes are never included in API responses.
- `POST /api/v1/public/registration/resend-code` enforces server-side expiry, resend cooldown and attempt limits.
- For allocated tenant hosts, requests resolve the host slug to its active tenant. Authenticated tenant access remains restricted to active membership; an `X-Tenant-Id` that disagrees with the host is not honored.
