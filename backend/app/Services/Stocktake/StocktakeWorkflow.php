<?php

namespace App\Services\Stocktake;

use App\Models\StocktakeJob;
use App\Models\StocktakeStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every job status change goes through this service. No controller may write
 * stocktake_jobs.status directly.
 */
final class StocktakeWorkflow
{
    public const STATUSES = [
        'draft', 'awaiting_deposit', 'deposit_pending_verification', 'submitted', 'under_review',
        'needs_tenant_information', 'quote_issued', 'awaiting_quote_acceptance', 'awaiting_balance_payment',
        'payment_pending_verification', 'approved_for_execution', 'awaiting_assignment', 'assigned',
        'awaiting_worker_acceptance', 'scheduled', 'in_progress', 'paused', 'count_submitted',
        'quality_review', 'recount_required', 'awaiting_tenant_review', 'tenant_correction_requested',
        'disputed', 'completion_confirmed', 'payout_pending_authorisation', 'payout_processing',
        'payout_completed', 'closed', 'cancelled', 'refunded', 'rejected',
    ];

    public const LABELS = [
        'draft' => 'Draft',
        'awaiting_deposit' => 'Awaiting deposit',
        'deposit_pending_verification' => 'Deposit pending verification',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'needs_tenant_information' => 'Needs tenant information',
        'quote_issued' => 'Quote issued',
        'awaiting_quote_acceptance' => 'Awaiting quote acceptance',
        'awaiting_balance_payment' => 'Awaiting balance payment',
        'payment_pending_verification' => 'Payment pending verification',
        'approved_for_execution' => 'Approved for execution',
        'awaiting_assignment' => 'Awaiting assignment',
        'assigned' => 'Assigned',
        'awaiting_worker_acceptance' => 'Awaiting worker acceptance',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'paused' => 'Paused',
        'count_submitted' => 'Count submitted',
        'quality_review' => 'Quality review',
        'recount_required' => 'Recount required',
        'awaiting_tenant_review' => 'Awaiting tenant review',
        'tenant_correction_requested' => 'Tenant correction requested',
        'disputed' => 'Disputed',
        'completion_confirmed' => 'Completion confirmed',
        'payout_pending_authorisation' => 'Payout pending authorisation',
        'payout_processing' => 'Payout processing',
        'payout_completed' => 'Payout completed',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
        'rejected' => 'Rejected',
    ];

