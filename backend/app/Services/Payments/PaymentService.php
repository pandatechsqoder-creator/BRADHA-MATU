<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\StocktakeDeposit;
use App\Models\StocktakeJob;
use App\Models\StocktakeQuote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Finance\LedgerService;
use App\Services\Payments\Contracts\ProviderCallback;
use App\Services\Stocktake\StocktakeWorkflow;
use App\Services\Stocktake\SystemActor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderManager $providers,
        private readonly StocktakeWorkflow $workflow,
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a payment instruction for a job. Payment success is only ever
     * recorded server-side from a provider callback or a finance verification.
     *
     * @param  array<string, mixed>  $data
     */
    public function initiate(StocktakeJob $job, array $data, User $actor, ?string $idempotencyKey = null): Payment
    {
        return DB::transaction(function () use ($job, $data, $actor, $idempotencyKey): Payment {
            $locked = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null) {
                $existing = Payment::withoutGlobalScope('tenant')
                    ->where('tenant_id', $locked->tenant_id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    $requested = isset($data['amount']) && $data['amount'] !== null
                        ? round((float) $data['amount'], 2)
                        : (float) $existing->amount;

                    $same = $existing->job_id === $locked->id
                        && $existing->purpose === ($data['purpose'] ?? null)
                        && $existing->method === ($data['method'] ?? null)
                        && abs((float) $existing->amount - $requested) < 0.005;

                    if (! $same) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['This idempotency key was already used for a different payment.'],
                        ]);
                    }

                    return $existing->fresh(['attempts']);
                }
            }

            $purpose = (string) ($data['purpose'] ?? '');
            $method = (string) ($data['method'] ?? '');

            if (! in_array($purpose, ['deposit', 'balance'], true)) {
                throw ValidationException::withMessages(['purpose' => ['Choose deposit or balance.']]);
            }
            if (! in_array($method, ['mpesa', 'card', 'bank', 'manual'], true)) {
                throw ValidationException::withMessages(['method' => ['Choose a supported payment method.']]);
            }

            $this->ensureNoPaymentInFlight($locked, $purpose);

            [$outstanding, $targetType, $targetId, $quote] = $this->outstandingFor($locked, $purpose);

            $amount = isset($data['amount']) && $data['amount'] !== null
                ? round((float) $data['amount'], 2)
                : $outstanding;

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => ['There is nothing outstanding to pay.']]);
            }
            if ($amount - 0.005 > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => ['The amount exceeds the outstanding '.$purpose.'.'],
                ]);
            }

            if ($method === 'mpesa') {
                $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? '')) ?? '';
                if (strlen($phone) < 9 || strlen($phone) > 12) {
                    throw ValidationException::withMessages(['phone' => ['Enter the M-Pesa phone number.']]);
                }
            }

            $provider = $this->providers->forMethod($method);

            $payment = Payment::withoutGlobalScope('tenant')->create([
                'tenant_id' => $locked->tenant_id,
                'job_id' => $locked->id,
                'quote_id' => $quote?->id,
                'purpose' => $purpose,
                'method' => $method,
                'provider' => $provider->name(),
                'amount' => $amount,
                'currency' => $locked->currency,
                'status' => 'pending',
                'internal_reference' => $this->reference(),
                'idempotency_key' => $idempotencyKey,
                'phone' => $method === 'mpesa' ? (string) ($data['phone'] ?? null) : null,
                'initiated_by' => $actor->id,
                'metadata' => ['notes' => $data['notes'] ?? null],
            ]);

            PaymentAllocation::withoutGlobalScope('tenant')->create([
                'tenant_id' => $locked->tenant_id,
                'payment_id' => $payment->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'amount' => $amount,
                'currency' => $locked->currency,
            ]);

            $initiation = $provider->initiate($payment, [
                'phone' => $data['phone'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            PaymentAttempt::create([
                'payment_id' => $payment->id,
                'provider' => $provider->name(),
                'status' => $initiation->status === 'processing' ? 'processing' : 'initiated',
                'provider_reference' => $initiation->providerReference,
                'request' => $initiation->request ?: null,
                'response' => $initiation->response ?: null,
                'attempted_at' => now(),
            ]);

            $payment->update([
                'status' => $initiation->status,
                'metadata' => [
                    ...($payment->metadata ?? []),
                    'instructions' => $initiation->instructions,
                ],
            ]);

            if ($purpose === 'deposit' && $locked->status === 'awaiting_deposit') {
                $this->workflow->transition($locked, 'deposit_pending_verification', $actor, 'Deposit payment initiated with '.$provider->name().'.', [
                    'payment_id' => $payment->uuid,
                    'amount' => $amount,
                    'method' => $method,
                ]);
            }

            if ($purpose === 'balance' && $locked->status === 'awaiting_balance_payment') {
                $this->workflow->transition($locked, 'payment_pending_verification', $actor, 'Balance payment initiated with '.$provider->name().'.', [
                    'payment_id' => $payment->uuid,
                    'amount' => $amount,
                    'method' => $method,
                ]);
            }

            $this->audit->log('finance.payment.initiated', $payment, null, null, [
                'reference' => $payment->internal_reference,
                'purpose' => $purpose,
                'method' => $method,
                'amount' => $amount,
                'job' => $locked->reference,
            ], $locked->tenant_id, $actor->id);

            return $payment->fresh(['attempts']);
        });
    }

    public function verifyManual(Payment $payment, User $actor, string $decision, ?string $reference = null, ?string $reason = null): Payment
    {
        $locked = Payment::withoutGlobalScope('tenant')->whereKey($payment->id)->firstOrFail();

        if ($locked->status !== 'pending') {
            throw ValidationException::withMessages(['payment' => ['This payment is no longer awaiting verification.']]);
        }

        if (! in_array($locked->provider, ['bank', 'manual'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Only bank and manual payments can be confirmed by finance. Provider payments settle from their signed callback.'],
            ]);
        }

        if ($decision === 'confirm') {
            $reference = $reference ?: 'MANUAL-'.Str::upper(Str::random(10));

            try {
                return $this->settle($locked, $actor, $reference);
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'reference' => ['That payment reference has already been used.'],
                ]);
            }
        }

        return $this->fail($locked, $actor, $reason ?: 'Payment could not be verified.', true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handleCallback(string $providerName, array $payload, bool $signatureValid, string $note = ''): array
    {
        $provider = $this->providers->byName($providerName);

        try {
            $callback = $provider->parseCallback($payload);
        } catch (\Throwable) {
            $callbackId = hash('sha256', json_encode($payload));

            PaymentCallback::create([
                'provider' => $providerName,
                'callback_id' => $callbackId,
                'status' => 'rejected',
                'signature_valid' => $signatureValid,
                'payload' => ['malformed' => true],
                'processed_at' => now(),
                'note' => 'Malformed provider callback.',
            ]);

            return ['status' => 'rejected', 'message' => 'Malformed provider callback.'];
        }

        $existing = PaymentCallback::query()
            ->where('provider', $providerName)
            ->where('callback_id', $callback->callbackId)
            ->first();

        if ($existing !== null && in_array($existing->status, ['accepted', 'ignored'], true)) {
            return [
                'status' => $existing->status,
                'duplicate' => true,
                'payment_id' => $existing->payment_id ? Payment::withoutGlobalScope('tenant')->find($existing->payment_id)?->uuid : null,
                'message' => 'Callback already processed.',
            ];
        }

        $payment = $this->findPaymentForCallback($providerName, $callback);

        $record = PaymentCallback::query()->updateOrCreate(
            ['provider' => $providerName, 'callback_id' => $callback->callbackId],
            [
                'payment_id' => $payment?->id,
                'provider_reference' => $callback->transactionReference ?? $callback->providerReference,
                'amount' => $callback->amount,
                'currency' => $callback->currency,
                'status' => ! $signatureValid ? 'rejected' : ($payment === null ? 'ignored' : 'accepted'),
                'signature_valid' => $signatureValid,
                'payload' => $callback->payload,
                'processed_at' => now(),
                'note' => trim($note) ?: null,
            ],
        );

        if (! $signatureValid) {
            return ['status' => 'rejected', 'message' => 'Invalid callback signature.'];
        }

        if ($payment === null) {
            return ['status' => 'ignored', 'message' => 'No matching payment is awaiting this callback.'];
        }

        if (abs($callback->amount - (float) $payment->amount) > 0.005 || strtoupper($callback->currency) !== strtoupper($payment->currency)) {
            $record->update([
                'status' => 'rejected',
                'note' => 'Callback amount or currency does not match the payment instruction.',
            ]);

            return [
                'status' => 'rejected',
                'payment_id' => $payment->uuid,
                'message' => 'Callback amount or currency does not match the payment.',
            ];
        }

        if (! $callback->successful) {
            $this->fail($payment, new SystemActor, $callback->message ?: 'Provider reported the payment as failed.', true);

            return ['status' => 'accepted', 'payment_id' => $payment->uuid, 'payment_status' => 'failed'];
        }

        try {
            $settled = $this->settle(
                $payment,
                new SystemActor,
                $callback->transactionReference ?? $callback->callbackId,
                $callback->payload,
            );
        } catch (QueryException) {
            $record->update(['status' => 'rejected', 'note' => 'Provider reference already used by another payment.']);

            return ['status' => 'rejected', 'payment_id' => $payment->uuid, 'message' => 'Provider reference already used.'];
        } catch (ValidationException $exception) {
            $record->update(['status' => 'rejected', 'note' => collect($exception->errors())->flatten()->first()]);

            return ['status' => 'rejected', 'payment_id' => $payment->uuid, 'message' => collect($exception->errors())->flatten()->first()];
        }

        return ['status' => 'accepted', 'payment_id' => $settled->uuid, 'payment_status' => $settled->status];
    }

    /**
     * Sandbox-only: send a simulated provider callback through the real
     * callback pipeline. Never available in production.
     *
     * @return array<string, mixed>
     */
    public function simulate(Payment $payment, string $outcome, User $actor): array
    {
        if (! config('payments.sandbox') || app()->environment('production')) {
            throw ValidationException::withMessages(['payment' => ['Sandbox simulation is disabled.']]);
        }

        $locked = Payment::withoutGlobalScope('tenant')->whereKey($payment->id)->firstOrFail();

        if (! in_array($locked->provider, ['mpesa_sandbox', 'card_sandbox'], true)) {
            throw ValidationException::withMessages(['payment' => ['Only sandbox provider payments can be simulated.']]);
        }
        if ($locked->status !== 'processing') {
            throw ValidationException::withMessages(['payment' => ['This payment is not awaiting a provider callback.']]);
        }

        $attempt = PaymentAttempt::query()->where('payment_id', $locked->id)->latest('id')->first();
        $reference = $attempt?->provider_reference;

        if ($locked->provider === 'mpesa_sandbox') {
            $payload = [
                'CheckoutRequestID' => $reference,
                'MerchantRequestID' => $attempt?->uuid,
                'ResultCode' => $outcome === 'success' ? 0 : 1032,
                'ResultDesc' => $outcome === 'success'
                    ? 'The service request is processed successfully.'
                    : 'Request cancelled by user',
                'Amount' => (float) $locked->amount,
                'Currency' => $locked->currency,
                'MpesaReceiptNumber' => $outcome === 'success' ? 'SBX'.Str::upper(Str::random(8)) : null,
                'PhoneNumber' => $locked->phone,
            ];
        } else {
            $payload = [
                'session_reference' => $reference,
                'transaction_reference' => $outcome === 'success' ? 'SBXCARD'.Str::upper(Str::random(8)) : null,
                'amount' => (float) $locked->amount,
                'currency' => $locked->currency,
                'status' => $outcome === 'success' ? 'captured' : 'declined',
                'message' => $outcome === 'success' ? 'Sandbox card captured.' : 'Sandbox card declined.',
            ];
        }

        $result = $this->handleCallback($locked->provider, $payload, true, 'Sandbox simulation by '.$actor->name);
        $this->audit->log('finance.payment.simulated', $locked, null, null, [
            'outcome' => $outcome,
            'result' => $result['status'] ?? null,
        ], $locked->tenant_id, $actor->id);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $callbackPayload
     */
    public function settle(
        Payment $payment,
        User|SystemActor $actor,
        ?string $providerReference = null,
        array $callbackPayload = [],
    ): Payment {
        return DB::transaction(function () use ($payment, $actor, $providerReference, $callbackPayload): Payment {
            $locked = Payment::withoutGlobalScope('tenant')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'succeeded') {
                return $locked->fresh();
            }

            if (! in_array($locked->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['payment' => ['A failed or cancelled payment cannot be settled.']]);
            }

            $locked->update([
                'status' => 'succeeded',
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'succeeded_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'metadata' => $callbackPayload
                    ? [...($locked->metadata ?? []), 'callback' => $callbackPayload]
                    : $locked->metadata,
            ]);

            $allocation = PaymentAllocation::withoutGlobalScope('tenant')->where('payment_id', $locked->id)->first();
            if ($allocation !== null) {
                PaymentAllocation::withoutGlobalScope('tenant')->whereKey($allocation->id)->update(['applied_at' => now()]);
            }

            if ($locked->purpose === 'deposit') {
                $this->ledger->postDepositReceived($locked, $actor instanceof User ? $actor->id : null);
            } else {
                $this->ledger->postBalanceReceived($locked, $actor instanceof User ? $actor->id : null);
            }

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($locked->job_id)->lockForUpdate()->firstOrFail();

            if ($locked->purpose === 'deposit') {
                $this->syncDeposit($job);
            } else {
                $this->syncBalance($job);
            }

            $this->advanceAfterSettlement($job->fresh(), $locked, $actor);

            $this->audit->log('finance.payment.settled', $locked, null, ['status' => 'processing'], [
                'reference' => $locked->internal_reference,
                'provider_reference' => $locked->provider_reference,
                'amount' => (float) $locked->amount,
                'purpose' => $locked->purpose,
                'job' => $job->reference,
            ], $locked->tenant_id, $actor instanceof User ? $actor->id : null);

            return $locked->fresh();
        });
    }

    public function fail(Payment $payment, User|SystemActor $actor, string $reason, bool $returnToAwaiting = true): Payment
    {
        return DB::transaction(function () use ($payment, $actor, $reason, $returnToAwaiting): Payment {
            $locked = Payment::withoutGlobalScope('tenant')->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'succeeded') {
                throw ValidationException::withMessages(['payment' => ['A settled payment cannot be failed. Use a refund instead.']]);
            }

            if ($locked->status === 'failed') {
                return $locked->fresh();
            }

            $locked->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => Str::limit($reason, 240),
            ]);

            $paymentAttempt = PaymentAttempt::query()->where('payment_id', $locked->id)->latest('id')->first();
            $paymentAttempt?->update(['status' => 'failed', 'error' => Str::limit($reason, 240)]);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($locked->job_id)->lockForUpdate()->firstOrFail();

            if ($returnToAwaiting) {
                if ($locked->purpose === 'deposit' && $job->status === 'deposit_pending_verification') {
                    $this->workflow->transition($job, 'awaiting_deposit', $actor, 'Deposit payment failed: '.Str::limit($reason, 120), [
                        'payment_id' => $locked->uuid,
                    ]);
                }

                if ($locked->purpose === 'balance' && $job->status === 'payment_pending_verification') {
                    $this->workflow->transition($job, 'awaiting_balance_payment', $actor, 'Balance payment failed: '.Str::limit($reason, 120), [
                        'payment_id' => $locked->uuid,
                    ]);
                }
            }

            $this->audit->log('finance.payment.failed', $locked, $reason, ['status' => 'processing'], [
                'reference' => $locked->internal_reference,
                'job' => $job->reference,
            ], $locked->tenant_id, $actor instanceof User ? $actor->id : null);

            return $locked->fresh();
        });
    }

    public function cancel(Payment $payment, User $actor, string $reason): Payment
    {
        return $this->fail($payment, $actor, $reason, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Payment $payment, bool $platform = false): array
    {
        $attempt = $payment->relationLoaded('attempts')
            ? $payment->attempts->last()
            : $payment->attempts()->latest('id')->first();

        return [
            'id' => $payment->uuid,
            'purpose' => $payment->purpose,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'method' => $payment->method,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'internal_reference' => $payment->internal_reference,
            'provider_reference' => $payment->provider_reference,
            'checkout_reference' => $attempt?->provider_reference,
            'phone' => $this->maskPhone($payment->phone),
            'instructions' => $payment->metadata['instructions'] ?? null,
            'notes' => $payment->metadata['notes'] ?? null,
            'failure_reason' => $payment->failure_reason,
            'sandbox' => str_ends_with($payment->provider, '_sandbox'),
            'can_simulate' => str_ends_with($payment->provider, '_sandbox') && $payment->status === 'processing',
            'can_verify' => $payment->status === 'pending' && in_array($payment->provider, ['bank', 'manual'], true),
            'created_at' => $payment->created_at?->toIso8601String(),
            'succeeded_at' => $payment->succeeded_at?->toIso8601String(),
            'failed_at' => $payment->failed_at?->toIso8601String(),
            'initiated_by' => $platform ? $payment->initiatedBy?->name : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function jobPayments(StocktakeJob $job, bool $platform = false): array
    {
        return Payment::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->with(['attempts', 'initiatedBy'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Payment $payment) => $this->payload($payment, $platform))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function balanceSummary(StocktakeJob $job): ?array
    {
        $quote = $job->acceptedQuote ?? $job->latestQuote;
        if ($quote === null) {
            return null;
        }

        $paid = $this->settledBalanceTotal($job, $quote);

        return [
            'quote_number' => $quote->number,
            'total' => (float) $quote->total,
            'deposit_applied' => (float) $job->deposit_paid_amount,
            'due' => round(max(0, (float) $quote->balance_due), 2),
            'paid' => $paid,
            'currency' => $quote->currency,
            'due_at' => $quote->accepted_at?->copy()->addDays((int) config('payments.balance_due_days', 7))->toIso8601String(),
        ];
    }

    private function ensureNoPaymentInFlight(StocktakeJob $job, string $purpose): void
    {
        $inFlight = Payment::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->where('purpose', $purpose)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($inFlight) {
            throw ValidationException::withMessages([
                'payment' => ['A '.$purpose.' payment is already in progress. Complete or cancel it before starting another.'],
            ]);
        }
    }

    /**
     * @return array{0: float, 1: string, 2: int|null, 3: StocktakeQuote|null}
     */
    private function outstandingFor(StocktakeJob $job, string $purpose): array
    {
        if ($purpose === 'deposit') {
            if (! in_array($job->status, ['awaiting_deposit', 'deposit_pending_verification'], true)) {
                throw ValidationException::withMessages(['deposit' => ['This job is not awaiting a deposit.']]);
            }

            $deposit = StocktakeDeposit::withoutGlobalScope('tenant')->where('job_id', $job->id)->first();
            if ($deposit === null || $deposit->status === 'waived') {
                throw ValidationException::withMessages(['deposit' => ['No deposit is required for this job.']]);
            }
            if ($deposit->status === 'paid') {
                throw ValidationException::withMessages(['deposit' => ['This deposit is already settled.']]);
            }

            return [
                round(max(0, (float) $deposit->amount - (float) $deposit->paid_amount), 2),
                'deposit',
                $deposit->id,
                $deposit->quote_id ? StocktakeQuote::withoutGlobalScope('tenant')->find($deposit->quote_id) : null,
            ];
        }

        if (! in_array($job->status, ['awaiting_balance_payment', 'payment_pending_verification'], true)) {
            throw ValidationException::withMessages(['balance' => ['This job is not awaiting a balance payment.']]);
        }

        $quote = $job->acceptedQuote
            ?? $job->quotes()->where('status', 'accepted')->orderByDesc('version')->first();

        if ($quote === null) {
            throw ValidationException::withMessages(['balance' => ['This job has no accepted quote.']]);
        }

        return [round(max(0, (float) $quote->balance_due), 2), 'balance', $quote->id, $quote];
    }

    private function findPaymentForCallback(string $providerName, ProviderCallback $callback): ?Payment
    {
        if ($callback->providerReference === null) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('provider', $providerName)
            ->where('provider_reference', $callback->providerReference)
            ->latest('id')
            ->first();

        if ($attempt === null) {
            return null;
        }

        $payment = Payment::withoutGlobalScope('tenant')->find($attempt->payment_id);

        if ($payment === null || $payment->provider !== $providerName) {
            return null;
        }

        if (! in_array($payment->status, ['processing', 'pending'], true)) {
            return $payment->status === 'succeeded' ? $payment : null;
        }

        return $payment;
    }

    private function syncDeposit(StocktakeJob $job): void
    {
        $deposit = StocktakeDeposit::withoutGlobalScope('tenant')->where('job_id', $job->id)->lockForUpdate()->first();
        if ($deposit === null) {
            return;
        }

        $total = (float) PaymentAllocation::withoutGlobalScope('tenant')
            ->where('target_type', 'deposit')
            ->where('target_id', $deposit->id)
            ->whereHas('payment', fn ($query) => $query->where('status', 'succeeded'))
            ->sum('amount');

        $paid = round($total, 2) + 0.005 >= (float) $deposit->amount && (float) $deposit->amount > 0;
        $deposit->update([
            'paid_amount' => round($total, 2),
            'status' => $paid ? 'paid' : ($total > 0 ? 'partially_paid' : $deposit->status),
            'paid_at' => $paid ? now() : null,
        ]);

        $job->update(['deposit_paid_amount' => round($total, 2)]);
    }

    private function syncBalance(StocktakeJob $job): void
    {
        $quote = StocktakeQuote::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->where('status', 'accepted')
            ->lockForUpdate()
            ->first();

        if ($quote === null) {
            return;
        }

        $depositPaid = (float) PaymentAllocation::withoutGlobalScope('tenant')
            ->where('target_type', 'deposit')
            ->whereIn('target_id', StocktakeDeposit::withoutGlobalScope('tenant')->where('job_id', $job->id)->pluck('id'))
            ->whereHas('payment', fn ($query) => $query->where('status', 'succeeded'))
            ->sum('amount');

        $balancePaid = $this->settledBalanceTotal($job, $quote);
        $due = max(0, round((float) $quote->total - $depositPaid - $balancePaid, 2));

        $quote->update(['balance_due' => $due]);
    }

    private function settledBalanceTotal(StocktakeJob $job, StocktakeQuote $quote): float
    {
        return round((float) PaymentAllocation::withoutGlobalScope('tenant')
            ->where('target_type', 'balance')
            ->where('target_id', $quote->id)
            ->whereHas('payment', fn ($query) => $query->where('status', 'succeeded'))
            ->sum('amount'), 2);
    }

    private function advanceAfterSettlement(StocktakeJob $job, Payment $payment, User|SystemActor $actor): void
    {
        if ($payment->purpose === 'deposit') {
            $deposit = StocktakeDeposit::withoutGlobalScope('tenant')->where('job_id', $job->id)->first();

            if ($deposit?->status === 'paid' && $job->status === 'deposit_pending_verification') {
                $this->workflow->transition($job, 'submitted', $actor, 'Deposit received and verified.', [
                    'payment_id' => $payment->uuid,
                ]);
            }

            return;
        }

        $quote = StocktakeQuote::withoutGlobalScope('tenant')
            ->where('job_id', $job->id)
            ->where('status', 'accepted')
            ->first();

        if ($quote === null) {
            return;
        }

        if ((float) $quote->balance_due <= 0.005) {
            if ($job->status === 'payment_pending_verification') {
                $this->workflow->transition($job, 'approved_for_execution', $actor, 'Job balance received in full.', [
                    'payment_id' => $payment->uuid,
                ]);
            }

            return;
        }

        if ($job->status === 'payment_pending_verification') {
            $this->workflow->transition($job, 'awaiting_balance_payment', $actor, 'Partial balance payment received.', [
                'payment_id' => $payment->uuid,
                'outstanding' => (float) $quote->balance_due,
            ]);
        }
    }

    private function reference(): string
    {
        do {
            $reference = 'PAY-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Payment::withoutGlobalScope('tenant')->where('internal_reference', $reference)->exists());

        return $reference;
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        return strlen($digits) >= 8 ? substr($digits, 0, 4).'****'.substr($digits, -3) : 'phone';
    }
}
