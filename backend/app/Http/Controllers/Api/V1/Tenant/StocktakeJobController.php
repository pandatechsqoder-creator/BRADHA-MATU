<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\StocktakeDeposit;
use App\Models\StocktakeJob;
use App\Models\StocktakeJobAmendment;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentService;
use App\Services\Stocktake\StocktakeDepositService;
use App\Services\Stocktake\StocktakePolicyService;
use App\Services\Stocktake\StocktakePresenter;
use App\Services\Stocktake\StocktakeQuoteService;
use App\Services\Stocktake\StocktakeRequestService;
use App\Services\Stocktake\StocktakeWorkflow;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StocktakeJobController extends Controller
{
    use ChecksTenantAccess;

    private const VIEW_PERMISSIONS = [
        'stocktake.request.create', 'stocktake.quote.view', 'stocktake.results.review', 'finance.invoice.view', 'report.view',
    ];

    private const CANCELLABLE = [
        'draft', 'awaiting_deposit', 'deposit_pending_verification', 'submitted', 'under_review',
        'needs_tenant_information', 'quote_issued', 'awaiting_quote_acceptance', 'awaiting_balance_payment',
        'payment_pending_verification', 'approved_for_execution', 'awaiting_assignment',
    ];

    private const AMENDABLE = [
        'submitted', 'under_review', 'quote_issued', 'awaiting_quote_acceptance',
        'awaiting_balance_payment', 'approved_for_execution', 'awaiting_assignment',
    ];

    public function index(Request $request, Tenant $tenant, StocktakePresenter $presenter): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, self::VIEW_PERMISSIONS);

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'critical'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StocktakeJob::query()
            ->where('tenant_id', $tenant->id)
            ->with(['requester', 'latestQuote', 'deposit'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id');

        $page = $query->paginate(20);

        $statusCounts = StocktakeJob::query()
            ->where('tenant_id', $tenant->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $buckets = [
            'drafts' => (int) ($statusCounts['draft'] ?? 0),
            'awaiting_deposit' => (int) ($statusCounts['awaiting_deposit'] ?? 0) + (int) ($statusCounts['deposit_pending_verification'] ?? 0),
            'with_platform' => collect([
                'submitted', 'under_review', 'needs_tenant_information', 'quote_issued',
                'awaiting_quote_acceptance', 'awaiting_balance_payment', 'payment_pending_verification',
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
            'active' => collect([
                'approved_for_execution', 'awaiting_assignment', 'assigned', 'awaiting_worker_acceptance',
                'scheduled', 'in_progress', 'paused', 'count_submitted', 'quality_review', 'recount_required',
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
            'closed' => collect([
                'awaiting_tenant_review', 'tenant_correction_requested', 'disputed', 'completion_confirmed',
                'payout_pending_authorisation', 'payout_processing', 'payout_completed', 'closed',
                'cancelled', 'refunded', 'rejected',
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
        ];

        return ApiResponse::success(
            collect($page->items())->map(fn (StocktakeJob $job) => $presenter->summary($job, $request->user()))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'status_counts' => $statusCounts,
                'buckets' => $buckets,
            ],
        );
    }

    public function estimate(Request $request, Tenant $tenant, StocktakeRequestService $requests): JsonResponse
    {
        $this->assertCan($request, $tenant, 'stocktake.request.create');

        $data = $request->validate($this->scopeRules());

        return ApiResponse::success($requests->estimate($tenant, $data));
    }

    public function store(Request $request, Tenant $tenant, StocktakeRequestService $requests, StocktakePresenter $presenter): JsonResponse
    {
        $this->assertCan($request, $tenant, 'stocktake.request.create');

        $data = $request->validate($this->rules());
        $job = $requests->create($tenant, $request->user(), $data);

        return ApiResponse::success($presenter->tenantPayload($job, $request->user()), 201);
    }

    public function show(Request $request, Tenant $tenant, StocktakeJob $job, StocktakePresenter $presenter): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, self::VIEW_PERMISSIONS);
        $this->assertBelongsToTenant($tenant, $job);

        return ApiResponse::success($presenter->tenantPayload($job, $request->user()));
    }

    public function update(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeRequestService $requests,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.request.create');
        $this->assertBelongsToTenant($tenant, $job);

        if (! in_array($job->status, ['draft', 'needs_tenant_information'], true)) {
            throw ValidationException::withMessages(['status' => ['Only draft requests or requests awaiting your information can be edited. Use an amendment after submission.']]);
        }

        $data = $request->validate($this->rules(partial: true));
        $job = $requests->update($job, $data);

        return ApiResponse::success($presenter->tenantPayload($job, $request->user()));
    }

    public function submit(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeRequestService $requests,
        StocktakeDepositService $deposits,
        StocktakeWorkflow $workflow,
        StocktakePresenter $presenter,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.request.submit');
        $this->assertBelongsToTenant($tenant, $job);

        if (! in_array($job->status, ['draft', 'needs_tenant_information'], true)) {
            throw ValidationException::withMessages(['status' => ['This request cannot be submitted from its current status.']]);
        }

        $readiness = $requests->catalogueReadiness($tenant);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'catalogue' => $readiness['reasons'] ?: ['Your catalogue is not ready for a stock take.'],
            ]);
        }

        $validated = $request->validate([
            'request_declaration.confirm_accuracy' => ['accepted'],
            'request_declaration.exceptions' => ['nullable', 'string', 'max:2000'],
        ]);
        $declarationData = $validated['request_declaration'];
        $requests->declareRequest($job, $request->user(), $declarationData, $request->userAgent(), $request->ip());

        if ($job->status === 'draft') {
            $requirement = $deposits->requirement($job);
            if ($requirement['required']) {
                $deposit = $deposits->requireFor($job->fresh(), $request->user());
                $job = $workflow->transition($job, 'awaiting_deposit', $request->user(), 'Deposit required before review.', [
                    'deposit_id' => $deposit->uuid,
                    'deposit_amount' => (float) $deposit->amount,
                ]);
                $audit->log('stocktake.job.submitted', $job, null, null, [
                    'reference' => $job->reference,
                    'deposit_required' => (float) $deposit->amount,
                ]);

                return ApiResponse::success($presenter->tenantPayload($job->fresh(), $request->user()));
            }
        }

        $job = $workflow->transition($job, 'submitted', $request->user(), 'Request submitted to BRATHAMATU.');
        $audit->log('stocktake.job.submitted', $job, null, null, ['reference' => $job->reference]);

        return ApiResponse::success($presenter->tenantPayload($job->fresh(), $request->user()));
    }

    public function payDeposit(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeDepositService $deposits,
        PaymentService $payments,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'finance.payment.record');
        $this->assertBelongsToTenant($tenant, $job);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['mpesa', 'card', 'bank', 'manual'])],
            'phone' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $deposits->recordPayment($job->fresh(), $data, $request->user(), $request->header('Idempotency-Key'));

        return ApiResponse::success([
            'payment' => $payments->payload($payment),
            'job' => $presenter->tenantPayload($job->fresh(), $request->user()),
        ], 201);
    }

    public function pay(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        PaymentService $payments,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'finance.payment.record');
        $this->assertBelongsToTenant($tenant, $job);

        $data = $request->validate([
            'purpose' => ['required', Rule::in(['deposit', 'balance'])],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['mpesa', 'card', 'bank', 'manual'])],
            'phone' => ['nullable', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $payments->initiate($job->fresh(), $data, $request->user(), $request->header('Idempotency-Key'));

        return ApiResponse::success([
            'payment' => $payments->payload($payment),
            'job' => $presenter->tenantPayload($job->fresh(), $request->user()),
        ], 201);
    }

    public function simulatePayment(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        string $payment,
        PaymentService $payments,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'finance.payment.record');
        $this->assertBelongsToTenant($tenant, $job);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(['success', 'failure'])],
        ]);

        $record = Payment::withoutGlobalScope('tenant')
            ->where('uuid', $payment)
            ->where('job_id', $job->id)
            ->firstOrFail();

        $result = $payments->simulate($record, $data['outcome'], $request->user());

        return ApiResponse::success([
            'result' => $result,
            'job' => $presenter->tenantPayload($job->fresh(), $request->user()),
        ]);
    }

    public function acceptQuote(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeQuoteService $quotes,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.quote.accept');
        $this->assertBelongsToTenant($tenant, $job);

        $quotes->accept($job, $request->user());

        return ApiResponse::success($presenter->tenantPayload($job->fresh(), $request->user()));
    }

    public function declineQuote(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeQuoteService $quotes,
        StocktakePresenter $presenter,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.quote.accept');
        $this->assertBelongsToTenant($tenant, $job);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $quotes->decline($job, $request->user(), $data['reason']);

        return ApiResponse::success($presenter->tenantPayload($job->fresh(), $request->user()));
    }

    public function cancel(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakeWorkflow $workflow,
        StocktakePolicyService $policies,
        StocktakePresenter $presenter,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.request.cancel');
        $this->assertBelongsToTenant($tenant, $job);

        if (! in_array($job->status, self::CANCELLABLE, true)) {
            throw ValidationException::withMessages(['status' => ['This request can no longer be cancelled.']]);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        $policy = $policies->policy();
        $feePercent = (float) $policy->cancellation_fee_percent;
        $fee = 0.0;
        if (in_array($job->status, ['approved_for_execution', 'awaiting_assignment'], true) && $job->quote_total !== null) {
            $fee = round((float) $job->quote_total * ($feePercent / 100), 2);
        }

        $priorStatus = $job->status;
        $deposit = StocktakeDeposit::query()->where('job_id', $job->id)->first();
        $paid = $deposit ? (float) $deposit->paid_amount : (float) $job->deposit_paid_amount;
        $refund = round(max(0, $paid - $fee), 2);

        $job->update([
            'cancelled_by' => $request->user()->id,
            'cancellation_reason' => $data['reason'],
            'cancellation' => [
                'fee' => $fee,
                'fee_percent' => $feePercent,
                'deposit_paid' => $paid,
                'refund_due' => $refund,
                'currency' => $job->currency,
                'reason' => $data['reason'],
                'at' => now()->toIso8601String(),
            ],
        ]);

        if ($deposit !== null && $paid > 0) {
            $deposit->update(['status' => 'refund_pending']);
        }

        $job = $workflow->transition($job, 'cancelled', $request->user(), $data['reason']);

        $audit->log('stocktake.job.cancelled', $job, $data['reason'], ['status' => $priorStatus], [
            'fee' => $fee,
            'refund_due' => $refund,
        ]);

        return ApiResponse::success($presenter->tenantPayload($job, $request->user()));
    }

    public function amend(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        StocktakePresenter $presenter,
        AuditLogger $audit,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'stocktake.request.create');
        $this->assertBelongsToTenant($tenant, $job);

        if (! in_array($job->status, self::AMENDABLE, true)) {
            throw ValidationException::withMessages(['status' => ['This request cannot be amended in its current status.']]);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*' => ['nullable'],
        ]);

        $allowed = [
            'title', 'description', 'job_type', 'priority', 'count_method', 'expected_duration_minutes',
            'preferred_start_at', 'deadline_at', 'branch_ids', 'stock_location_ids', 'department_ids', 'zone_ids',
            'product_scope', 'product_scope_payload', 'required_team_size', 'required_skills',
            'required_qualifications', 'safety_requirements', 'access_requirements', 'stock_movement_cutoff',
            'required_evidence', 'signoff_requirements', 'custom_instructions', 'attachments', 'inventory_snapshot_id',
        ];

        $unknown = array_diff(array_keys($data['changes']), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['changes' => ['Unsupported fields: '.implode(', ', $unknown)]]);
        }

        $before = [
            'scope' => $job->scope,
            'product_scope' => $job->product_scope,
            'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
            'deadline_at' => $job->deadline_at?->toIso8601String(),
            'required_team_size' => $job->required_team_size,
            'priority' => $job->priority,
            'quote_total' => $job->quote_total,
        ];

        $amendment = StocktakeJobAmendment::create([
            'tenant_id' => $tenant->id,
            'job_id' => $job->id,
            'status' => 'pending',
            'reason' => $data['reason'],
            'changes' => $data['changes'],
            'before' => $before,
            'tenant_consent_at' => now(),
            'worker_consent_required' => $job->status === 'awaiting_assignment',
            'requested_by' => $request->user()->id,
        ]);

        $audit->log('stocktake.amendment.requested', $amendment, $data['reason'], $before, $data['changes']);

        return ApiResponse::success([
            'amendment' => [
                'id' => $amendment->uuid,
                'status' => $amendment->status,
                'changes' => $amendment->changes,
                'requested_at' => $amendment->created_at?->toIso8601String(),
            ],
            'job' => $presenter->tenantPayload($job->fresh(), $request->user()),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeRules(): array
    {
        return [
            'job_type' => ['nullable', Rule::in(['full', 'cycle', 'spot', 'recurring', 'on_demand'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'critical'])],
            'count_method' => ['nullable', Rule::in(['quantity', 'barcode', 'guided', 'blind', 'manual', 'hybrid'])],
            'required_team_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'product_scope' => ['nullable', Rule::in(['all', 'category', 'variant'])],
            'product_scope_payload' => ['nullable', 'array'],
            'product_scope_payload.*' => ['string', 'max:120'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['string', 'max:40'],
            'stock_location_ids' => ['nullable', 'array'],
            'stock_location_ids.*' => ['string', 'max:40'],
            'department_ids' => ['nullable', 'array'],
            'department_ids.*' => ['string', 'max:40'],
            'zone_ids' => ['nullable', 'array'],
            'zone_ids.*' => ['string', 'max:40'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            ...$this->scopeRules(),
            'title' => [$required, 'string', 'min:3', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'business_criticality' => ['nullable', 'string', 'max:120'],
            'expected_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:10080'],
            'preferred_start_at' => ['nullable', 'date'],
            'deadline_at' => ['nullable', 'date'],
            'industry_requirements' => ['nullable', 'string', 'max:2000'],
            'safety_requirements' => ['nullable', 'string', 'max:2000'],
            'access_requirements' => ['nullable', 'string', 'max:2000'],
            'stock_movement_cutoff' => ['nullable', 'string', 'max:2000'],
            'required_skills' => ['nullable', 'array'],
            'required_skills.*' => ['string', 'max:80'],
            'required_qualifications' => ['nullable', 'string', 'max:2000'],
            'required_evidence' => ['nullable', 'array'],
            'required_evidence.*' => ['string', 'max:120'],
            'signoff_requirements' => ['nullable', 'string', 'max:2000'],
            'sop_template' => ['nullable', 'string', 'max:120'],
            'sop_version' => ['nullable', 'string', 'max:40'],
            'custom_instructions' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:20'],
            'attachments.*' => ['string', 'max:500'],
            'inventory_snapshot_id' => ['nullable', 'string', 'max:40'],
        ];
    }
}
