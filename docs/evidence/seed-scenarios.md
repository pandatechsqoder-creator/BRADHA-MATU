# Master-spec §23.3 seed scenarios — coverage map

Date: 2026-11-15
Sources: `backend/tests/Feature/*` (121 tests, 1472 assertions), `backend/database/seeders/*`,
`backend/scripts/demo_lifecycle.php`, `backend/scripts/demo_cleanup.php`.

| # | Scenario | Covered by | Status |
|---|----------|-----------|--------|
| 1 | Valid catalogue | `CatalogueTest::test_csv_import_is_queued_mapped_committed_and_reported`; `DemoDataSeeder` 8-product demo catalogue; browser evidence phase 04 | Covered |
| 2 | Invalid rows | `CatalogueTest::test_all_or_nothing_commit_is_blocked_while_errors_exist`; `InventoryTest::test_external_movement_import_groups_lines_dedupes_and_reports_bad_rows` | Covered |
| 3 | Duplicate SKU/barcode | `CatalogueTest::test_duplicate_rows_can_be_updated_on_a_second_import` | Covered |
| 4 | Product variants | `CatalogueTest` variant cases (attributes, counts); demo products carry variant rows | Covered |
| 5 | Unit conversion and carton/each | `CountEngineTest::test_entries_are_idempotent_and_reject_out_of_scope_or_unapproved_units`; catalogue `packaging_unit = carton` fixtures | Covered |
| 6 | Missing expected stock | `QATest` finding with type `missing`; `CountController` `observations[] = missing_label` | Covered (API + UI) |
| 7 | Surplus/shortage | `QATest::test_worker_accuracy_distinguishes_worker_error_from_expected_variance`; phase 12/13 browser evidence shows expected variance | Covered |
| 8 | Damaged and expired items | Count observations `damaged`/`expired`; `InventoryTest` expired-batch filter; phase 10 browser evidence | Covered |
| 9 | Unknown product | `CountEngineTest::test_exceptions_and_unknown_products_can_be_reported`; phase 10 browser evidence (1 unknown) | Covered |
| 10 | Stock movement during count | `InventoryTest::test_cutoff_links_movements_and_snapshot_excludes_post_cutoff_activity` | Covered |
| 11 | Independent count mismatch | `QATest::test_verification_task_samples_entries_and_records_matches_and_mismatches` | Covered |
| 12 | Recount confirms worker count | `QATest::test_approving_recount_reopens_job_for_counting`; `QATest::test_completing_review_with_recount_creates_request_and_transitions` | Covered |
| 13 | Worker count error confirmed | `QATest::test_worker_accuracy_distinguishes_worker_error_from_expected_variance` | Covered |
| 14 | Payment pending/failed/success/reversed | Pending + success in `FinanceTest` payments; reversal via `test_refund_requires_approval_and_posts_a_balanced_reversal`. Failure status exists in `PaymentService` but has no dedicated test. | Partial — failure path untested |
| 15 | Duplicate payment callback | `FinanceTest::test_sandbox_payment_requires_a_signed_callback_and_is_idempotent` (`data.duplicate = true`) | Covered |
| 16 | Partial refund | `FinanceTest::test_refund_requires_approval_and_posts_a_balanced_reversal` (KES 500 of a larger payment, balanced reversal) | Covered |
| 17 | Payout failure and retry | `PayoutTest` covers prepare → approve → process only; `PayoutService` has no failure/retry branch (sandbox always completes) | **Gap** |
| 18 | Disputed job | `TenantReviewTest::test_tenant_can_raise_dispute_with_message`; `test_platform_can_resolve_dispute_and_transition_back`; phase 13 browser evidence | Covered |
| 19 | Monthly/annual schedule | `ScheduleTest::test_tenant_can_create_a_monthly_schedule`; annual frequency accepted but not separately tested; demo monthly schedule in phase 15 evidence | Partial — annual untested |
| 20 | Month-end recurrence | Occurrence generator advances by month; no Jan-31 / leap-year fixture | **Gap** (tracked in Phase 15 notes) |
| 21 | Offline count sync conflict | `OfflineSyncTest::test_conflict_is_raised_when_offline_entry_differs_from_a_submitted_entry`; phase 11 browser evidence | Covered |

## How to run the demo data

```bash
php artisan migrate --seed            # RBAC, SOP, demo tenant/worker data, POS demo data
php scripts/demo_lifecycle.php        # builds the six lifecycle jobs through the real API
php scripts/demo_cleanup.php          # optional: removes demo jobs for a clean rerun
```

The lifecycle script logs in as the documented demo accounts (password `Password1!`) instead of using
hard-coded tokens, so no credentials live in source.

## Remaining gaps

The two gaps above are feature/test scope, not regressions: payout failure/retry has no implementation
to exercise, and month-end/leap-year recurrence (with annual schedules and review-window reminders)
belongs to Phase 15 follow-up work already listed in `docs/VERIFICATION_2026-11-15.md`.
