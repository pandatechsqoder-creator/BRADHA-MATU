<?php

namespace App\Services\Catalogue;

use App\Models\Catalogue;
use App\Models\CatalogueInvoice;
use App\Models\CataloguePricingPolicy;
use App\Models\CataloguePricingVersion;
use App\Models\CatalogueVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CataloguePricingService
{
    /**
     * The interim default policy preserves the published anchor points:
     * KSh 100 at 100 products and a KSh 2,000 cap from 6,000 products.
     */
    private const DEFAULTS = [
        'name' => 'Standard catalogue pricing',
        'pricing_model' => 'banded',
        'currency' => 'KES',
        'product_count_basis' => 'active_variants',
        'base_charge' => 100,
        'included_products' => 100,
        'scale_products' => 6000,
        'max_charge' => 2000,
        'tax_rate' => 0,
        'notes' => 'Interim banded pricing pending commercial confirmation between the published anchor points.',
    ];

    public function policy(): CataloguePricingPolicy
    {
        $policy = CataloguePricingPolicy::query()->where('is_active', true)->latest('id')->first();
        if ($policy !== null) {
            return $policy;
        }

        return DB::transaction(function (): CataloguePricingPolicy {
            $policy = CataloguePricingPolicy::create([
                ...self::DEFAULTS,
                'is_active' => true,
                'effective_from' => now(),
            ]);

            CataloguePricingVersion::create([
                'policy_id' => $policy->id,
                'version' => 1,
                'rules' => $this->rules($policy),
                'effective_from' => $policy->effective_from,
            ]);

            return $policy;
        });
    }

    public function activeVersion(): CataloguePricingVersion
    {
        $policy = $this->policy();

        return $policy->versions()->first() ?? CataloguePricingVersion::create([
            'policy_id' => $policy->id,
            'version' => 1,
            'rules' => $this->rules($policy),
            'effective_from' => $policy->effective_from,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(CataloguePricingPolicy $policy): array
    {
        return [
            'pricing_model' => $policy->pricing_model,
            'currency' => $policy->currency,
            'product_count_basis' => $policy->product_count_basis,
            'base_charge' => (float) $policy->base_charge,
            'included_products' => $policy->included_products,
            'scale_products' => $policy->scale_products,
            'max_charge' => (float) $policy->max_charge,
            'tax_rate' => (float) $policy->tax_rate,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePolicy(array $data, ?User $actor = null): CataloguePricingPolicy
    {
        return DB::transaction(function () use ($data, $actor): CataloguePricingPolicy {
            $policy = $this->policy();
            $policy->update($data);

            $nextVersion = (int) $policy->versions()->max('version') + 1;

            CataloguePricingVersion::create([
                'policy_id' => $policy->id,
                'version' => $nextVersion,
                'rules' => $this->rules($policy),
                'created_by' => $actor?->id,
                'effective_from' => now(),
            ]);

            return $policy->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function estimate(int $productCount, ?CataloguePricingPolicy $policy = null): array
    {
        $policy ??= $this->policy();
        $count = max(0, $productCount);
        $base = (float) $policy->base_charge;
        $max = (float) $policy->max_charge;
        $included = max(1, (int) $policy->included_products);
        $scale = max($included + 1, (int) $policy->scale_products);

        [$subtotal, $explanation] = match ($policy->pricing_model) {
            'flat' => [$base, 'Flat charge for this catalogue version.'],
            'per_product' => [min($base * $count, $max), 'Charged per active product, capped at the maximum.'],
            'minimum' => [$base, 'Minimum catalogue charge applies.'],
            'capped' => [min($base + $count, $max), 'Base charge plus units, capped at the maximum.'],
            'promotional' => [$base, 'Promotional catalogue rate.'],
            'negotiated' => [$base, 'Negotiated rate recorded by platform finance.'],
            default => $this->banded($count, $base, $included, $scale, $max),
        };

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * ((float) $policy->tax_rate / 100), 2);

        return [
            'policy_id' => $policy->uuid,
            'pricing_model' => $policy->pricing_model,
            'product_count' => $count,
            'product_count_basis' => $policy->product_count_basis,
            'currency' => $policy->currency,
            'base_charge' => $base,
            'included_products' => $included,
            'scale_products' => $scale,
            'max_charge' => $max,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
            'explanation' => $explanation,
        ];
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function banded(int $count, float $base, int $included, int $scale, float $max): array
    {
        if ($count <= $included) {
            return [$base, "Includes up to {$included} products."];
        }

        if ($count >= $scale) {
            return [$max, 'Maximum catalogue charge applied (above '.number_format($scale).' products).'];
        }

        $ratio = ($count - $included) / ($scale - $included);
        $charge = $base + (($max - $base) * $ratio);

        return [round($charge, 2), 'Banded charge interpolated between the published anchor points.'];
    }

    public function invoiceFor(Catalogue $catalogue, CatalogueVersion $version, ?User $actor = null): CatalogueInvoice
    {
        $existing = CatalogueInvoice::query()
            ->where('tenant_id', $catalogue->tenant_id)
            ->where('catalogue_version_id', $version->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $policyVersion = $this->activeVersion();
        $estimate = $this->estimate($version->product_count, $policyVersion->policy);

        return CatalogueInvoice::create([
            'tenant_id' => $catalogue->tenant_id,
            'catalogue_id' => $catalogue->id,
            'catalogue_version_id' => $version->id,
            'pricing_version_id' => $policyVersion->id,
            'number' => 'CAT-'.now()->format('Ymd').'-'.strtoupper(Str::random(6)),
            'product_count' => $version->product_count,
            'subtotal' => $estimate['subtotal'],
            'tax' => $estimate['tax'],
            'total' => $estimate['total'],
            'currency' => $estimate['currency'],
            'status' => 'issued',
            'issued_at' => now(),
        ]);
    }
}
