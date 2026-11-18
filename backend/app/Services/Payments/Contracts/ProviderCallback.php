<?php

namespace App\Services\Payments\Contracts;

final class ProviderCallback
{
    /**
     * @param  array<string, mixed>  $payload  Redacted, normalised provider payload.
     */
    public function __construct(
        public readonly string $callbackId,
        public readonly ?string $providerReference,
        public readonly ?string $transactionReference,
        public readonly float $amount,
        public readonly string $currency,
        public readonly bool $successful,
        public readonly string $message,
        public readonly array $payload = [],
    ) {}
}