    private const TRANSITIONS = [
        'draft' => ['awaiting_deposit' => ['tenant'], 'submitted' => ['tenant'], 'cancelled' => ['tenant']],
        'awaiting_deposit' => ['deposit_pending_verification' => ['tenant'], 'submitted' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'deposit_pending_verification' => ['submitted' => ['platform', 'system'], 'awaiting_deposit' => ['platform', 'system'], 'cancelled' => ['tenant', 'platform']],
        'submitted' => ['under_review' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'under_review' => ['needs_tenant_information' => ['platform'], 'quote_issued' => ['platform'], 'rejected' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'needs_tenant_information' => ['submitted' => ['tenant'], 'under_review' => ['platform'], 'cancelled' => ['tenant']],
        'quote_issued' => ['awaiting_quote_acceptance' => ['platform'], 'under_review' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'awaiting_quote_acceptance' => ['awaiting_balance_payment' => ['tenant'], 'approved_for_execution' => ['tenant'], 'under_review' => ['tenant', 'platform'], 'cancelled' => ['tenant', 'platform']],
        'awaiting_balance_payment' => ['payment_pending_verification' => ['tenant'], 'approved_for_execution' => ['platform'], 'under_review' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'payment_pending_verification' => ['approved_for_execution' => ['platform', 'system'], 'awaiting_balance_payment' => ['platform', 'system'], 'cancelled' => ['tenant', 'platform']],
        'approved_for_execution' => ['awaiting_assignment' => ['platform'], 'under_review' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'awaiting_assignment' => ['assigned' => ['platform'], 'awaiting_worker_acceptance' => ['platform'], 'under_review' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'assigned' => ['awaiting_worker_acceptance' => ['platform'], 'awaiting_assignment' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'awaiting_worker_acceptance' => ['scheduled' => ['worker', 'platform'], 'awaiting_assignment' => ['worker', 'platform'], 'cancelled' => ['tenant', 'platform']],
        'scheduled' => ['in_progress' => ['worker', 'platform'], 'paused' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'in_progress' => ['paused' => ['platform', 'worker'], 'count_submitted' => ['worker', 'platform'], 'cancelled' => ['platform']],
        'paused' => ['in_progress' => ['platform'], 'cancelled' => ['tenant', 'platform']],
        'count_submitted' => ['quality_review' => ['platform']],
        'quality_review' => ['recount_required' => ['platform'], 'awaiting_tenant_review' => ['platform'], 'in_progress' => ['platform']],
        'recount_required' => ['in_progress' => ['platform'], 'quality_review' => ['platform'], 'disputed' => ['tenant']],
        'awaiting_tenant_review' => ['completion_confirmed' => ['tenant'], 'tenant_correction_requested' => ['tenant'], 'disputed' => ['tenant'], 'recount_required' => ['tenant']],
        'tenant_correction_requested' => ['quality_review' => ['platform']],
        'disputed' => ['quality_review' => ['platform'], 'awaiting_tenant_review' => ['platform'], 'cancelled' => ['platform']],
        'completion_confirmed' => ['payout_pending_authorisation' => ['platform'], 'closed' => ['platform']],
        'payout_pending_authorisation' => ['payout_processing' => ['platform'], 'completion_confirmed' => ['platform']],
        'payout_processing' => ['payout_completed' => ['platform'], 'payout_pending_authorisation' => ['platform']],
        'payout_completed' => ['closed' => ['platform']],
        'closed' => ['refunded' => ['platform']],
        'cancelled' => ['refunded' => ['platform']],
        'refunded' => [],
        'rejected' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function actorType(User|SystemActor $user, StocktakeJob $job): ?string
    {
        if ($user instanceof SystemActor) {
            return 'system';
        }

        if ($user->isMemberOf($job->tenant)) {
            return 'tenant';
        }

        if ($user->roles()->where('roles.scope', 'platform')->whereNull('user_roles.tenant_id')->exists()) {
            return 'platform';
        }

        if ($user->roles()->where('roles.scope', 'workforce')->whereNull('user_roles.tenant_id')->exists()) {
            return 'worker';
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function allowedTransitions(StocktakeJob $job, string $actorType): array
    {
        $map = self::TRANSITIONS[$job->status] ?? [];

        return collect($map)
            ->filter(fn (array $actors) => in_array($actorType, $actors, true))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function transition(StocktakeJob $job, string $to, User|SystemActor $actor, ?string $reason = null, array $context = []): StocktakeJob
    {
        if (! in_array($to, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Unknown job status.']]);
        }

        return DB::transaction(function () use ($job, $to, $actor, $reason, $context): StocktakeJob {
            $locked = StocktakeJob::withoutGlobalScope('tenant')
                ->whereKey($job->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;
            $actorType = $this->actorType($actor, $locked);

            if ($actorType === null) {
                abort(403, 'You are not allowed to change this job.');
            }

            $allowed = self::TRANSITIONS[$from][$to] ?? null;
            if ($allowed === null || ! in_array($actorType, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => ["The job cannot move from {$from} to {$to} for this actor."],
                ]);
            }

            $this->guard($locked, $to, $context);

            $locked->status = $to;
            match ($to) {
                'submitted' => $locked->submitted_at ??= now(),
                'under_review' => $locked->review_started_at ??= now(),
                'awaiting_quote_acceptance' => $locked->quoted_at ??= now(),
                'awaiting_balance_payment', 'approved_for_execution' => $locked->accepted_at ??= now(),
                'cancelled' => $locked->cancelled_at = now(),
                'closed' => $locked->closed_at = now(),
                default => null,
            };
            $locked->save();

            StocktakeStatusHistory::create([
                'tenant_id' => $locked->tenant_id,
                'job_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actorType,
                'actor_id' => $actor instanceof User ? $actor->id : null,
                'actor_role' => $actor instanceof User ? $this->actorRole($actor, $actorType, $locked->tenant_id) : null,
                'reason' => $reason,
                'metadata' => $context ?: null,
            ]);

            $this->audit->log(
                'stocktake.job.status_changed',
                $locked,
                $reason,
                ['status' => $from],
                ['status' => $to, 'context' => $context],
                $locked->tenant_id,
                $actor instanceof User ? $actor->id : null,
            );

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function guard(StocktakeJob $job, string $to, array $context): void
    {
        if ($to === 'awaiting_deposit') {
            abort_unless($job->deposit()->exists() || isset($context['deposit_id']), 422, 'A deposit record is required.');
        }

        if ($to === 'submitted' && $job->deposit_required) {
            $deposit = $job->deposit;
            $waived = $deposit?->status === 'waived';
            $paid = $deposit ? (float) $deposit->paid_amount : (float) $job->deposit_paid_amount;
            $required = $deposit ? (float) $deposit->amount : (float) $job->deposit_amount;

            if (! $waived && $paid + 0.001 < $required) {
                throw ValidationException::withMessages([
                    'deposit' => ['The required deposit must be received before the request is submitted.'],
                ]);
            }
        }

        if ($to === 'quote_issued') {
            $quote = $context['quote_id'] ?? null;
            if ($quote === null) {
                throw ValidationException::withMessages(['quote' => ['A quote must be issued with this transition.']]);
            }
        }

        if (in_array($to, ['awaiting_quote_acceptance', 'awaiting_balance_payment', 'approved_for_execution'], true)) {
            $quote = $job->acceptedQuote ?? $job->latestQuote;
            if ($quote === null) {
                throw ValidationException::withMessages(['quote' => ['No quote is available for this job.']]);
            }

            if ($to === 'awaiting_balance_payment' && (float) $quote->balance_due <= 0) {
                throw ValidationException::withMessages(['quote' => ['This quote has no outstanding balance.']]);
            }

            if ($to === 'approved_for_execution' && (float) $quote->balance_due > 0) {
                throw ValidationException::withMessages(['quote' => ['The balance must be paid before execution is approved.']]);
            }
        }
    }

    private function actorRole(User $actor, string $actorType, int $tenantId): ?string
    {
        $scope = match ($actorType) {
            'platform' => 'platform',
            'worker' => 'workforce',
            default => 'tenant',
        };

        return $actor->roles()
            ->where('roles.scope', $scope)
            ->when($scope === 'tenant', fn ($query) => $query->where('user_roles.tenant_id', $tenantId))
            ->when($scope !== 'tenant', fn ($query) => $query->whereNull('user_roles.tenant_id'))
            ->value('roles.slug');
    }
}
