<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Models\ProductVariant;
use App\Services\Catalogue\CataloguePricingService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CataloguePricingController extends Controller
{
    public function estimate(Request $request, CataloguePricingService $pricing, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'product_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $tenant = $tenantContext->tenant();
        $count = $data['product_count'] ?? null;

        if ($count === null) {
            $count = $tenant
                ? ProductVariant::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('status', 'active')
                    ->count()
                : 0;
        }

        $estimate = $pricing->estimate((int) $count);
        $policy = $pricing->policy();

        return ApiResponse::success([
            ...$estimate,
            'policy' => [
                'id' => $policy->uuid,
                'name' => $policy->name,
                'notes' => $policy->notes,
                'effective_from' => $policy->effective_from?->toIso8601String(),
                'version' => $pricing->activeVersion()->version,
            ],
        ]);
    }
}
