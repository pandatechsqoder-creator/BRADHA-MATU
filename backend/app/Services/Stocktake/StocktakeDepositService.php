<?php

namespace App\Services\Stocktake;

use App\Models\Payment;
use App\Models\StocktakeDeposit;
use App\Models\StocktakeJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

final class StocktakeDepositService
{
    public function __construct(
        private readonly StocktakePolicyService $policies,
        private readonly StocktakeWorkflow $workflow,
        private readonly AuditLogger $audit,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function requirement(StocktakeJob $job): array
    {
        $rules = $this->policies->depositRules($job->tenant, $job->job_type);
        $amount = (float) ($job->provisional_estimate['total'] ?? 0);

        return $this->policies->depositFor($amount, $rules);
    }

    public function requireFor(StocktakeJob $job, User $actor): StocktakeDeposit
    {
        return DB::transaction(function () use ($job, $actor): StocktakeDeposit {
            $rules = $this->policies->depositRules($job->tenant, $job->job_type);
            $estimateTotal = (float) ($job->provisional_estimate['total'] ?? $job->quote_total ?? 0);
            $deposit = $this->policies->depositFor($estimateTotal, $rules);
            $dueAt = now()->addDays((int) $deposit['due_days']);

            $record = StocktakeDeposit::query()->where('job_id', $job->id)->first();
            if ($record === null) {
                $record = StocktakeDeposit::create([
                    'tenant_id' => $job->tenant_id,
                    'job_id' => $job->id,
                    'status' => 'required',
                    'amount' => $deposit['amount'],
                    'paid_amount' => 0,
                    'currency' => $deposit['currency'],
                    'due_at' => $dueAt,
                    'policy' => $deposit,
                ]);
            } elseif (in_array($record->status, ['required', 'failed', 'expired'], true)) {
                $record->update([
                    'amount' => $deposit['amount'],
                    'status' => 'required',
                    'due_at' => $record->due_at ?? $dueAt,
                    'policy' => $deposit,
                ]);
            }

            $job->update([
                'deposit_required' => $deposit['required'],
                'deposit_amount' => $deposit['amount'],
                'deposit_due_at' => $record->due_at,
                'currency' => $deposit['currency'],
            ]);

            $this->audit->log('stocktake.deposit.required', $record, null, null, [
                'amount' => $deposit['amount'],
                'policy' => $deposit['summary'],
                'job' => $job->reference,
            ], $job->tenant_id, $actor->id);

            return $record->fresh();
        });
    }

    /**
     * Record (or initiate) a deposit payment. The deposit is only counted as
     * paid after a provider callback or an authorised finance confirmation.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordPayment(StocktakeJob $job, array $data, User $actor, ?string $idempotencyKey = null): Payment
    {
        return $this->payments->initiate($job, [
            'purpose' => 'deposit',
            'method' => $data['method'],
            'amount' => $data['amount'],
            'phone' => $data['phone'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $actor, $idempotencyKey);
    }

    public function waive(StocktakeJob $job, User $actor, string $reason): StocktakeJob
    {
        return DB::transaction(function () use ($job, $actor, $reason): StocktakeJob {
            $locked = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $deposit = StocktakeDeposit::query()->where('job_id', $locked->id)->first();

            $deposit?->update(['status' => 'waived', 'notes' => $reason]);
            $locked->update(['deposit_required' => false, 'deposit_amount' => 0]);

            if ($locked->status === 'awaiting_deposit') {
                $locked = $this->workflow->transition($locked, 'submitted', $actor, 'Deposit waived: '.$reason);
            }

            $this->audit->log('stocktake.deposit.waived', $deposit ?? $locked, $reason, null, [
                'job' => $locked->reference,
            ], $locked->tenant_id, $actor->id);

            return $locked->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(StocktakeJob $job, bool $includePayments = true, bool $platform = false): ?array
    {
        $deposit = $job->relationLoaded('deposit') ? $job->deposit : $job->deposit()->first();

        if ($deposit === null) {
            return null;
        }

        $payments = $includePayments
            ? collect($this->payments->jobPayments($job, $platform))
                ->where('purpose', 'deposit')
                ->values()
                ->all()
            : [];

        return [
            'id' => $deposit->uuid,
            'status' => $deposit->status,
            'amount' => (float) $deposit->amount,
            'paid_amount' => (float) $deposit->paid_amount,
            'outstanding' => round(max(0, (float) $deposit->amount - (float) $deposit->paid_amount), 2),
            'currency' => $deposit->currency,
            'due_at' => $deposit->due_at?->toIso8601String(),
            'overdue' => $deposit->due_at !== null && $deposit->due_at->isPast() && $deposit->status !== 'paid',
            'paid_at' => $deposit->paid_at?->toIso8601String(),
            'policy' => $deposit->policy,
            'payments' => $payments,
        ];
    }
}
