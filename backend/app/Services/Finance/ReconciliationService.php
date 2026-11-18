<?php

namespace App\Services\Finance;

use App\Models\FinancialReconciliation;
use App\Models\FinancialReconciliationItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReconciliationService
{
    private const PROVIDERS = ['mpesa', 'card', 'bank', 'manual'];

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): FinancialReconciliation
    {
        return DB::transaction(function () use ($data, $actor): FinancialReconciliation {
            $provider = (string) $data['provider'];
            if (! in_array($provider, self::PROVIDERS, true)) {
                throw ValidationException::withMessages(['provider' => ['Choose a supported provider.']]);
            }

            $from = (string) $data['period_start'];
            $to = (string) $data['period_end'];
            if ($from > $to) {
                throw ValidationException::withMessages(['period_end' => ['The period end must be after the period start.']]);
            }

            $payments = Payment::withoutGlobalScope('tenant')
                ->where('method', $provider)
                ->where('status', 'succeeded')
                ->whereBetween(DB::raw('DATE(succeeded_at)'), [$from, $to])
                ->get()
                ->keyBy(fn (Payment $payment) => (string) $payment->provider_reference);

            $statement = collect($data['statement_lines'] ?? [])
                ->map(fn (array $line) => [
                    'provider_reference' => trim((string) $line['provider_reference']),
                    'amount' => round((float) $line['amount'], 2),
                ])
                ->filter(fn (array $line) => $line['provider_reference'] !== '')
                ->values();

            if ($statement->isEmpty()) {
                throw ValidationException::withMessages(['statement_lines' => ['Add at least one statement line.']]);
            }

            $items = [];
            $seen = [];

            foreach ($statement as $line) {
                $seen[] = $line['provider_reference'];
                $payment = $payments->get($line['provider_reference']);

                if ($payment === null) {
                    $items[] = [
                        'provider_reference' => $line['provider_reference'],
                        'amount' => $line['amount'],
                        'status' => 'unmatched',
                        'payment_id' => null,
                        'note' => 'Statement line has no matching payment in BRATHAMATU.',
                    ];

                    continue;
                }

                $matches = abs((float) $payment->amount - $line['amount']) < 0.005;
                $items[] = [
                    'provider_reference' => $line['provider_reference'],
                    'amount' => $line['amount'],
                    'status' => $matches ? 'matched' : 'amount_mismatch',
                    'payment_id' => $payment->id,
                    'note' => $matches ? null : sprintf('Payment amount is %s.', number_format((float) $payment->amount, 2)),
                ];
            }

            foreach ($payments as $reference => $payment) {
                if ($reference !== '' && in_array($reference, $seen, true)) {
                    continue;
                }

                $items[] = [
                    'provider_reference' => $reference !== '' ? $reference : $payment->internal_reference,
                    'amount' => (float) $payment->amount,
                    'status' => 'missing',
                    'payment_id' => $payment->id,
                    'note' => 'Settled payment is missing from the provider statement.',
                ];
            }

            $statementTotal = round($statement->sum('amount'), 2);
            $paymentTotal = round((float) $payments->sum(fn (Payment $payment) => (float) $payment->amount), 2);
            $ledgerTotal = $this->ledger->clearingTotal($provider, $from, $to);

            $exceptional = collect($items)->whereIn('status', ['unmatched', 'amount_mismatch', 'missing'])->count();
            $difference = round($statementTotal - $paymentTotal, 2);
            $balanced = $exceptional === 0
                && abs($difference) < 0.005
                && abs($statementTotal - $ledgerTotal) < 0.005;

            $reconciliation = FinancialReconciliation::create([
                'reference' => $this->reference(),
                'provider' => $provider,
                'currency' => $data['currency'] ?? config('payments.default_currency', 'KES'),
                'period_start' => $from,
                'period_end' => $to,
                'status' => $balanced ? 'balanced' : 'variance',
                'statement_total' => $statementTotal,
                'payment_total' => $paymentTotal,
                'ledger_total' => $ledgerTotal,
                'difference' => $difference,
                'created_by' => $actor->id,
                'completed_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                FinancialReconciliationItem::create([...$item, 'reconciliation_id' => $reconciliation->id]);
            }

            $this->audit->log('finance.reconciliation.created', $reconciliation, null, null, [
                'provider' => $provider,
                'status' => $reconciliation->status,
                'statement_total' => $statementTotal,
                'payment_total' => $paymentTotal,
                'ledger_total' => $ledgerTotal,
                'exceptions' => $exceptional,
            ], null, $actor->id);

            return $reconciliation->load('items');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(FinancialReconciliation $reconciliation, bool $withItems = true): array
    {
        $items = $reconciliation->relationLoaded('items')
            ? $reconciliation->items
            : $reconciliation->items()->get();

        return [
            'id' => $reconciliation->uuid,
            'reference' => $reconciliation->reference,
            'provider' => $reconciliation->provider,
            'currency' => $reconciliation->currency,
            'period_start' => $reconciliation->period_start?->toDateString(),
            'period_end' => $reconciliation->period_end?->toDateString(),
            'status' => $reconciliation->status,
            'statement_total' => (float) $reconciliation->statement_total,
            'payment_total' => (float) $reconciliation->payment_total,
            'ledger_total' => (float) $reconciliation->ledger_total,
            'difference' => (float) $reconciliation->difference,
            'exception_count' => $items->whereIn('status', ['unmatched', 'amount_mismatch', 'missing'])->count(),
            'notes' => $reconciliation->notes,
            'created_by' => $reconciliation->createdBy?->name,
            'completed_at' => $reconciliation->completed_at?->toIso8601String(),
            'created_at' => $reconciliation->created_at?->toIso8601String(),
            'items' => $withItems ? $items->map(fn (FinancialReconciliationItem $item) => [
                'provider_reference' => $item->provider_reference,
                'amount' => (float) $item->amount,
                'status' => $item->status,
                'payment_id' => $item->payment?->uuid,
                'note' => $item->note,
            ])->values()->all() : null,
        ];
    }

    private function reference(): string
    {
        do {
            $reference = 'REC-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (FinancialReconciliation::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
