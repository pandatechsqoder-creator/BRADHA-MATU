<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\StocktakeJob;
use App\Models\StocktakeJobAmendment;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentService;
use App\Services\Stocktake\StocktakeDepositService;
use App\Services\Stocktake\StocktakePresenter;
use App\Services\Stocktake\StocktakeQuoteService;
use App\Services\Stocktake\StocktakeRequestService;
use App\Services\Stocktake\StocktakeWorkflow;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StocktakeJobController extends Controller
{
    public function index(Request $request, StocktakePresenter $presenter, TenantContext $context): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'critical'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StocktakeJob::withoutGlobalScope('tenant')
            ->with(['tenant', 'requester', 'latestQuote', 'deposit'])
            ->where('status', '!=', 'draft')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhereHas('tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id');

        $page = $query->paginate(25);

        $statusCounts = StocktakeJob::withoutGlobalScope('tenant')
            ->where('status', '!=', 'draft')
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        $buckets = [
            'new_requests' => (int) ($statusCounts['submitted'] ?? 0),
            'awaiting_deposit' => (int) ($statusCounts['awaiting_deposit'] ?? 0) + (int) ($statusCounts['deposit_pending_verification'] ?? 0),
            'under_review' => (int) ($statusCounts['under_review'] ?? 0) + (int) ($statusCounts['needs_tenant_information'] ?? 0),
            'quotes_pending' => collect([
                'quote_issued', 'awaiting_quote_acceptance', 'awaiting_balance_payment', 'payment_pending_verification',
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
            'ready_to_execute' => (int) ($statusCounts['approved_for_execution'] ?? 0) + (int) ($statusCounts['awaiting_assignment'] ?? 0),
            'active_jobs' => collect([
                'assigned', 'awaiting_worker_acceptance', 'scheduled', 'in_progress', 'paused',
                'count_submitted', 'quality_review', 'recount_required', 'awaiting_tenant_review',
                'tenant_correction_requested', 'disputed',
            ])->sum(fn (string $status) => (int) ($statusCounts[$status] ?? 0)),
        ];

        return ApiResponse::success(
            collect($page->items())->map(fn (StocktakeJob $job) => $presenter->summary($job, $request->user(), true))->values(),
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

    public function show(string $job, Request $request, StocktakePresenter $presenter, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        abort_if($model->status === 'draft', 404);

        return ApiResponse::success($presenter->platformPayload($model, $request->user()));
    }

    public function startReview(string $job, Request $request, StocktakeWorkflow $workflow, StocktakePresenter $presenter, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $model = $workflow->transition($model, 'under_review', $request->user(), 'Platform review started.');
        $audit->log('stocktake.job.review_started', $model, null, null, ['reference' => $model->reference], $model->tenant_id);

        return ApiResponse::success($presenter->platformPayload($model->fresh(), $request->user()));
    }

    public function requestInformation(string $job, Request $request, StocktakeWorkflow $workflow, StocktakePresenter $presenter, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $model = $this->findJob($job, $context);
        $model = $workflow->transition($model, 'needs_tenant_information', $request->user(), $data['reason']);
        $audit->log('stocktake.job.information_requested', $model, $data['reason'], null, null, $model->tenant_id);

        return ApiResponse::success($presenter->platformPayload($model->fresh(), $request->user()));
    }

    public function issueQuote(
        string $job,
        Request $request,
        StocktakeQuoteService $quotes,
        StocktakePresenter $presenter,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'base_service_charge' => ['required', 'numeric', 'min:0'],
            'scope_complexity_charge' => ['nullable', 'numeric', 'min:0'],
            'travel_expenses' => ['nullable', 'numeric', 'min:0'],
            'other_expenses' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deposit_type' => ['nullable', Rule::in(['none', 'fixed', 'percentage'])],
            'deposit_value' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'worker_compensation_budget' => ['nullable', 'numeric', 'min:0'],
            'worker_compensation_breakdown' => ['nullable', 'array'],
            'platform_fee' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date', 'after:today'],
        ]);

        $model = $this->findJob($job, $context);
        $quote = $quotes->issue($model, $data, $request->user());
        $audit->log('stocktake.quote.prepared', $quote, null, null, [
            'number' => $quote->number,
            'total' => (float) $quote->total,
        ], $model->tenant_id);

        return ApiResponse::success($presenter->platformPayload($model->fresh(), $request->user()), 201);
    }

    public function verifyPayment(
        string $job,
        string $payment,
        Request $request,
        PaymentService $payments,
        StocktakePresenter $presenter,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm', 'fail'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $model = $this->findJob($job, $context);
        $record = Payment::withoutGlobalScope('tenant')
            ->where('uuid', $payment)
            ->where('job_id', $model->id)
            ->firstOrFail();

        $record = $payments->verifyManual($record, $request->user(), $data['decision'], $data['reference'] ?? null, $data['reason'] ?? null);
        $audit->log('stocktake.deposit.reviewed', $model, $data['reason'] ?? null, null, [
            'decision' => $data['decision'],
            'payment' => $record->uuid,
        ], $model->tenant_id);

        return ApiResponse::success($presenter->platformPayload($model->fresh(), $request->user()));
    }

    public function waiveDeposit(
        string $job,
        Request $request,
        StocktakeDepositService $deposits,
        StocktakePresenter $presenter,
        TenantContext $context,
    ): JsonResponse {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $model = $this->findJob($job, $context);
        $model = $deposits->waive($model, $request->user(), $data['reason']);

        return ApiResponse::success($presenter->platformPayload($model->fresh(), $request->user()));
    }

    public function decideAmendment(
        string $job,
        string $amendment,
        Request $request,
        StocktakeWorkflow $workflow,
        StocktakeRequestService $requests,
        StocktakePresenter $presenter,
        TenantContext $context,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'financial_impact' => ['nullable', 'numeric'],
        ]);

        $model = $this->findJob($job, $context);
        $record = StocktakeJobAmendment::withoutGlobalScope('tenant')
            ->where('uuid', $amendment)
            ->where('job_id', $model->id)
            ->firstOrFail();

        if ($record->status !== 'pending') {
            throw ValidationException::withMessages(['amendment' => ['This amendment has already been decided.']]);
        }

        $result = DB::transaction(function () use ($record, $model, $data, $request, $workflow, $requests, $audit): StocktakeJob {
            $record->update([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected',
                'financial_impact' => (float) ($data['financial_impact'] ?? 0),
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
                'decision_reason' => $data['reason'] ?? null,
                'applied_at' => $data['decision'] === 'approve' ? now() : null,
            ]);

            if ($data['decision'] === 'reject') {
                $audit->log('stocktake.amendment.rejected', $record, $data['reason'] ?? null, null, null, $model->tenant_id);

                return $model->fresh();
            }

            $changes = $record->changes;
            $updated = $requests->update($model->fresh(), $changes);
            $updated->increment('version');

            $hasAccepted = $updated->quotes()->where('status', 'accepted')->exists();
            $updated->quotes()->whereIn('status', ['issued', 'accepted'])->update([
                'status' => 'superseded',
                'superseded_at' => now(),
            ]);

            $updated->update([
                'accepted_quote_id' => null,
                'quote_total' => null,
                'accepted_at' => null,
            ]);

            if ($hasAccepted || in_array($updated->status, [
                'quote_issued', 'awaiting_quote_acceptance', 'awaiting_balance_payment', 'approved_for_execution', 'awaiting_assignment',
            ], true)) {
                if ($updated->status === 'quote_issued') {
                    $updated = $workflow->transition($updated, 'under_review', $request->user(), 'Amendment approved: '.$record->reason);
                } else {
                    $updated = $workflow->transition($updated, 'under_review', $request->user(), 'Amendment approved: '.$record->reason);
                }
            }

            $audit->log('stocktake.amendment.approved', $record, $record->reason, $record->before, [
                'changes' => $changes,
                'financial_impact' => (float) ($data['financial_impact'] ?? 0),
            ], $model->tenant_id);

            return $updated->fresh();
        });

        return ApiResponse::success($presenter->platformPayload($result, $request->user()));
    }

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }
}
