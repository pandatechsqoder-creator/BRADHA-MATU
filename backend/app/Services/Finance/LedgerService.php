<?php

namespace App\Services\Finance;

use App\Models\FinancialAccount;
use App\Models\FinancialJournal;
use App\Models\FinancialJournalLine;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StocktakeJob;
use App\Models\StocktakeQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Double-entry journal posting. Journals are append-only and every journal
 * must balance per currency. Mistakes are corrected with reversals.
 */
final class LedgerService
{
    public const CLEARING_BY_METHOD = [
        'mpesa' => '1000',
        'card' => '1010',
        'bank' => '1020',
        'manual' => '1030',
    ];

    /**
     * @param  array<int, array{account: string, debit?: float, credit?: float, memo?: string}>  $lines
     * @param  array<string, mixed>  $metadata
     */
    public function post(
        string $sourceType,
        string $sourceId,
        array $lines,
        string $description,
        string $currency = 'KES',
        ?int $tenantId = null,
        ?int $jobId = null,
        ?int $actorId = null,
        ?string $transactionDate = null,
        array $metadata = [],
    ): FinancialJournal {
        return DB::transaction(function () use ($sourceType, $sourceId, $lines, $description, $currency, $tenantId, $jobId, $actorId, $transactionDate, $metadata): FinancialJournal {
            $existing = FinancialJournal::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $accounts = FinancialAccount::query()->whereIn('code', array_map(fn (array $line) => $line['account'], $lines))->get()->keyBy('code');

            $debits = 0;
            $credits = 0;
            $normalised = [];

            foreach ($lines as $line) {
                $account = $accounts->get($line['account']);
                if ($account === null) {
                    throw ValidationException::withMessages(['ledger' => ["Unknown ledger account {$line['account']}."]]);
                }

                $debit = round((float) ($line['debit'] ?? 0), 2);
                $credit = round((float) ($line['credit'] ?? 0), 2);

                if ($debit < 0 || $credit < 0) {
                    throw ValidationException::withMessages(['ledger' => ['Journal amounts cannot be negative.']]);
                }
                if ($debit > 0 && $credit > 0) {
                    throw ValidationException::withMessages(['ledger' => ['A journal line is either a debit or a credit, not both.']]);
                }
                if ($debit === 0.0 && $credit === 0.0) {
                    throw ValidationException::withMessages(['ledger' => ['Journal lines cannot be zero.']]);
                }

                $debits += (int) round($debit * 100);
                $credits += (int) round($credit * 100);
                $normalised[] = [
                    'account_id' => $account->id,
                    'tenant_id' => $tenantId,
                    'job_id' => $jobId,
                    'currency' => $currency,
                    'debit' => $debit,
                    'credit' => $credit,
                    'memo' => $line['memo'] ?? null,
                ];
            }

            if ($debits !== $credits) {
                throw ValidationException::withMessages([
                    'ledger' => [sprintf('Journal does not balance: debits %s, credits %s.', number_format($debits / 100, 2), number_format($credits / 100, 2))],
                ]);
            }

            $journal = FinancialJournal::create([
                'reference' => $this->reference(),
                'tenant_id' => $tenantId,
                'job_id' => $jobId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'currency' => $currency,
                'transaction_date' => $transactionDate ?? now()->toDateString(),
                'posted_at' => now(),
                'description' => $description,
                'actor_id' => $actorId,
                'metadata' => $metadata ?: null,
            ]);

            foreach ($normalised as $line) {
                FinancialJournalLine::create([...$line, 'journal_id' => $journal->id]);
            }

            return $journal->load('lines.account');
        });
    }

