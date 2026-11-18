<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;

interface PaymentProvider
{
    public function name(): string;

    public function supports(string $method): bool;

    /**
     * @param  array<string, mixed>  $context
     */
    public function initiate(Payment $payment, array $context = []): ProviderInitiation;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseCallback(array $payload): ProviderCallback;
}
