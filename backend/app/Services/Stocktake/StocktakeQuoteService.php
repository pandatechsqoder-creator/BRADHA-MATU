<?php

namespace App\Services\Stocktake;

use App\Models\StocktakeDeposit;
use App\Models\StocktakeJob;
use App\Models\StocktakeQuote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\LedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StocktakeQuoteService
{
    public function __construct(
        private readonly StocktakeWorkflow $workflow,
        private readonly StocktakePolicyService $policies,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function issue(StocktakeJob $job, array $data, User $actor): StocktakeQuote
    {
        return DB::transaction(function () use ($job, $data, $actor): StocktakeQuote {
            $locked = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($locked->quotes()->where('status', 'accepted')->exists()) {
                throw ValidationException::withMessages([
                    'quote' => ['This job already has an accepted quote. Request an amendment first.'],
                ]);
            }

            $locked->quotes()->whereIn('status', ['issued'])->update(['status' => 'superseded', 'superseded_at' => now()]);

            $base = round((float) ($data['base_service_charge'] ?? 0), 2);
            $complexity = round((float) ($data['scope_complexity_charge'] ?? 0), 2);
            $travel = round((float) ($data['travel_expenses'] ?? 0), 2);
            $other = round((float) ($data['other_expenses'] ?? 0), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);

            if ($base + $complexity + $travel + $other <= 0) {
                throw ValidationException::withMessages([
                    'base_service_charge' => ['Enter the service charges for this quote.'],
                ]);
            }

            $subtotal = round(max(0, $base + $complexity + $travel + $other - $discount), 2);
            $policy = $this->policies->policy();
            $taxRate = isset($data['tax_rate']) ? (float) $data['tax_rate'] : (float) $policy->tax_rate;
            $tax = round($subtotal * ($taxRate / 100), 2);
            $total = round($subtotal + $tax, 2);

            $depositRules = $this->policies->depositRules($locked->tenant, $locked->job_type);
            if (isset($data['deposit_type'])) {
                $depositRules['type'] = $data['deposit_type'];
            }
            if (isset($data['deposit_value'])) {
                $depositRules['value'] = (float) $data['deposit_value'];
            }
            $deposit = $this->policies->depositFor($total, $depositRules);
            $existingDeposit = StocktakeDeposit::query()->where('job_id', $locked->id)->first();
            $depositAmount = isset($data['deposit_amount'])
                ? round((float) $data['deposit_amount'], 2)
                : (float) ($existingDeposit?->amount ?? $deposit['amount']);

            $workerBudget = round((float) ($data['worker_compensation_budget'] ?? 0), 2);
            $platformFee = isset($data['platform_fee'])
                ? round((float) $data['platform_fee'], 2)
                : round($subtotal - $workerBudget, 2);
            $margin = round($subtotal - $workerBudget, 2);
            $balanceDue = round(max(0, $total - (float) $locked->deposit_paid_amount), 2);

            $version = (int) $locked->quotes()->max('version') + 1;

            $quote = StocktakeQuote::create([
                'tenant_id' => $locked->tenant_id,
                'job_id' => $locked->id,
                'number' => $this->number(),
                'version' => $version,
                'status' => 'issued',
                'currency' => $locked->currency,
                'base_service_charge' => $base,
                'scope_complexity_charge' => $complexity,
                'travel_expenses' => $travel,
                'other_expenses' => $other,
                'discount' => $discount,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax' => $tax,
                'total' => $total,
                'deposit_type' => $depositRules['type'] ?? null,
                'deposit_value' => $depositRules['value'] ?? null,
                'deposit_amount' => $depositAmount,
                'deposit_due_at' => $locked->deposit_due_at ?? now()->addDays((int) ($depositRules['due_days'] ?? 3)),
                'balance_due' => $balanceDue,
                'worker_compensation_budget' => $workerBudget,
                'worker_compensation_breakdown' => $data['worker_compensation_breakdown'] ?? null,
                'platform_fee' => $platformFee,
                'margin_amount' => $margin,
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'valid_until' => $data['valid_until'] ?? now()->addDays(7),
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'created_by' => $actor->id,
            ]);

            $locked->update([
                'quote_total' => $total,
                'quoted_at' => now(),
                'deposit_required' => $depositAmount > 0,
                'deposit_amount' => max((float) $locked->deposit_amount, $depositAmount),
                'deposit_due_at' => $locked->deposit_due_at ?? $quote->deposit_due_at,
                'currency' => $quote->currency,
            ]);

            $depositRecord = StocktakeDeposit::query()->where('job_id', $locked->id)->first();
            if ($depositRecord !== null) {
                $depositRecord->update([
                    'quote_id' => $quote->id,
                    'amount' => max((float) $depositRecord->amount, $depositAmount),
                ]);
            }

            if (in_array($locked->status, ['submitted', 'needs_tenant_information'], true)) {
                $locked = $this->workflow->transition($locked, 'under_review', $actor, 'Review started with quote preparation.');
            }

            $locked = $this->workflow->transition($locked, 'quote_issued', $actor, 'Quote issued.', ['quote_id' => $quote->id]);
            $this->workflow->transition($locked, 'awaiting_quote_acceptance', $actor, 'Quote sent to tenant.');

            $this->audit->log('stocktake.quote.issued', $quote, null, null, [
                'number' => $quote->number,
                'version' => $version,
                'total' => $total,
                'job' => $locked->reference,
            ], $locked->tenant_id, $actor->id);

            return $quote->fresh();
        });
    }

    public function accept(StocktakeJob $job, User $actor): StocktakeQuote
    {
        return DB::transaction(function () use ($job, $actor): StocktakeQuote {
            $locked = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $quote = $locked->quotes()->where('status', 'issued')->orderByDesc('version')->first();

            if ($quote === null) {
                throw ValidationException::withMessages(['quote' => ['There is no issued quote to accept.']]);
            }

            $deposit = StocktakeDeposit::query()->where('job_id', $locked->id)->first();
            $paid = $deposit ? (float) $deposit->paid_amount : (float) $locked->deposit_paid_amount;
            $waived = $deposit?->status === 'waived';
            if (! $waived && $paid + 0.001 < (float) $quote->deposit_amount) {
                throw ValidationException::withMessages([
                    'deposit' => ['Pay the required deposit before accepting the quote.'],
                ]);
            }

            $balance = round(max(0, (float) $quote->total - $paid), 2);

            $quote->update([
                'status' => 'accepted',
                'balance_due' => $balance,
                'accepted_by' => $actor->id,
                'accepted_at' => now(),
            ]);

            $locked->update([
                'accepted_quote_id' => $quote->id,
                'quote_total' => $quote->total,
                'accepted_at' => now(),
            ]);

            $this->ledger->postQuoteAccepted($quote, $paid, $actor->id);

            $target = $balance > 0 ? 'awaiting_balance_payment' : 'approved_for_execution';
            $locked = $this->workflow->transition($locked, $target, $actor, 'Tenant accepted quote '.$quote->number.'.');

            $this->audit->log('stocktake.quote.accepted', $quote, null, null, [
                'number' => $quote->number,
                'total' => $quote->total,
                'balance_due' => $balance,
                'job' => $locked->reference,
            ], $locked->tenant_id, $actor->id);

            return $quote->fresh();
        });
    }

    public function decline(StocktakeJob $job, User $actor, string $reason): StocktakeQuote
    {
        return DB::transaction(function () use ($job, $actor, $reason): StocktakeQuote {
            $locked = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $quote = $locked->quotes()->where('status', 'issued')->orderByDesc('version')->first();

            if ($quote === null) {
                throw ValidationException::withMessages(['quote' => ['There is no issued quote to decline.']]);
            }

            $quote->update([
                'status' => 'declined',
                'declined_by' => $actor->id,
                'declined_at' => now(),
                'decline_reason' => $reason,
            ]);

            $this->workflow->transition($locked, 'under_review', $actor, 'Tenant requested a revised quote: '.$reason);

            $this->audit->log('stocktake.quote.declined', $quote, $reason, null, [
                'number' => $quote->number,
                'job' => $locked->reference,
            ], $locked->tenant_id, $actor->id);

            return $quote->fresh();
        });
    }

    private function number(): string
    {
        do {
            $number = 'STQ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (StocktakeQuote::withoutGlobalScope('tenant')->where('number', $number)->exists());

        return $number;
    }
}
