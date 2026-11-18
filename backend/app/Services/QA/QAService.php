<?php

namespace App\Services\QA;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\Discrepancy;
use App\Models\JobAssignment;
use App\Models\QaFinding;
use App\Models\QaReview;
use App\Models\RecountRequest;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\User;
use App\Models\VerificationSample;
use App\Models\VerificationTask;
use App\Services\Stocktake\StocktakeWorkflow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QAService
{
    public function __construct(private readonly StocktakeWorkflow $workflow) {}

    /**
     * Start a QA review. Transitions the job count_submitted → quality_review.
     *
     * @return array<string, mixed>
     */
    public function startReview(StocktakeJob $job, User $reviewer): array
    {
        if (! in_array($job->status, ['count_submitted', 'quality_review'], true)) {
            throw ValidationException::withMessages(['job' => ['QA review can only start when counts are submitted.']]);
        }

        return DB::transaction(function () use ($job, $reviewer): array {
            // Reuse an in-progress review if one exists
            $existing = QaReview::where('job_id', $job->id)->where('status', 'in_progress')->first();
            if ($existing) {
                return $this->reviewPayload($existing->fresh(), $job);
            }

            if ($job->status === 'count_submitted') {
                $this->workflow->transition($job, 'quality_review', $reviewer, 'QA review started.');
            }

            $review = QaReview::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'reviewer_id' => $reviewer->id,
                'status' => 'in_progress',
                'started_at' => now(),
            ]);

            return $this->reviewPayload($review, $job);
        });
    }

    /**
     * Full review payload: entries from the submitted session + existing findings.
     *
     * @return array<string, mixed>
     */
    public function reviewPayload(QaReview $review, StocktakeJob $job): array
    {
        $review->loadMissing(['findings', 'verificationTasks.samples', 'recountRequests']);

        $session = CountSession::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->where('status', 'submitted')
            ->latest('id')
            ->with(['entries.variant.product', 'entries.rawUnit'])
            ->first();

        $entries = $session?->entries ?? collect();

        return [
            'review' => $this->reviewSummary($review),
            'job' => [
                'id' => $job->uuid,
                'reference' => $job->reference,
                'title' => $job->title,
                'status' => $job->status,
            ],
            'session_id' => $session?->uuid,
            'entries' => $entries->map(fn (CountEntry $e) => $this->entryForReview($e, $review))->values()->all(),
            'findings' => $review->findings->map(fn (QaFinding $f) => $this->findingPayload($f))->values()->all(),
            'verification_tasks' => $review->verificationTasks->map(fn (VerificationTask $t) => $this->verificationTaskPayload($t))->values()->all(),
            'recount_requests' => $review->recountRequests->map(fn (RecountRequest $r) => $this->recountPayload($r))->values()->all(),
            'worker_accuracy' => $this->workerAccuracyForJob($job),
        ];
    }

    /**
     * Record a QA finding (discrepancy) for a specific entry.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordFinding(StocktakeJob $job, QaReview $review, array $data, User $reviewer): array
    {
        $data = validator($data, [
            'entry_id' => ['nullable', 'ulid'],
            'variant_id' => ['nullable', 'ulid'],
            'type' => ['required', 'string', 'in:missing,excess,damaged,mismatched,wrong_unit,other'],
            'counted_quantity' => ['nullable', 'numeric'],
            'verified_quantity' => ['nullable', 'numeric'],
            'severity' => ['nullable', 'string', 'in:minor,moderate,major,critical'],
            'classification' => ['nullable', 'string', 'in:worker_error,system_error,expected_variance,unknown'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $entryId = isset($data['entry_id']) ? CountEntry::where('uuid', $data['entry_id'])->value('id') : null;
        $countedQty = $data['counted_quantity'] ?? ($entryId ? CountEntry::whereKey($entryId)->value('raw_quantity') : null);
        $verifiedQty = $data['verified_quantity'] ?? null;
        $variance = ($countedQty !== null && $verifiedQty !== null) ? (float) $verifiedQty - (float) $countedQty : null;

        $finding = QaFinding::create([
            'tenant_id' => $job->tenant_id,
            'qa_review_id' => $review->id,
            'entry_id' => $entryId,
            'variant_id' => isset($data['variant_id']) ? \App\Models\ProductVariant::where('uuid', $data['variant_id'])->value('id') : null,
            'type' => $data['type'],
            'counted_quantity' => $countedQty,
            'verified_quantity' => $verifiedQty,
            'variance' => $variance,
            'severity' => $data['severity'] ?? 'minor',
            'classification' => $data['classification'] ?? null,
            'notes' => $data['notes'] ?? null,
            'resolution' => 'none',
            'created_by' => $reviewer->id,
        ]);

        $review->increment('findings_count');

        // Auto-create a discrepancy record
        Discrepancy::create([
            'tenant_id' => $job->tenant_id,
            'job_id' => $job->id,
            'variant_id' => $finding->variant_id,
            'qa_finding_id' => $finding->id,
            'original_quantity' => $countedQty,
            'verified_quantity' => $verifiedQty,
            'variance' => $variance,
            'type' => $data['classification'] ?? 'unknown',
            'status' => 'open',
        ]);

        return ['id' => $finding->uuid, 'type' => $finding->type, 'severity' => $finding->severity];
    }

    /**
     * Complete a QA review with a decision.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function completeReview(StocktakeJob $job, QaReview $review, array $data, User $reviewer): array
    {
        $data = validator($data, [
            'decision' => ['required', 'string', 'in:pass,fail,recount'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'recount_reason' => ['nullable', 'string', 'max:2000'],
            'cost_responsibility' => ['nullable', 'string', 'in:platform,worker_error,tenant_request,split,undetermined'],
        ])->validate();

        return DB::transaction(function () use ($job, $review, $data, $reviewer): array {
            $review->update([
                'status' => $data['decision'] === 'pass' ? 'passed' : ($data['decision'] === 'recount' ? 'recount_required' : 'failed'),
                'decision' => $data['decision'],
                'summary' => $data['summary'] ?? null,
                'entries_reviewed' => CountEntry::withoutGlobalScope('tenant')->where('session_id', $review->session_id)->count(),
                'completed_at' => now(),
            ]);

            if ($data['decision'] === 'pass') {
                $this->workflow->transition($job, 'awaiting_tenant_review', $reviewer, 'QA review passed.');
            } elseif ($data['decision'] === 'recount') {
                // Create a recount request and transition to recount_required
                RecountRequest::create([
                    'tenant_id' => $job->tenant_id,
                    'job_id' => $job->id,
                    'qa_review_id' => $review->id,
                    'reason' => $data['recount_reason'] ?? 'QA review identified discrepancies requiring recount.',
                    'cost_responsibility' => $data['cost_responsibility'] ?? 'undetermined',
                    'status' => 'pending',
                    'requested_by' => $reviewer->id,
                ]);

                $this->workflow->transition($job, 'recount_required', $reviewer, 'QA review requires recount.');
            } else {
                // Fail without recount — still go to awaiting_tenant_review for tenant to decide
                $this->workflow->transition($job, 'awaiting_tenant_review', $reviewer, 'QA review completed with findings.');
            }

            return $this->reviewSummary($review->fresh());
        });
    }

    /**
     * Create a verification task for independent sampling.
     * Selects random entries from the submitted session for re-verification.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createVerificationTask(StocktakeJob $job, QaReview $review, array $data, User $creator): array
    {
        $data = validator($data, [
            'assigned_to' => ['nullable', 'ulid'],
            'sample_size' => ['required', 'integer', 'min:1', 'max:500'],
        ])->validate();

        $session = CountSession::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)->where('status', 'submitted')->latest('id')->first();
        if ($session === null) {
            throw ValidationException::withMessages(['job' => ['No submitted count session found.']]);
        }

        $assignedToId = isset($data['assigned_to']) ? User::where('uuid', $data['assigned_to'])->value('id') : null;

        return DB::transaction(function () use ($job, $review, $session, $data, $assignedToId, $creator): array {
            $task = VerificationTask::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'session_id' => $session->id,
                'qa_review_id' => $review->id,
                'assigned_to' => $assignedToId,
                'status' => 'pending',
                'sample_size' => $data['sample_size'],
                'created_by' => $creator->id,
            ]);

            // Select random entries for the sample
            $entries = CountEntry::withoutGlobalScope('tenant')
                ->where('session_id', $session->id)
                ->inRandomOrder()
                ->limit($data['sample_size'])
                ->get();

            foreach ($entries as $entry) {
                VerificationSample::create([
                    'verification_task_id' => $task->id,
                    'entry_id' => $entry->id,
                    'variant_id' => $entry->variant_id,
                    'original_quantity' => $entry->raw_quantity,
                    'result' => 'pending',
                ]);
            }

            return $this->verificationTaskPayload($task->fresh()->loadMissing('samples'));
        });
    }

    /**
     * Record a verification sample result (worker re-counts an item).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function recordVerificationSample(VerificationTask $task, VerificationSample $sample, array $data, User $worker): array
    {
        $data = validator($data, [
            'verified_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $original = (float) $sample->original_quantity;
        $verified = (float) $data['verified_quantity'];
        $variance = $verified - $original;
        $result = abs($variance) < 0.000001 ? 'match' : 'mismatch';

        $sample->update([
            'verified_quantity' => $verified,
            'variance' => $variance,
            'result' => $result,
            'notes' => $data['notes'] ?? null,
            'verified_by' => $worker->id,
            'verified_at' => now(),
        ]);

        $task->increment('items_verified');
        if ($result === 'match') {
            $task->increment('items_matched');
        } else {
            $task->increment('items_mismatched');
        }

        return ['id' => $sample->uuid, 'result' => $result, 'variance' => $variance];
    }

    /**
     * Complete a verification task.
     *
     * @return array<string, mixed>
     */
    public function completeVerificationTask(VerificationTask $task, User $user): array
    {
        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
            'summary' => "{$task->items_matched} matched, {$task->items_mismatched} mismatched out of {$task->items_verified} verified.",
        ]);

        return $this->verificationTaskPayload($task->fresh());
    }

    /**
     * Request a recount independently of a QA review (e.g. tenant-initiated).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function requestRecount(StocktakeJob $job, array $data, User $requester): array
    {
        $data = validator($data, [
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'cost_responsibility' => ['nullable', 'string', 'in:platform,worker_error,tenant_request,split,undetermined'],
            'qa_review_id' => ['nullable', 'ulid'],
        ])->validate();

        $reviewId = isset($data['qa_review_id']) ? QaReview::where('uuid', $data['qa_review_id'])->value('id') : null;

        $request = RecountRequest::create([
            'tenant_id' => $job->tenant_id,
            'job_id' => $job->id,
            'qa_review_id' => $reviewId,
            'reason' => $data['reason'],
            'cost_responsibility' => $data['cost_responsibility'] ?? 'undetermined',
            'status' => 'pending',
            'requested_by' => $requester->id,
        ]);

        return $this->recountPayload($request);
    }

    /**
     * Approve a recount — re-opens the job for counting (recount_required → in_progress).
     *
     * @return array<string, mixed>
     */
    public function approveRecount(StocktakeJob $job, RecountRequest $request, User $approver): array
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['recount' => ['Recount request is not pending.']]);
        }

        return DB::transaction(function () use ($job, $request, $approver): array {
            $request->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            if ($job->status === 'recount_required') {
                $this->workflow->transition($job, 'in_progress', $approver, 'Recount approved.');
            }

            return $this->recountPayload($request->fresh());
        });
    }

    /**
     * Complete a recount — transition job back to count_submitted (recount count is done).
     *
     * @return array<string, mixed>
     */
    public function completeRecount(StocktakeJob $job, RecountRequest $request, User $user): array
    {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages(['recount' => ['Recount must be approved first.']]);
        }

        return DB::transaction(function () use ($job, $request, $user): array {
            $request->update(['status' => 'completed', 'completed_at' => now()]);

            if ($job->status === 'in_progress') {
                $this->workflow->transition($job, 'count_submitted', $user, 'Recount completed; counts submitted.');
            }

            return $this->recountPayload($request->fresh());
        });
    }

    /**
     * Resolve a discrepancy — classify and set resolution.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolveDiscrepancy(Discrepancy $discrepancy, array $data, User $user): array
    {
        $data = validator($data, [
            'type' => ['nullable', 'string', 'in:worker_error,system_error,expected_variance,unknown'],
            'resolution' => ['required', 'string', 'max:2000'],
        ])->validate();

        $discrepancy->update([
            'type' => $data['type'] ?? $discrepancy->type,
            'status' => 'resolved',
            'resolution' => $data['resolution'],
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        return ['id' => $discrepancy->uuid, 'status' => 'resolved'];
    }

    /**
     * Worker accuracy metrics for a job — what percentage of entries had
     * discrepancies classified as worker_error vs expected_variance.
     *
     * @return array<string, mixed>
     */
    public function workerAccuracyForJob(StocktakeJob $job): array
    {
        $sessions = CountSession::withoutGlobalScope('tenant')->where('job_id', $job->id)->pluck('id');
        $totalEntries = CountEntry::withoutGlobalScope('tenant')->whereIn('session_id', $sessions)->count();

        $discrepancies = Discrepancy::where('job_id', $job->id)->get();
        $workerErrors = $discrepancies->where('type', 'worker_error')->count();
        $expectedVariance = $discrepancies->where('type', 'expected_variance')->count();
        $systemErrors = $discrepancies->where('type', 'system_error')->count();

        $accuracyRate = $totalEntries > 0
            ? round((1 - ($workerErrors / $totalEntries)) * 100, 2)
            : null;

        return [
            'total_entries' => $totalEntries,
            'total_discrepancies' => $discrepancies->count(),
            'worker_errors' => $workerErrors,
            'expected_variance' => $expectedVariance,
            'system_errors' => $systemErrors,
            'accuracy_rate' => $accuracyRate,
            'note' => 'Expected stock variance is not treated as worker error. Only discrepancies classified as worker_error reduce accuracy.',
        ];
    }

    // ----- payload helpers -----

    /** @return array<string, mixed> */
    private function reviewSummary(QaReview $review): array
    {
        return [
            'id' => $review->uuid,
            'status' => $review->status,
            'decision' => $review->decision,
            'summary' => $review->summary,
            'findings_count' => $review->findings_count,
            'entries_reviewed' => $review->entries_reviewed,
            'started_at' => $review->started_at?->toIso8601String(),
            'completed_at' => $review->completed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function entryForReview(CountEntry $entry, QaReview $review): array
    {
        $existingFinding = $review->findings->firstWhere('entry_id', $entry->id);

        return [
            'id' => $entry->uuid,
            'variant_id' => $entry->variant?->uuid,
            'sku' => $entry->variant?->sku,
            'product_name' => $entry->variant?->product?->name,
            'raw_quantity' => $entry->raw_quantity,
            'unit' => $entry->rawUnit?->name,
            'observations' => $entry->observations ?? [],
            'notes' => $entry->notes,
            'has_finding' => $existingFinding !== null,
            'finding' => $existingFinding ? $this->findingPayload($existingFinding) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function findingPayload(QaFinding $f): array
    {
        return [
            'id' => $f->uuid,
            'type' => $f->type,
            'severity' => $f->severity,
            'classification' => $f->classification,
            'counted_quantity' => $f->counted_quantity,
            'verified_quantity' => $f->verified_quantity,
            'variance' => $f->variance,
            'notes' => $f->notes,
            'resolution' => $f->resolution,
        ];
    }

    /** @return array<string, mixed> */
    private function verificationTaskPayload(VerificationTask $t): array
    {
        return [
            'id' => $t->uuid,
            'status' => $t->status,
            'sample_size' => $t->sample_size,
            'items_verified' => $t->items_verified,
            'items_matched' => $t->items_matched,
            'items_mismatched' => $t->items_mismatched,
            'summary' => $t->summary,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'samples' => $t->relationLoaded('samples') ? $t->samples->map(fn (VerificationSample $s) => [
                'id' => $s->uuid, 'result' => $s->result, 'original_quantity' => $s->original_quantity,
                'verified_quantity' => $s->verified_quantity, 'variance' => $s->variance,
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function recountPayload(RecountRequest $r): array
    {
        return [
            'id' => $r->uuid,
            'status' => $r->status,
            'reason' => $r->reason,
            'cost_responsibility' => $r->cost_responsibility,
            'requested_at' => $r->created_at?->toIso8601String(),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'decision_reason' => $r->decision_reason,
        ];
    }
}
