<?php

namespace App\Services\Finance;

use App\Models\Payment;
use App\Models\Refund;
use App\Models\StocktakeJob;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RefundService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(StocktakeJob $job, array $data, User $actor): Refund
    {
        return DB::transaction(function () use ($job, $data, $actor): Refund {
            $payment = Payment::withoutGlobalScope('tenant')
                ->where('uuid', $data['payment_id'])
                ->where('job_id', $job->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->status !== 'succeeded') {
                throw ValidationException::withMessages(['payment_id' => ['Only settled payments can be refunded.']]);
            }

            $already = (float) Refund::withoutGlobalScope('tenant')
                ->where('payment_id', $payment->id)
                ->whereIn('status', ['requested', 'approved', 'processing', 'completed'])
                ->sum('amount');

            $available = round((float) $payment->amount - $already, 2);
            $amount = round((float) $data['amount'], 2);

            if ($amount <= 0 || $amount - 0.005 > $available) {
                throw ValidationException::withMessages([
                    'amount' => [sprintf('Enter a refund up to %s.', number_format(max(0, $available), 2))],
                ]);
            }

            $refund = Refund::withoutGlobalScope('tenant')->create([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'job_id' => $payment->job_id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => 'requested',
                'reason' => $data['reason'],
                'requested_by' => $actor->id,
                'metadata' => ['payment_reference' => $payment->internal_reference],
            ]);

            $this->audit->log('finance.refund.requested', $refund, $data['reason'], null, [
                'payment' => $payment->internal_reference,
                'amount' => $amount,
                'job' => $job->reference,
            ], $payment->tenant_id, $actor->id);

            return $refund->fresh();
        });
    }

    public function approve(Refund $refund, User $actor, ?string $note = null): Refund
    {
        $locked = Refund::withoutGlobalScope('tenant')->whereKey($refund->id)->firstOrFail();

        if ($locked->status !== 'requested') {
            throw ValidationException::withMessages(['refund' => ['Only requested refunds can be approved.']]);
        }

        $locked->update([
            'status' => 'approved',
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'metadata' => [...($locked->metadata ?? []), 'approval_note' => $note],
        ]);

        $this->audit->log('finance.refund.approved', $locked, $note, ['status' => 'requested'], [
            'status' => 'approved',
            'amount' => (float) $locked->amount,
        ], $locked->tenant_id, $actor->id);

        return $locked->fresh();
    }

    public function complete(Refund $refund, User $actor, ?string $reference = null, ?string $outcome = 'success', ?string $reason = null): Refund
    {
        return DB::transaction(function () use ($refund, $actor, $reference, $outcome, $reason): Refund {
            $locked = Refund::withoutGlobalScope('tenant')->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, ['approved', 'processing'], true)) {
                throw ValidationException::withMessages(['refund' => ['Only approved refunds can be processed.']]);
            }

            if ($outcome === 'fail') {
                $locked->update([
                    'status' => 'failed',
                    'failure_reason' => Str::limit($reason ?: 'Refund could not be processed.', 240),
                    'processed_at' => now(),
                ]);

                $this->audit->log('finance.refund.failed', $locked, $reason, ['status' => 'approved'], [
                    'status' => 'failed',
                ], $locked->tenant_id, $actor->id);

                return $locked->fresh();
            }

            $payment = Payment::withoutGlobalScope('tenant')->whereKey($locked->payment_id)->firstOrFail();

            $locked->update([
                'status' => 'completed',
                'provider_reference' => $reference ?: 'REF-'.Str::upper(Str::random(10)),
                'processed_at' => now(),
                'failure_reason' => null,
            ]);

            $this->ledger->postRefund($locked, $payment, $actor->id);

            $payment->update([
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'refunded_amount' => round((float) Refund::withoutGlobalScope('tenant')
                        ->where('payment_id', $payment->id)
                        ->where('status', 'completed')
                        ->sum('amount'), 2),
                ],
            ]);

            $this->audit->log('finance.refund.completed', $locked, null, ['status' => 'approved'], [
                'status' => 'completed',
                'provider_reference' => $locked->provider_reference,
                'amount' => (float) $locked->amount,
            ], $locked->tenant_id, $actor->id);

            return $locked->fresh();
        });
    }

    public function cancel(Refund $refund, User $actor, string $reason): Refund
    {
        $locked = Refund::withoutGlobalScope('tenant')->whereKey($refund->id)->firstOrFail();

        if (! in_array($locked->status, ['requested', 'approved'], true)) {
            throw ValidationException::withMessages(['refund' => ['This refund can no longer be cancelled.']]);
        }

        $locked->update(['status' => 'cancelled', 'failure_reason' => Str::limit($reason, 240)]);

        $this->audit->log('finance.refund.cancelled', $locked, $reason, null, ['status' => 'cancelled'], $locked->tenant_id, $actor->id);

        return $locked->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Refund $refund): array
    {
        return [
            'id' => $refund->uuid,
            'status' => $refund->status,
            'amount' => (float) $refund->amount,
            'currency' => $refund->currency,
            'reason' => $refund->reason,
            'payment' => $refund->payment ? [
                'id' => $refund->payment->uuid,
                'reference' => $refund->payment->internal_reference,
                'method' => $refund->payment->method,
                'purpose' => $refund->payment->purpose,
            ] : null,
            'job' => $refund->job ? [
                'id' => $refund->job->uuid,
                'reference' => $refund->job->reference,
                'title' => $refund->job->title,
            ] : null,
            'requested_by' => $refund->requestedBy?->name,
            'approved_by' => $refund->approvedBy?->name,
            'approved_at' => $refund->approved_at?->toIso8601String(),
            'provider_reference' => $refund->provider_reference,
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'failure_reason' => $refund->failure_reason,
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }
}
