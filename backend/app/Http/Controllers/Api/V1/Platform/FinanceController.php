<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\FinancialJournal;
use App\Models\FinancialReconciliation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StocktakeJob;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\LedgerService;
use App\Services\Finance\ReconciliationService;
use App\Services\Finance\RefundService;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinanceController extends Controller
{
    public function overview(LedgerService $ledger, PaymentService $payments): JsonResponse
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $succeeded = Payment::withoutGlobalScope('tenant')->where('status', 'succeeded');

        $byMethod = (clone $succeeded)
            ->selectRaw('method, count(*) as payments, sum(amount) as total')
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->method,
                'payments' => (int) $row->payments,
                'total' => round((float) $row->total, 2),
            ])->values()->all();

        $trial = $ledger->trialBalance($monthStart, $today);

        return ApiResponse::success([
            'collected_today' => round((float) (clone $succeeded)->whereDate('succeeded_at', $today)->sum('amount'), 2),
            'collected_this_month' => round((float) (clone $succeeded)->whereDate('succeeded_at', '>=', $monthStart)->sum('amount'), 2),
            'by_method' => $byMethod,
            'awaiting_verification' => Payment::withoutGlobalScope('tenant')
                ->whereIn('provider', ['bank', 'manual'])
                ->where('status', 'pending')
                ->count(),
            'processing' => Payment::withoutGlobalScope('tenant')->where('status', 'processing')->count(),
            'failed_payments' => Payment::withoutGlobalScope('tenant')->where('status', 'failed')->count(),
            'awaiting_deposit_jobs' => StocktakeJob::withoutGlobalScope('tenant')
                ->whereIn('status', ['awaiting_deposit', 'deposit_pending_verification'])
                ->count(),
            'awaiting_balance_jobs' => StocktakeJob::withoutGlobalScope('tenant')
                ->whereIn('status', ['awaiting_balance_payment', 'payment_pending_verification'])
                ->count(),
            'refunds_requested' => Refund::withoutGlobalScope('tenant')->whereIn('status', ['requested', 'approved'])->count(),
            'ledger' => $trial,
            'recent_payments' => Payment::withoutGlobalScope('tenant')
                ->with(['job.tenant', 'initiatedBy'])
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (Payment $payment) => $this->paymentRow($payment, $payments))
                ->values()->all(),
            'recent_reconciliations' => FinancialReconciliation::query()
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (FinancialReconciliation $reconciliation) => [
                    'id' => $reconciliation->uuid,
                    'reference' => $reconciliation->reference,
                    'provider' => $reconciliation->provider,
                    'status' => $reconciliation->status,
                    'period_start' => $reconciliation->period_start?->toDateString(),
                    'period_end' => $reconciliation->period_end?->toDateString(),
                    'difference' => (float) $reconciliation->difference,
                ])->values()->all(),
        ]);
    }

    public function payments(Request $request, PaymentService $payments): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'processing', 'succeeded', 'failed', 'cancelled'])],
            'method' => ['nullable', Rule::in(['mpesa', 'card', 'bank', 'manual'])],
            'purpose' => ['nullable', Rule::in(['deposit', 'balance'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Payment::withoutGlobalScope('tenant')
            ->with(['job.tenant', 'initiatedBy'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['method'] ?? null, fn ($query, $method) => $query->where('method', $method))
            ->when($filters['purpose'] ?? null, fn ($query, $purpose) => $query->where('purpose', $purpose))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('internal_reference', 'like', "%{$search}%")
                        ->orWhere('provider_reference', 'like', "%{$search}%")
                        ->orWhereHas('job', fn ($job) => $job->where('reference', 'like', "%{$search}%")
                            ->orWhere('title', 'like', "%{$search}%"))
                        ->orWhereHas('job.tenant', fn ($tenant) => $tenant->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id');

        $page = $query->paginate(25);

        return ApiResponse::success(
            collect($page->items())->map(fn (Payment $payment) => $this->paymentRow($payment, $payments))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'counts' => (object) Payment::withoutGlobalScope('tenant')
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status')
                    ->map(fn ($count) => (int) $count)
                    ->all(),
            ],
        );
    }

    public function verifyPayment(
        string $payment,
        Request $request,
        PaymentService $payments,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm', 'fail'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Payment::withoutGlobalScope('tenant')->where('uuid', $payment)->firstOrFail();
        $record = $payments->verifyManual($record, $request->user(), $data['decision'], $data['reference'] ?? null, $data['reason'] ?? null);

        $audit->log('finance.payment.reviewed', $record, $data['reason'] ?? null, null, [
            'decision' => $data['decision'],
            'job_id' => $record->job_id,
        ], $record->tenant_id, $request->user()->id);

        return ApiResponse::success($this->paymentRow($record->fresh(['job.tenant', 'initiatedBy']), $payments));
    }

    public function ledger(Request $request, LedgerService $ledger): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'tenant_id' => ['nullable', 'string', 'max:40'],
            'job_id' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = null;
        if (! empty($filters['tenant_id'])) {
            $tenantId = \App\Models\Tenant::query()->where('uuid', $filters['tenant_id'])->value('id');
        }

        $query = FinancialJournal::query()
            ->with(['lines.account', 'job', 'tenant', 'actor'])
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('transaction_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))
            ->when($tenantId, fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->when($filters['job_id'] ?? null, fn ($query, $jobId) => $query->whereHas('job', fn ($job) => $job->where('uuid', $jobId)))
            ->orderByDesc('posted_at')
            ->orderByDesc('id');

        $page = $query->paginate(30);

        return ApiResponse::success(
            collect($page->items())->map(fn (FinancialJournal $journal) => [
                'id' => $journal->uuid,
                'reference' => $journal->reference,
                'description' => $journal->description,
                'currency' => $journal->currency,
                'transaction_date' => $journal->transaction_date?->toDateString(),
                'posted_at' => $journal->posted_at?->toIso8601String(),
                'job' => $journal->job ? ['id' => $journal->job->uuid, 'reference' => $journal->job->reference] : null,
                'tenant' => $journal->tenant?->name,
                'actor' => $journal->actor?->name,
                'source_type' => $journal->source_type,
                'reversal_of' => $journal->reversal_of_id !== null,
                'lines' => $journal->lines->map(fn ($line) => [
                    'account' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'memo' => $line->memo,
                ])->values()->all(),
            ])->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'trial_balance' => $ledger->trialBalance($filters['from'] ?? null, $filters['to'] ?? null, $tenantId),
            ],
        );
    }

    public function reconciliations(Request $request, ReconciliationService $reconciliations): JsonResponse
    {
        $page = FinancialReconciliation::query()
            ->with('createdBy')
            ->orderByDesc('id')
            ->paginate(20);

        return ApiResponse::success(
            collect($page->items())->map(fn (FinancialReconciliation $reconciliation) => $reconciliations->payload($reconciliation, false))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    public function storeReconciliation(Request $request, ReconciliationService $reconciliations): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['mpesa', 'card', 'bank', 'manual'])],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:500'],
            'statement_lines' => ['required', 'array', 'min:1', 'max:500'],
            'statement_lines.*.provider_reference' => ['required', 'string', 'max:80'],
            'statement_lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);

        $reconciliation = $reconciliations->create($data, $request->user());

        return ApiResponse::success($reconciliations->payload($reconciliation), 201);
    }

    public function showReconciliation(string $reconciliation, ReconciliationService $reconciliations): JsonResponse
    {
        $record = FinancialReconciliation::query()->where('uuid', $reconciliation)->firstOrFail();

        return ApiResponse::success($reconciliations->payload($record->load('items.payment')));
    }

    public function refunds(Request $request, RefundService $refunds): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['requested', 'approved', 'processing', 'completed', 'failed', 'cancelled'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = Refund::withoutGlobalScope('tenant')
            ->with(['payment', 'job', 'requestedBy', 'approvedBy'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(25);

        return ApiResponse::success(
            collect($page->items())->map(fn (Refund $refund) => $refunds->payload($refund))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'counts' => (object) Refund::withoutGlobalScope('tenant')
                    ->selectRaw('status, count(*) as aggregate')
                    ->groupBy('status')
                    ->pluck('aggregate', 'status')
                    ->map(fn ($count) => (int) $count)
                    ->all(),
            ],
        );
    }

    public function storeRefund(Request $request, RefundService $refunds): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $payment = Payment::withoutGlobalScope('tenant')->where('uuid', $data['payment_id'])->firstOrFail();
        $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($payment->job_id)->firstOrFail();

        $refund = $refunds->request($job, $data, $request->user());

        return ApiResponse::success($refunds->payload($refund->load(['payment', 'job', 'requestedBy', 'approvedBy'])), 201);
    }

    public function decideRefund(
        string $refund,
        Request $request,
        RefundService $refunds,
    ): JsonResponse {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'process', 'fail', 'cancel'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $record = Refund::withoutGlobalScope('tenant')->where('uuid', $refund)->firstOrFail();

        $record = match ($data['decision']) {
            'approve' => $refunds->approve($record, $request->user(), $data['reason'] ?? null),
            'process' => $refunds->complete($record->fresh(), $request->user(), $data['reference'] ?? null, 'success'),
            'fail' => $refunds->complete($record->fresh(), $request->user(), null, 'fail', $data['reason'] ?? null),
            'cancel' => $refunds->cancel($record->fresh(), $request->user(), $data['reason'] ?? 'Cancelled by finance.'),
        };

        return ApiResponse::success($refunds->payload($record->load(['payment', 'job', 'requestedBy', 'approvedBy'])));
    }

    public function options(PaymentProviderManager $providers): JsonResponse
    {
        return ApiResponse::success([
            'providers' => $providers->options(),
            'sandbox' => (bool) config('payments.sandbox'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(Payment $payment, PaymentService $payments): array
    {
        return [
            ...$payments->payload($payment, true),
            'job' => $payment->job ? [
                'id' => $payment->job->uuid,
                'reference' => $payment->job->reference,
                'title' => $payment->job->title,
                'status' => $payment->job->status,
            ] : null,
            'tenant' => $payment->job?->tenant ? [
                'id' => $payment->job->tenant->uuid,
                'name' => $payment->job->tenant->name,
            ] : null,
        ];
    }
}
