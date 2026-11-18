<?php

namespace App\Services\Review;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\DisputeDecision;
use App\Models\DisputeEvidence;
use App\Models\DisputeMessage;
use App\Models\ProductVariant;
use App\Models\ReconciliationAdjustment;
use App\Models\ReconciliationItem;
use App\Models\ReconciliationReport;
use App\Models\StocktakingDispute;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Models\TenantResultReview;
use App\Models\TenantResultReviewItem;
use App\Models\User;
use App\Services\Stocktake\StocktakeWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantReviewService
{
    public function __construct(private readonly StocktakeWorkflow $workflow) {}

    /**
     * Publish count results to the tenant for review.
     * Called when QA passes and the job transitions to awaiting_tenant_review.
     * Creates a tenant_result_review with per-product items from the submitted count session.
     *
     * @return array<string, mixed>
     */
    public function publishResults(StocktakeJob $job): array
    {
        if ($job->status !== 'awaiting_tenant_review') {
            throw ValidationException::withMessages(['job' => ['Results can only be published when the job is awaiting tenant review.']]);
        }

        $existing = TenantResultReview::where('job_id', $job->id)
            ->whereIn('status', ['in_progress', 'accepted', 'requested_correction', 'requested_recount', 'disputed'])
            ->first();
        if ($existing) {
            return $this->reviewPayload($existing, $job);
        }

        // Get the submitted count session entries
        $session = CountSession::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)->where('status', 'submitted')
            ->latest('id')->with(['entries.variant.product'])
            ->first();

        $review = DB::transaction(function () use ($job, $session): TenantResultReview {
            $review = TenantResultReview::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'status' => 'in_progress',
                'review_window_expires_at' => now()->addDays(7),
                'total_items' => $session?->entries->count() ?? 0,
            ]);

            if ($session) {
                foreach ($session->entries as $entry) {
                    TenantResultReviewItem::create([
                        'tenant_result_review_id' => $review->id,
                        'variant_id' => $entry->variant_id,
                        'counted_quantity' => $entry->raw_quantity,
                        'expected_quantity' => null, // blind count — expected not available to worker or in count
                        'variance' => null,
                        'status' => 'pending',
                    ]);
                }
            }

            return $review;
        });

        return $this->reviewPayload($review, $job);
    }

    /**
     * Full review payload for the tenant: items, reconciliation, disputes.
     *
     * @return array<string, mixed>
     */
    public function reviewPayload(TenantResultReview $review, StocktakeJob $job): array
    {
        $review->loadMissing(['items.variant.product', 'disputes.messages', 'disputes.decisions']);

        return [
            'review' => $this->reviewSummary($review),
            'job' => [
                'id' => $job->uuid, 'reference' => $job->reference, 'title' => $job->title,
                'status' => $job->status,
            ],
            'items' => $review->items->map(fn (TenantResultReviewItem $i) => $this->itemPayload($i))->values()->all(),
            'disputes' => $review->disputes->map(fn (StocktakingDispute $d) => $this->disputePayload($d))->values()->all(),
            'reconciliation' => $this->reconciliationForReview($review, $job),
            'expires_at' => $review->review_window_expires_at?->toIso8601String(),
        ];
    }

    /**
     * Tenant accepts the results. Transitions job → completion_confirmed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function acceptResults(StocktakeJob $job, TenantResultReview $review, array $data, User $user): array
    {
        $data = validator($data, ['notes' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($job, $review, $data, $user): array {
            $review->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'decision_notes' => $data['notes'] ?? null,
                'reviewer_id' => $user->id,
            ]);

            TenantResultReviewItem::where('tenant_result_review_id', $review->id)->update(['status' => 'accepted']);
            $review->update(['items_accepted' => $review->items()->count(), 'items_flagged' => 0]);

            $this->workflow->transition($job, 'completion_confirmed', $user, 'Tenant accepted count results.');

            return $this->reviewSummary($review->fresh());
        });
    }

    /**
     * Tenant requests corrections. Transitions job → tenant_correction_requested.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function requestCorrection(StocktakeJob $job, TenantResultReview $review, array $data, User $user): array
    {
        $data = validator($data, ['notes' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        return DB::transaction(function () use ($job, $review, $data, $user): array {
            $review->update([
                'status' => 'requested_correction',
                'decision_notes' => $data['notes'],
                'reviewer_id' => $user->id,
            ]);

            $this->workflow->transition($job, 'tenant_correction_requested', $user, 'Tenant requested corrections: '.$data['notes']);

            return $this->reviewSummary($review->fresh());
        });
    }

    /**
     * Tenant requests a recount. Transitions job → recount_required.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function requestRecount(StocktakeJob $job, TenantResultReview $review, array $data, User $user): array
    {
        $data = validator($data, ['reason' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        return DB::transaction(function () use ($job, $review, $data, $user): array {
            $review->update(['status' => 'requested_recount', 'decision_notes' => $data['reason'], 'reviewer_id' => $user->id]);

            $this->workflow->transition($job, 'recount_required', $user, 'Tenant requested recount: '.$data['reason']);

            return $this->reviewSummary($review->fresh());
        });
    }

    /**
     * Tenant raises a formal dispute. Transitions job → disputed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function raiseDispute(StocktakeJob $job, TenantResultReview $review, array $data, User $user): array
    {
        $data = validator($data, [
            'category' => ['required', 'string', 'in:accuracy,completeness,damage,billing,other'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'message' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($job, $review, $data, $user): array {
            $review->update(['status' => 'disputed', 'reviewer_id' => $user->id]);

            $dispute = StocktakingDispute::create([
                'tenant_id' => $job->tenant_id,
                'job_id' => $job->id,
                'tenant_result_review_id' => $review->id,
                'status' => 'open',
                'raised_by' => $user->id,
                'category' => $data['category'],
                'priority' => $data['priority'] ?? 'normal',
                'reason' => $data['reason'],
            ]);

            if (! empty($data['message'])) {
                DisputeMessage::create([
                    'dispute_id' => $dispute->id,
                    'sender_id' => $user->id,
                    'sender_role' => 'tenant',
                    'message' => $data['message'],
                ]);
            }

            $this->workflow->transition($job, 'disputed', $user, 'Tenant raised a dispute: '.$data['category']);

            return $this->disputePayload($dispute);
        });
    }

    /**
     * Flag a specific review item with a tenant comment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function flagItem(TenantResultReview $review, TenantResultReviewItem $item, array $data, User $user): array
    {
        $data = validator($data, [
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
        ])->validate();

        $item->update(['status' => 'flagged', 'tenant_comment' => $data['comment']]);
        $review->increment('items_flagged');

        return ['id' => $item->uuid, 'status' => 'flagged'];
    }

    /**
     * Generate a reconciliation report comparing counted vs expected quantities.
     * For blind counts, expected is not available — all items are 'unknown'.
     *
     * @return array<string, mixed>
     */
    public function generateReconciliation(TenantResultReview $review, StocktakeJob $job): array
    {
        $report = ReconciliationReport::create([
            'tenant_id' => $job->tenant_id,
            'job_id' => $job->id,
            'tenant_result_review_id' => $review->id,
            'generated_at' => now(),
            'total_items' => $review->items->count(),
            'status' => 'published',
        ]);

        $matched = 0; $variance = 0; $unknown = 0;
        foreach ($review->items as $item) {
            $type = $item->expected_quantity !== null
                ? (abs((float) $item->counted_quantity - (float) $item->expected_quantity) < 0.000001 ? 'match' : 'variance')
                : 'unknown';

            if ($type === 'match') $matched++;
            elseif ($type === 'variance') $variance++;
            else $unknown++;

            ReconciliationItem::create([
                'reconciliation_report_id' => $report->id,
                'variant_id' => $item->variant_id,
                'counted_quantity' => $item->counted_quantity,
                'expected_quantity' => $item->expected_quantity,
                'variance' => $item->expected_quantity !== null
                    ? (float) $item->counted_quantity - (float) $item->expected_quantity
                    : null,
                'type' => $type,
            ]);
        }

        $report->update(['items_matched' => $matched, 'items_with_variance' => $variance, 'items_unknown' => $unknown]);

        return $this->reconciliationPayload($report->fresh());
    }

    /**
     * Approve or reject an inventory adjustment.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function decideAdjustment(ReconciliationAdjustment $adjustment, array $data, User $user): array
    {
        $data = validator($data, [
            'status' => ['required', 'string', 'in:approved,rejected'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        $adjustment->update([
            'status' => $data['status'],
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return ['id' => $adjustment->uuid, 'status' => $adjustment->status];
    }

    /**
     * Add a message to a dispute thread.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addDisputeMessage(StocktakingDispute $dispute, array $data, User $user, string $role = 'tenant'): array
    {
        $data = validator($data, ['message' => ['required', 'string', 'max:2000']])->validate();

        $msg = DisputeMessage::create([
            'dispute_id' => $dispute->id,
            'sender_id' => $user->id,
            'sender_role' => $role,
            'message' => $data['message'],
        ]);

        return ['id' => $msg->uuid, 'message' => $msg->message, 'sender_role' => $msg->sender_role];
    }

    /**
     * Add evidence to a dispute.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function addDisputeEvidence(StocktakingDispute $dispute, array $data, User $user): array
    {
        $data = validator($data, [
            'kind' => ['required', 'string', 'in:photo,document,note,count_comparison'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $ev = DisputeEvidence::create([
            'dispute_id' => $dispute->id,
            'kind' => $data['kind'],
            'description' => $data['description'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        return ['id' => $ev->uuid, 'kind' => $ev->kind];
    }

    /**
     * Platform makes a decision on a dispute.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function decideDispute(StocktakeJob $job, StocktakingDispute $dispute, array $data, User $user): array
    {
        $data = validator($data, [
            'decision' => ['required', 'string', 'in:uphold,overturn,partial,negotiate'],
            'reasoning' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
        ])->validate();

        return DB::transaction(function () use ($job, $dispute, $data, $user): array {
            $decision = DisputeDecision::create([
                'dispute_id' => $dispute->id,
                'decided_by' => $user->id,
                'decision' => $data['decision'],
                'reasoning' => $data['reasoning'] ?? null,
                'conditions' => $data['conditions'] ?? null,
            ]);

            $dispute->update(['status' => 'resolved', 'resolved_at' => now()]);

            // Transition job back to awaiting_tenant_review for re-review (or completion)
            if ($job->status === 'disputed') {
                $this->workflow->transition($job, 'awaiting_tenant_review', $user, 'Dispute resolved: '.$data['decision']);
            }

            return $this->disputePayload($dispute->fresh());
        });
    }

    // ----- payload helpers -----

    /** @return array<string, mixed> */
    private function reviewSummary(TenantResultReview $r): array
    {
        return [
            'id' => $r->uuid, 'status' => $r->status,
            'total_items' => $r->total_items, 'items_accepted' => $r->items_accepted, 'items_flagged' => $r->items_flagged,
            'expires_at' => $r->review_window_expires_at?->toIso8601String(),
            'accepted_at' => $r->accepted_at?->toIso8601String(),
            'decision_notes' => $r->decision_notes,
        ];
    }

    /** @return array<string, mixed> */
    private function itemPayload(TenantResultReviewItem $i): array
    {
        return [
            'id' => $i->uuid, 'status' => $i->status,
            'sku' => $i->variant?->sku, 'product_name' => $i->variant?->product?->name,
            'counted_quantity' => $i->counted_quantity, 'expected_quantity' => $i->expected_quantity,
            'variance' => $i->variance, 'tenant_comment' => $i->tenant_comment,
        ];
    }

    /** @return array<string, mixed> */
    private function disputePayload(StocktakingDispute $d): array
    {
        return [
            'id' => $d->uuid, 'status' => $d->status, 'category' => $d->category, 'priority' => $d->priority,
            'reason' => $d->reason, 'raised_at' => $d->created_at?->toIso8601String(),
            'resolved_at' => $d->resolved_at?->toIso8601String(),
            'messages' => $d->relationLoaded('messages') ? $d->messages->map(fn (DisputeMessage $m) => [
                'id' => $m->uuid, 'message' => $m->message, 'sender_role' => $m->sender_role,
                'sent_at' => $m->created_at?->toIso8601String(),
            ])->values()->all() : [],
            'decisions' => $d->relationLoaded('decisions') ? $d->decisions->map(fn (DisputeDecision $dec) => [
                'id' => $dec->uuid, 'decision' => $dec->decision, 'reasoning' => $dec->reasoning,
            ])->values()->all() : [],
        ];
    }

    /** @return array<string, mixed>|null */
    private function reconciliationForReview(TenantResultReview $review, StocktakeJob $job): ?array
    {
        $report = ReconciliationReport::where('job_id', $job->id)->where('status', 'published')->latest('id')->first();
        if (! $report) return null;
        return $this->reconciliationPayload($report);
    }

    /** @return array<string, mixed> */
    private function reconciliationPayload(ReconciliationReport $r): array
    {
        $r->loadMissing(['items.variant.product']);
        return [
            'id' => $r->uuid, 'status' => $r->status,
            'total_items' => $r->total_items, 'items_matched' => $r->items_matched,
            'items_with_variance' => $r->items_with_variance, 'items_unknown' => $r->items_unknown,
            'generated_at' => $r->generated_at?->toIso8601String(),
            'items' => $r->items->map(fn (ReconciliationItem $i) => [
                'id' => $i->uuid, 'sku' => $i->variant?->sku, 'product_name' => $i->variant?->product?->name,
                'counted_quantity' => $i->counted_quantity, 'expected_quantity' => $i->expected_quantity,
                'variance' => $i->variance, 'type' => $i->type,
            ])->values()->all(),
        ];
    }
}
