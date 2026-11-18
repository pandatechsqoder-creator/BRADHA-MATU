<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Payments\Contracts\ProviderCallback;
use App\Services\Payments\Contracts\ProviderInitiation;
use RuntimeException;

/**
 * Bank transfer and manual deposits. A finance user verifies the funds before
 * the payment settles; there is no provider callback.
 */
final class ManualProvider implements PaymentProvider
{
    public function __construct(private readonly string $kind) {}

    public function name(): string
    {
        return $this->kind;
    }

    public function supports(string $method): bool
    {
        return $method === $this->kind;
    }

    public function initiate(Payment $payment, array $context = []): ProviderInitiation
    {
        $bank = config('payments.bank');

        return new ProviderInitiation(
            status: 'pending',
            providerReference: null,
            instructions: [
                'mode' => 'manual',
                'label' => $this->kind === 'bank' ? 'Bank transfer' : 'Manual or cash payment',
                'message' => 'BRATHAMATU finance confirms this payment after checking the bank or cash receipt. The request continues only after confirmation.',
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'reference' => $payment->internal_reference,
                'bank' => $this->kind === 'bank' ? $bank : null,
                'requires_finance_verification' => true,
            ],
            request: ['reference' => $payment->internal_reference],
            response: ['status' => 'awaiting_finance_verification'],
        );
    }

    public function parseCallback(array $payload): ProviderCallback
    {
        throw new RuntimeException('Manual payments do not use provider callbacks.');
    }
}
