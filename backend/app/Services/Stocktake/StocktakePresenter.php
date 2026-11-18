<?php

namespace App\Services\Stocktake;

use App\Models\Payment;
use App\Models\StocktakeJob;
use App\Models\StocktakeJobAmendment;
use App\Models\StocktakeQuote;
use App\Models\StocktakeRequestDeclaration;
use App\Models\StocktakeStatusHistory;
use App\Models\User;
use App\Services\Payments\PaymentService;

final class StocktakePresenter
{
    public function __construct(
        private readonly StocktakeWorkflow $workflow,
        private readonly StocktakeDepositService $deposits,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(StocktakeJob $job, User $viewer, bool $platform = false): array
    {
        $quote = $this->activeQuote($job);

        return [
            'id' => $job->uuid,
            'reference' => $job->reference,
            'title' => $job->title,
            'status' => $job->status,
            'status_label' => StocktakeWorkflow::LABELS[$job->status] ?? ucfirst(str_replace('_', ' ', $job->status)),
            'job_type' => $job->job_type,
            'priority' => $job->priority,
            'count_method' => $job->count_method,
            'scope_label' => $job->scope['label'] ?? 'All stock locations',
            'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
            'deadline_at' => $job->deadline_at?->toIso8601String(),
            'required_team_size' => $job->required_team_size,
            'currency' => $job->currency,
            'provisional_total' => (float) ($job->provisional_estimate['total'] ?? 0),
            'quote_total' => $job->quote_total !== null ? (float) $job->quote_total : null,
            'quote_status' => $quote?->status,
            'deposit_required' => $job->deposit_required,
            'deposit_amount' => (float) $job->deposit_amount,
            'deposit_paid' => (float) $job->deposit_paid_amount,
            'submitted_at' => $job->submitted_at?->toIso8601String(),
            'accepted_at' => $job->accepted_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'requester' => $platform ? [
                'name' => $job->requester?->name,
                'email' => $job->requester?->email,
            ] : null,
            'tenant' => $platform ? [
                'id' => $job->tenant?->uuid,
                'name' => $job->tenant?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantPayload(StocktakeJob $job, User $viewer): array
    {
        $this->load($job);
        $quote = $this->activeQuote($job);
        $permissions = $this->tenantPermissions($job, $viewer, $quote);

        return [
            ...$this->base($job, $viewer),
            'quote' => $quote ? $this->tenantQuote($quote) : null,
            'deposit' => $this->deposits->payload($job),
            'balance' => $this->payments->balanceSummary($job),
            'payments' => $this->payments->jobPayments($job),
            'amendments' => $job->amendments->map(fn (StocktakeJobAmendment $amendment) => $this->amendmentPayload($amendment))->values()->all(),
            'permissions' => $permissions,
            'internal' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platformPayload(StocktakeJob $job, User $viewer): array
    {
        $this->load($job);
        $quote = $job->latestQuote;

        return [
            ...$this->base($job, $viewer),
            'tenant' => [
                'id' => $job->tenant?->uuid,
                'name' => $job->tenant?->name,
                'industry' => $job->tenant?->industry,
            ],
            'requester' => [
                'name' => $job->requester?->name,
                'email' => $job->requester?->email,
            ],
            'quote' => $quote ? $this->platformQuote($quote) : null,
            'quotes' => $job->quotes->sortByDesc('version')->map(fn (StocktakeQuote $item) => $this->platformQuote($item))->values()->all(),
            'deposit' => $this->deposits->payload($job, true, true),
            'balance' => $this->payments->balanceSummary($job),
            'payments' => $this->payments->jobPayments($job, true),
            'amendments' => $job->amendments->sortByDesc('id')->map(fn (StocktakeJobAmendment $amendment) => $this->amendmentPayload($amendment))->values()->all(),
            'permissions' => $this->platformPermissions($job, $viewer, $quote),
            'internal' => [
                'worker_compensation_budget' => $quote ? (float) $quote->worker_compensation_budget : null,
                'worker_compensation_breakdown' => $quote?->worker_compensation_breakdown,
                'platform_fee' => $quote ? (float) $quote->platform_fee : null,
                'margin_amount' => $quote ? (float) $quote->margin_amount : null,
                'internal_notes' => $quote?->internal_notes,
                'deposit_policy' => $job->deposit ? $job->deposit->policy : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function base(StocktakeJob $job, User $viewer): array
    {
        $declarations = $job->requestDeclarations;
        $latestDeclaration = $declarations->first();

        return [
            'id' => $job->uuid,
            'reference' => $job->reference,
            'title' => $job->title,
            'description' => $job->description,
            'status' => $job->status,
            'status_label' => StocktakeWorkflow::LABELS[$job->status] ?? ucfirst(str_replace('_', ' ', $job->status)),
            'job_type' => $job->job_type,
            'priority' => $job->priority,
            'business_criticality' => $job->business_criticality,
            'count_method' => $job->count_method,
            'expected_duration_minutes' => $job->expected_duration_minutes,
            'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
            'deadline_at' => $job->deadline_at?->toIso8601String(),
            'required_team_size' => $job->required_team_size,
            'scope' => $job->scope,
            'product_scope' => $job->product_scope,
            'product_scope_payload' => $job->product_scope_payload,
            'requirements' => [
                'industry' => $job->industry_requirements,
                'safety' => $job->safety_requirements,
                'access' => $job->access_requirements,
                'stock_movement_cutoff' => $job->stock_movement_cutoff,
                'team_size' => $job->required_team_size,
                'skills' => $job->required_skills,
                'qualifications' => $job->required_qualifications,
                'evidence' => $job->required_evidence,
                'signoffs' => $job->signoff_requirements,
            ],
            'sop' => [
                'template' => $job->sop_template,
                'version' => $job->sop_version,
                'instructions' => $job->custom_instructions,
            ],
            'attachments' => $job->attachments,
            'catalogue' => [
                'version_id' => $job->catalogueVersion?->uuid,
                'version_number' => $job->catalogueVersion?->version_number,
                'product_count' => $job->catalogue_product_count,
            ],
            'request_declaration' => $latestDeclaration ? $this->requestDeclarationPayload($latestDeclaration) : null,
            'request_declaration_history' => $declarations->map(
                fn ($declaration) => $this->requestDeclarationPayload($declaration),
            )->values()->all(),
            'inventory_snapshot' => $job->inventorySnapshot ? [
                'id' => $job->inventorySnapshot->uuid,
                'reference' => $job->inventorySnapshot->reference,
                'snapshot_at' => $job->inventorySnapshot->snapshot_at?->toIso8601String(),
                'checksum' => $job->inventorySnapshot->checksum,
            ] : null,
            'provisional_estimate' => $job->provisional_estimate,
            'deposit_required' => $job->deposit_required,
            'deposit_amount' => (float) $job->deposit_amount,
            'deposit_paid_amount' => (float) $job->deposit_paid_amount,
            'deposit_due_at' => $job->deposit_due_at?->toIso8601String(),
            'quote_total' => $job->quote_total !== null ? (float) $job->quote_total : null,
            'currency' => $job->currency,
            'version' => $job->version,
            'submitted_at' => $job->submitted_at?->toIso8601String(),
            'review_started_at' => $job->review_started_at?->toIso8601String(),
            'quoted_at' => $job->quoted_at?->toIso8601String(),
            'accepted_at' => $job->accepted_at?->toIso8601String(),
            'cancelled_at' => $job->cancelled_at?->toIso8601String(),
            'cancellation' => $job->cancellation,
            'closed_at' => $job->closed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'updated_at' => $job->updated_at?->toIso8601String(),
            'timeline' => $job->statusHistory->map(fn (StocktakeStatusHistory $history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'to_status_label' => StocktakeWorkflow::LABELS[$history->to_status] ?? $history->to_status,
                'actor_type' => $history->actor_type,
                'actor' => $history->actor?->name,
                'reason' => $history->reason,
                'created_at' => $history->created_at?->toIso8601String(),
            ])->values()->all(),
            'allowed_transitions' => $this->workflow->allowedTransitions(
                $job,
                $viewer->isMemberOf($job->tenant) ? 'tenant' : 'platform',
            ),
        ];
    }

    private function load(StocktakeJob $job): void
    {
        $job->loadMissing([
            'tenant', 'requester', 'catalogueVersion', 'inventorySnapshot',
            'statusHistory.actor', 'amendments', 'quotes', 'deposit',
            'requestDeclarations.declaredBy', 'requestDeclarations.catalogueVersion',
        ]);
    }

    private function activeQuote(StocktakeJob $job): ?StocktakeQuote
    {
        if ($job->relationLoaded('quotes')) {
            return $job->quotes->sortByDesc('version')->first(
                fn (StocktakeQuote $quote) => in_array($quote->status, ['issued', 'accepted'], true),
            ) ?? $job->quotes->sortByDesc('version')->first();
        }

        return $job->quotes()->orderByDesc('version')->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantQuote(StocktakeQuote $quote): array
    {
        return [
            'id' => $quote->uuid,
            'number' => $quote->number,
            'version' => $quote->version,
            'status' => $quote->status,
            'currency' => $quote->currency,
            'base_service_charge' => (float) $quote->base_service_charge,
            'scope_complexity_charge' => (float) $quote->scope_complexity_charge,
            'travel_expenses' => (float) $quote->travel_expenses,
            'other_expenses' => (float) $quote->other_expenses,
            'discount' => (float) $quote->discount,
            'subtotal' => (float) $quote->subtotal,
            'tax_rate' => (float) $quote->tax_rate,
            'tax' => (float) $quote->tax,
            'total' => (float) $quote->total,
            'deposit_amount' => (float) $quote->deposit_amount,
            'balance_due' => (float) $quote->balance_due,
            'notes' => $quote->notes,
            'valid_until' => $quote->valid_until?->toIso8601String(),
            'issued_at' => $quote->issued_at?->toIso8601String(),
            'accepted_at' => $quote->accepted_at?->toIso8601String(),
            'decline_reason' => $quote->decline_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function platformQuote(StocktakeQuote $quote): array
    {
        return [
            ...$this->tenantQuote($quote),
            'job_status' => $quote->job?->status,
            'worker_compensation_budget' => (float) $quote->worker_compensation_budget,
            'worker_compensation_breakdown' => $quote->worker_compensation_breakdown,
            'platform_fee' => (float) $quote->platform_fee,
            'margin_amount' => (float) $quote->margin_amount,
            'internal_notes' => $quote->internal_notes,
            'issued_by' => $quote->issuedBy?->name,
            'superseded_at' => $quote->superseded_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function amendmentPayload(StocktakeJobAmendment $amendment): array
    {
        return [
            'id' => $amendment->uuid,
            'status' => $amendment->status,
            'reason' => $amendment->reason,
            'changes' => $amendment->changes,
            'financial_impact' => (float) $amendment->financial_impact,
            'worker_consent_required' => $amendment->worker_consent_required,
            'requested_at' => $amendment->created_at?->toIso8601String(),
            'decided_at' => $amendment->decided_at?->toIso8601String(),
            'decision_reason' => $amendment->decision_reason,
            'applied_at' => $amendment->applied_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function tenantPermissions(StocktakeJob $job, User $viewer, ?StocktakeQuote $quote): array
    {
        $canRequest = $viewer->hasPermission('stocktake.request.create', $job->tenant_id);
        $canSubmit = $viewer->hasPermission('stocktake.request.submit', $job->tenant_id);
        $canCancel = $viewer->hasPermission('stocktake.request.cancel', $job->tenant_id);
        $canQuote = $viewer->hasPermission('stocktake.quote.accept', $job->tenant_id);
        $canPay = $viewer->hasPermission('finance.payment.record', $job->tenant_id);

        $paid = (float) $job->deposit_paid_amount;
        $depositOutstanding = (float) $job->deposit_amount > $paid && $job->deposit?->status !== 'waived';
        $balance = $this->payments->balanceSummary($job);
        $balanceOutstanding = $balance !== null && (float) $balance['due'] > 0 && in_array($job->status, [
            'awaiting_balance_payment', 'payment_pending_verification',
        ], true);
        $inFlight = Payment::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->whereIn('status', ['pending', 'processing'])
            ->pluck('purpose')
            ->all();

        return [
            'can_edit' => $canRequest && in_array($job->status, ['draft', 'needs_tenant_information'], true),
            'can_submit' => $canSubmit && in_array($job->status, ['draft', 'needs_tenant_information'], true),
            'can_cancel' => $canCancel && in_array($job->status, [
                'draft', 'awaiting_deposit', 'deposit_pending_verification', 'submitted', 'under_review',
                'needs_tenant_information', 'quote_issued', 'awaiting_quote_acceptance', 'awaiting_balance_payment',
                'payment_pending_verification', 'approved_for_execution', 'awaiting_assignment',
            ], true),
            'can_accept_quote' => $canQuote && $job->status === 'awaiting_quote_acceptance' && $quote?->status === 'issued',
            'can_decline_quote' => $canQuote && $job->status === 'awaiting_quote_acceptance' && $quote?->status === 'issued',
            'can_pay_deposit' => $canPay && $depositOutstanding && ! in_array('deposit', $inFlight, true) && in_array($job->status, [
                'awaiting_deposit', 'deposit_pending_verification', 'awaiting_quote_acceptance', 'awaiting_balance_payment',
            ], true),
            'can_pay_balance' => $canPay && $balanceOutstanding && ! in_array('balance', $inFlight, true),
            'can_amend' => $canRequest && in_array($job->status, [
                'submitted', 'under_review', 'quote_issued', 'awaiting_quote_acceptance',
                'awaiting_balance_payment', 'approved_for_execution', 'awaiting_assignment',
            ], true),
            'can_view_quote' => $quote !== null && (
                $viewer->hasPermission('stocktake.quote.view', $job->tenant_id)
                || $viewer->hasPermission('stocktake.request.create', $job->tenant_id)
            ),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function platformPermissions(StocktakeJob $job, User $viewer, ?StocktakeQuote $quote): array
    {
        $canReview = $viewer->hasPermission('stocktake.job.review');
        $canIssue = $viewer->hasPermission('stocktake.quote.issue');
        $canFinance = $viewer->hasPermission('finance.payment.record');

        $pendingPayment = Payment::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->where('status', 'pending')
            ->whereIn('provider', ['bank', 'manual'])
            ->exists();

        return [
            'can_start_review' => $canReview && $job->status === 'submitted',
            'can_issue_quote' => $canIssue && in_array($job->status, ['submitted', 'under_review', 'needs_tenant_information'], true),
            'can_verify_payment' => $canFinance && $pendingPayment,
            'can_waive_deposit' => $canFinance && $job->status === 'awaiting_deposit' && (float) $job->deposit_paid_amount <= 0,
            'can_decide_amendment' => $canReview && $job->amendments->contains(fn (StocktakeJobAmendment $amendment) => $amendment->status === 'pending'),
            'can_approve_execution' => $canReview && $job->status === 'awaiting_balance_payment' && $quote !== null,
            'can_send_information_request' => $canReview && $job->status === 'under_review',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDeclarationPayload(StocktakeRequestDeclaration $declaration): array
    {
        return [
            'id' => $declaration->uuid,
            'revision' => $declaration->revision,
            'declaration_text' => $declaration->declaration_text,
            'exceptions' => $declaration->exceptions,
            'accepted_at' => $declaration->accepted_at?->toIso8601String(),
            'declared_by' => $declaration->declaredBy?->name,
            'catalogue_version_number' => $declaration->catalogueVersion?->version_number,
        ];
    }
}
