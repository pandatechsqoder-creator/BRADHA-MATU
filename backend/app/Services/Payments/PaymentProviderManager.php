<?php

namespace App\Services\Payments;

use App\Services\Payments\Contracts\PaymentProvider;
use App\Services\Payments\Providers\CardSandboxProvider;
use App\Services\Payments\Providers\ManualProvider;
use App\Services\Payments\Providers\MpesaSandboxProvider;
use InvalidArgumentException;

final class PaymentProviderManager
{
    /** @var array<int, PaymentProvider> */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            new MpesaSandboxProvider,
            new CardSandboxProvider,
            new ManualProvider('bank'),
            new ManualProvider('manual'),
        ];
    }

    public function forMethod(string $method): PaymentProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($method)) {
                return $provider;
            }
        }

        throw new InvalidArgumentException("No payment provider supports {$method}.");
    }

    public function byName(string $name): PaymentProvider
    {
        foreach ($this->providers as $provider) {
            if ($provider->name() === $name) {
                return $provider;
            }
        }

        throw new InvalidArgumentException("Unknown payment provider {$name}.");
    }

    /**
     * @return array<int, array{method: string, provider: string, label: string, mode: string}>
     */
    public function options(): array
    {
        return [
            ['method' => 'mpesa', 'provider' => 'mpesa_sandbox', 'label' => 'M-Pesa STK Push (sandbox)', 'mode' => 'sandbox'],
            ['method' => 'card', 'provider' => 'card_sandbox', 'label' => 'Card gateway (sandbox)', 'mode' => 'sandbox'],
            ['method' => 'bank', 'provider' => 'bank', 'label' => 'Bank transfer', 'mode' => 'manual'],
            ['method' => 'manual', 'provider' => 'manual', 'label' => 'Manual / cash', 'mode' => 'manual'],
        ];
    }
}
