<?php

namespace App\Services\Payments\Contracts;

final class ProviderInitiation
{
    /**
     * @param  array<string, mixed>  $instructions
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference,
        public readonly array $instructions = [],
        public readonly array $request = [],
        public readonly array $response = [],
    ) {}
}
