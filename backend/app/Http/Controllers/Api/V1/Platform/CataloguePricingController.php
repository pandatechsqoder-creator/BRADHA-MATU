<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\CataloguePricingPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\CataloguePricingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CataloguePricingController extends Controller
{
    public function show(CataloguePricingService $pricing): JsonResponse
    {
        $policy = $pricing->policy();
        $policy->load(['versions' => fn ($query) => $query->limit(10)]);

        return ApiResponse::success([
            'policy' => $this->policyPayload($policy),
            'estimates' => collect([100, 500, 1000, 3000, 6000, 10000])
                ->map(fn (int $count) => $pricing->estimate($count, $policy))
                ->values(),
        ]);
    }

    public function update(Request $request, CataloguePricingService $pricing, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'pricing_model' => ['sometimes', Rule::in(['flat', 'per_product', 'banded', 'minimum', 'capped', 'promotional', 'negotiated'])],
            'currency' => ['sometimes', 'string', 'size:3'],
            'product_count_basis' => ['sometimes', Rule::in(['active_variants', 'active_products', 'sellable_skus'])],
            'base_charge' => ['sometimes', 'numeric', 'min:0'],
            'included_products' => ['sometimes', 'integer', 'min:0'],
            'scale_products' => ['sometimes', 'integer', 'min:1'],
            'max_charge' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $policy = $pricing->policy();
        $before = $policy->only(array_keys($data));

        $policy = $pricing->updatePolicy($data, $request->user());

        $audit->log('catalogue.pricing.updated', $policy, null, $before, $policy->only(array_keys($data)), null);

        return ApiResponse::success($this->policyPayload($policy->load(['versions' => fn ($query) => $query->limit(10)])));
    }

    private function policyPayload(CataloguePricingPolicy $policy): array
    {
        return [
            'id' => $policy->uuid,
            'name' => $policy->name,
            'pricing_model' => $policy->pricing_model,
            'currency' => $policy->currency,
            'product_count_basis' => $policy->product_count_basis,
            'base_charge' => (float) $policy->base_charge,
            'included_products' => $policy->included_products,
            'scale_products' => $policy->scale_products,
            'max_charge' => (float) $policy->max_charge,
            'tax_rate' => (float) $policy->tax_rate,
            'notes' => $policy->notes,
            'effective_from' => $policy->effective_from?->toIso8601String(),
            'versions' => $policy->versions->map(fn ($version) => [
                'id' => $version->uuid,
                'version' => $version->version,
                'rules' => $version->rules,
                'effective_from' => $version->effective_from?->toIso8601String(),
            ])->values(),
        ];
    }
}
