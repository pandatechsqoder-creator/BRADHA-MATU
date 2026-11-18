<?php

namespace App\Services\Payments\Providers;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Payments\Contracts\ProviderCallback;
use App\Services\Payments\Contracts\ProviderInitiation;
use Illuminate\Support\Str;

/**
 * Sandbox card gateway. The redirect URL only carries the reference; the
 * payment becomes successful only after a signed server-to-server callback.
 */
final class CardSandboxProvider implements PaymentProvider
{
    public function name(): string
    {
        return 'card_sandbox';
    }

    public function supports(string $method): bool
    {
        return $method === 'card';
    }

    public function initiate(Payment $payment, array $context = []): ProviderInitiation
    {
        $session = 'CARD-'.Str::upper(Str::random(18));

        return new ProviderInitiation(
            status: 'processing',
            providerReference: $session,
            instructions: [
                'mode' => 'sandbox',
                'label' => 'Card gateway (sandbox)',
                'message' => 'Open the sandbox card checkout. Returning to this page does not mark the payment paid; only the signed gateway callback does.',
                'redirect_url' => 'https://sandbox.example.test/checkout/'.$session,
                'session_reference' => $session,
                'requires_provider_callback' => true,
            ],
            request: [
                'amount' => (int) round($payment->amount),
                'currency' => $payment->currency,
                'reference' => $payment->internal_reference,
                'callback_url' => config('payments.card.callback_url'),
            ],
            response: [
                'session_reference' => $session,
                'status' => 'requires_confirmation',
            ],
        );
    }

    public function parseCallback(array $payload): ProviderCallback
    {
        $session = (string) ($payload['session_reference'] ?? '');
        $transaction = (string) ($payload['transaction_reference'] ?? '');

        return new ProviderCallback(
            callbackId: $transaction !== '' ? $transaction : (string) Str::uuid(),
            providerReference: $session !== '' ? $session : null,
            transactionReference: $transaction !== '' ? $transaction : null,
            amount: round((float) ($payload['amount'] ?? 0), 2),
            currency: strtoupper((string) ($payload['currency'] ?? 'KES')),
            successful: in_array((string) ($payload['status'] ?? ''), ['captured', 'succeeded', 'success'], true),
            message: (string) ($payload['message'] ?? ''),
            payload: [
                'session_reference' => $session,
                'transaction_reference' => $transaction,
                'amount' => (float) ($payload['amount'] ?? 0),
                'currency' => $payload['currency'] ?? null,
                'status' => $payload['status'] ?? null,
            ],
        );
    }
}