    public function reverse(FinancialJournal $journal, string $reason, ?User $actor = null): FinancialJournal
    {
        if ($journal->reversal_of_id !== null) {
            throw ValidationException::withMessages(['ledger' => ['A reversal journal cannot be reversed again.']]);
        }

        $journal->loadMissing('lines');

        $lines = $journal->lines->map(fn (FinancialJournalLine $line) => [
            'account' => FinancialAccount::query()->findOrFail($line->account_id)->code,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'memo' => 'Reversal: '.($line->memo ?? $journal->description),
        ])->all();

        $reversal = $this->post(
            'reversal',
            $journal->uuid,
            $lines,
            'Reversal of '.$journal->reference.': '.$reason,
            $journal->currency,
            $journal->tenant_id,
            $journal->job_id,
            $actor?->id,
            metadata: ['reversal_of' => $journal->reference, 'reason' => $reason],
        );

        FinancialJournal::withoutEvents(fn () => FinancialJournal::query()->whereKey($reversal->id)->update(['reversal_of_id' => $journal->id]));

        return $reversal->fresh();
    }

    public function postDepositReceived(Payment $payment, ?int $actorId = null): FinancialJournal
    {
        return $this->post(
            'payment',
            $payment->uuid,
            [
                ['account' => self::CLEARING_BY_METHOD[$payment->method] ?? '1030', 'debit' => $payment->amount, 'memo' => 'Deposit received'],
                ['account' => '2000', 'credit' => $payment->amount, 'memo' => 'Tenant deposit held'],
            ],
            'Deposit payment '.$payment->internal_reference,
            $payment->currency,
            $payment->tenant_id,
            $payment->job_id,
            $actorId,
            metadata: ['method' => $payment->method, 'provider' => $payment->provider],
        );
    }

    public function postBalanceReceived(Payment $payment, ?int $actorId = null): FinancialJournal
    {
        return $this->post(
            'payment',
            $payment->uuid,
            [
                ['account' => self::CLEARING_BY_METHOD[$payment->method] ?? '1030', 'debit' => $payment->amount, 'memo' => 'Job balance received'],
                ['account' => '1100', 'credit' => $payment->amount, 'memo' => 'Tenant receivable settled'],
            ],
            'Balance payment '.$payment->internal_reference,
            $payment->currency,
            $payment->tenant_id,
            $payment->job_id,
            $actorId,
            metadata: ['method' => $payment->method, 'provider' => $payment->provider],
        );
    }

    /**
     * Recognise the accepted quote: debit the tenant receivable and credit
     * unearned revenue, applying any deposit already held against it.
     */
    public function postQuoteAccepted(StocktakeQuote $quote, float $depositPaid, ?int $actorId = null): FinancialJournal
    {
        $total = round((float) $quote->total, 2);
        $applied = round(min($depositPaid, $total), 2);

        $lines = [
            ['account' => '1100', 'debit' => $total, 'memo' => 'Tenant receivable for '.$quote->number],
            ['account' => '2200', 'credit' => $total, 'memo' => 'Unearned job revenue'],
        ];

        if ($applied > 0) {
            $lines[] = ['account' => '2000', 'debit' => $applied, 'memo' => 'Deposit applied to quote'];
            $lines[] = ['account' => '1100', 'credit' => $applied, 'memo' => 'Deposit credited against receivable'];
        }

        return $this->post(
            'quote_accepted',
            $quote->uuid,
            $lines,
            'Quote '.$quote->number.' accepted',
            $quote->currency,
            $quote->tenant_id,
            $quote->job_id,
            $actorId,
            metadata: ['total' => $total, 'deposit_applied' => $applied],
        );
    }

    public function postRefund(Refund $refund, Payment $payment, ?int $actorId = null): FinancialJournal
    {
        return $this->post(
            'refund',
            $refund->uuid,
            [
                ['account' => $payment->purpose === 'deposit' ? '2000' : '1100', 'debit' => $refund->amount, 'memo' => 'Refund to tenant'],
                ['account' => self::CLEARING_BY_METHOD[$payment->method] ?? '1030', 'credit' => $refund->amount, 'memo' => 'Refund paid'],
            ],
            'Refund '.$refund->uuid,
            $refund->currency,
            $refund->tenant_id,
            $refund->job_id,
            $actorId,
            metadata: ['payment' => $payment->internal_reference, 'reason' => $refund->reason],
        );
    }

