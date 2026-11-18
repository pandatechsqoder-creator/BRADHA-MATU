# Phase 12 — QA, verification and recount, browser walkthrough

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `supervisor@brathamatu.test` (platform supervisor, `qa.review`)
URL: `/platform/stocktaking-jobs/01M7FK9JK3TKWXHV0B2SS2RSJN/qa`

## Steps

1. Signed in through the login form as the platform supervisor.
2. Opened the QA review for job `STJ-20261115-T9BPTS` ("Q1 2027 dry goods sample count").
3. Observed the review status, worker accuracy panel, count entry table and existing finding.
4. Inspected the verification task form (sample size 5) and the finding form entry points.

## Captured state

- Heading `QA review`, subtitle `STJ-20261115-T9BPTS · Q1 2027 dry goods sample count`.
- Review status **in progress**, **1 findings**, **0 entries reviewed** of **6 total entries**.
- Worker accuracy **100%**, 0 worker errors, **1 expected variance**, 0 system errors.
- Explanatory copy rendered: "Expected stock variance is not treated as worker error. Only discrepancies
  classified as worker_error reduce accuracy."
- Actions available: **Log finding**, **Complete review**.
- Count entries table lists all six SKUs (`BRE-400 40`, `OIL-1L 52`, `MAI-2KG 64`, `MIL-500 76`,
  `RIC-5KG 88`, `SOA-800 100`) each with a `Log` action and `good` flag.
- Findings section: `expected_variance`, `missing`, `variance: -2.000000`, severity `minor`,
  note "Two units unaccounted for during the delivery window; treated as expected variance."
- **Create verification task** form present with sample size input and **Create sampling task** action.

## Result

Pass. QA review, discrepancy classification, accuracy accounting that excludes expected variance,
finding display and independent verification task creation all render against real data and are operable.
