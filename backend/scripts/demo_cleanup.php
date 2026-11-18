<?php

declare(strict_types=1);

/**
 * Removes the demo lifecycle jobs (and every dependent ledger/QA/review row) so
 * scripts/demo_lifecycle.php can be re-run from a clean slate.
 *
 * Usage: php scripts/demo_cleanup.php
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$defaultTitles = [
    'Q4 2026 supermarket full count',
    'Q4 2026 pharmacy shelf count',
    'Cycle count — fast movers aisle',
    'Ad-hoc spot check — returns bay',
    'November cycle count — Westlands outlet',
    'Q1 2027 dry goods sample count',
];

// Optional: pass one or more titles after the script name to clean a subset.
$subset = array_slice($argv ?? [], 1);
$titles = $subset ?: $defaultTitles;

$jobIds = DB::table('stocktake_jobs')->whereIn('title', $titles)->pluck('id')->all();

if ($jobIds === []) {
    echo "No demo lifecycle jobs found.\n";
    exit(0);
}

$assignmentIds = DB::table('job_assignments')->whereIn('job_id', $jobIds)->pluck('id')->all();
$paymentIds = DB::table('payments')->whereIn('job_id', $jobIds)->pluck('id')->all();
$sessionIds = DB::table('count_sessions')->whereIn('job_id', $jobIds)->pluck('id')->all();

DB::statement('SET FOREIGN_KEY_CHECKS=0');

foreach ([
    'count_entries', 'count_exceptions', 'count_session_locations', 'count_submission_locks',
    'count_sync_batches', 'count_sync_conflicts', 'unknown_product_reports',
] as $table) {
    if ($sessionIds !== []) {
        DB::table($table)->whereIn('session_id', $sessionIds)->delete();
    }
}
foreach (['inventory_snapshots', 'reconciliation_reports'] as $table) {
    DB::table($table)->whereIn('job_id', $jobIds)->delete();
}
if ($assignmentIds !== []) {
    DB::table('job_assignment_events')->whereIn('assignment_id', $assignmentIds)->delete();
}

foreach (['financial_reconciliation_items', 'payment_allocations', 'payment_attempts', 'payment_callbacks'] as $table) {
    if ($paymentIds !== []) {
        DB::table($table)->whereIn('payment_id', $paymentIds)->delete();
    }
}
foreach (['refunds', 'stock_taker_payouts'] as $table) {
    DB::table($table)->whereIn('job_id', $jobIds)->delete();
}
foreach ([
    'job_attendance', 'job_checklist_responses', 'job_procedure_acknowledgements',
    'job_progress_events', 'job_site_checkins', 'job_site_checkouts', 'stock_taker_earnings',
] as $table) {
    if ($assignmentIds !== []) {
        DB::table($table)->whereIn('assignment_id', $assignmentIds)->delete();
    }
}
foreach ([
    'count_sessions', 'count_device_sessions', 'discrepancies', 'financial_journal_lines',
    'financial_journals', 'job_assignments', 'job_attendance', 'job_checklists', 'job_procedures',
    'job_progress_events', 'job_site_checkins', 'job_site_checkouts', 'payments',
    'stock_taker_payouts', 'stocktake_deposits', 'stocktake_job_amendments',
    'stocktake_job_quotes', 'stocktake_job_status_history', 'stocktaking_disputes',
    'tenant_result_reviews', 'verification_tasks', 'qa_reviews', 'recount_requests',
    'schedule_occurrences',
] as $table) {
    DB::table($table)->whereIn('job_id', $jobIds)->delete();
}

DB::table('stocktake_jobs')->whereIn('id', $jobIds)->delete();

// Demo schedules are tenant-scoped and survive job cleanup, so reset them too on a full run.
if ($subset === []) {
    $scheduleIds = DB::table('stocktake_schedules')
        ->whereIn('title', ['Monthly supermarket full count', 'On-demand emergency count'])
        ->pluck('id')
        ->all();
    if ($scheduleIds !== []) {
        DB::table('schedule_occurrences')->whereIn('schedule_id', $scheduleIds)->delete();
        DB::table('stocktake_schedules')->whereIn('id', $scheduleIds)->delete();
    }
}

// Earlier aborted runs could leave session-scoped rows without their session.
foreach ([
    'count_entries', 'count_exceptions', 'count_session_locations', 'count_submission_locks',
    'count_sync_batches', 'count_sync_conflicts', 'unknown_product_reports',
] as $table) {
    DB::table($table)->whereNotExists(fn ($query) => $query->select(DB::raw(1))
        ->from('count_sessions')->whereColumn('count_sessions.id', $table.'.session_id'))
        ->delete();
}

// Payout batches are not job-scoped; remove any left without payouts.
$staleBatches = DB::table('payout_batches')
    ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('stock_taker_payouts')
        ->whereColumn('stock_taker_payouts.batch_id', 'payout_batches.id'))
    ->pluck('id')
    ->all();
if ($staleBatches !== []) {
    DB::table('payout_approvals')->whereIn('batch_id', $staleBatches)->delete();
    DB::table('payout_batches')->whereIn('id', $staleBatches)->delete();
}

DB::statement('SET FOREIGN_KEY_CHECKS=1');

echo 'Removed '.count($jobIds)." demo lifecycle job(s) and their dependent rows.\n";