    /**
     * @return array{accounts: array<int, array<string, mixed>>, totals: array{debit: float, credit: float}, balanced: bool}
     */
    public function trialBalance(?string $from = null, ?string $to = null, ?int $tenantId = null): array
    {
        $query = FinancialJournalLine::query()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_journal_lines.account_id')
            ->join('financial_journals', 'financial_journals.id', '=', 'financial_journal_lines.journal_id')
            ->selectRaw('financial_accounts.id, financial_accounts.code, financial_accounts.name, financial_accounts.type, financial_accounts.normal_balance')
            ->selectRaw('SUM(financial_journal_lines.debit) as debit, SUM(financial_journal_lines.credit) as credit')
            ->when($from, fn ($q, $from) => $q->whereDate('financial_journals.transaction_date', '>=', $from))
            ->when($to, fn ($q, $to) => $q->whereDate('financial_journals.transaction_date', '<=', $to))
            ->when($tenantId, fn ($q, $tenantId) => $q->where('financial_journal_lines.tenant_id', $tenantId))
            ->groupBy('financial_accounts.id', 'financial_accounts.code', 'financial_accounts.name', 'financial_accounts.type', 'financial_accounts.normal_balance')
            ->orderBy('financial_accounts.code');

        $accounts = $query->get()->map(fn ($row) => [
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->type,
            'normal_balance' => $row->normal_balance,
            'debit' => round((float) $row->debit, 2),
            'credit' => round((float) $row->credit, 2),
            'balance' => round((float) $row->debit - (float) $row->credit, 2),
        ])->values()->all();

        $debit = array_sum(array_column($accounts, 'debit'));
        $credit = array_sum(array_column($accounts, 'credit'));

        return [
            'accounts' => $accounts,
            'totals' => ['debit' => round($debit, 2), 'credit' => round($credit, 2)],
            'balanced' => (int) round($debit * 100) === (int) round($credit * 100),
        ];
    }

    public function clearingTotal(string $providerKey, string $from, string $to): float
    {
        $code = match ($providerKey) {
            'mpesa' => '1000',
            'card' => '1010',
            'bank' => '1020',
            'manual' => '1030',
            default => '1030',
        };

        $total = FinancialJournalLine::query()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_journal_lines.account_id')
            ->join('financial_journals', 'financial_journals.id', '=', 'financial_journal_lines.journal_id')
            ->where('financial_accounts.code', $code)
            ->whereDate('financial_journals.transaction_date', '>=', $from)
            ->whereDate('financial_journals.transaction_date', '<=', $to)
            ->sum('financial_journal_lines.debit');

        $credits = FinancialJournalLine::query()
            ->join('financial_accounts', 'financial_accounts.id', '=', 'financial_journal_lines.account_id')
            ->join('financial_journals', 'financial_journals.id', '=', 'financial_journal_lines.journal_id')
            ->where('financial_accounts.code', $code)
            ->whereDate('financial_journals.transaction_date', '>=', $from)
            ->whereDate('financial_journals.transaction_date', '<=', $to)
            ->sum('financial_journal_lines.credit');

        return round((float) $total - (float) $credits, 2);
    }

    public function jobLedger(StocktakeJob $job): array
    {
        $journals = FinancialJournal::query()
            ->where('job_id', $job->id)
            ->with('lines.account')
            ->orderBy('posted_at')
            ->get();

        return $journals->map(fn (FinancialJournal $journal) => [
            'id' => $journal->uuid,
            'reference' => $journal->reference,
            'description' => $journal->description,
            'currency' => $journal->currency,
            'posted_at' => $journal->posted_at?->toIso8601String(),
            'lines' => $journal->lines->map(fn (FinancialJournalLine $line) => [
                'account' => $line->account?->code,
                'account_name' => $line->account?->name,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'memo' => $line->memo,
            ])->values()->all(),
        ])->values()->all();
    }

    private function reference(): string
    {
        do {
            $reference = 'JRN-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (FinancialJournal::query()->where('reference', $reference)->exists());

        return $reference;
    }
}
