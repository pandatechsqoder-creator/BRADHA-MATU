<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\StocktakePolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Stocktake\StocktakePolicyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StocktakePolicyController extends Controller
{
    public function show(Request $request, StocktakePolicyService $policies): JsonResponse
    {
        return ApiResponse::success($this->payload($policies->policy()));
    }

    public function update(Request $request, StocktakePolicyService $policies, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'currency' => ['sometimes', 'string', 'size:3'],
            'base_charge' => ['sometimes', 'numeric', 'min:0'],
            'per_sku_charge' => ['sometimes', 'numeric', 'min:0'],
            'per_location_charge' => ['sometimes', 'numeric', 'min:0'],
            'per_branch_charge' => ['sometimes', 'numeric', 'min:0'],
            'priority_multipliers' => ['sometimes', 'array'],
            'priority_multipliers.low' => ['sometimes', 'numeric', 'min:0'],
            'priority_multipliers.normal' => ['sometimes', 'numeric', 'min:0'],
            'priority_multipliers.high' => ['sometimes', 'numeric', 'min:0'],
            'priority_multipliers.critical' => ['sometimes', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'deposit_type' => ['sometimes', Rule::in(['none', 'fixed', 'percentage'])],
            'deposit_value' => ['sometimes', 'numeric', 'min:0'],
            'deposit_minimum' => ['sometimes', 'numeric', 'min:0'],
            'deposit_maximum' => ['sometimes', 'numeric', 'min:0'],
            'deposit_due_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'balance_due_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'cancellation_fee_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'deposit_policy_overrides' => ['sometimes', 'array'],
        ]);

        $before = $this->payload($policies->policy());
        $policy = $policies->update($data, $request->user());
        $audit->log('stocktake.policy.updated', $policy, null, $before, $this->payload($policy));

        return ApiResponse::success($this->payload($policy));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StocktakePolicy $policy): array
    {
        return [
            'id' => $policy->uuid,
            'currency' => $policy->currency,
            'base_charge' => (float) $policy->base_charge,
            'per_sku_charge' => (float) $policy->per_sku_charge,
            'per_location_charge' => (float) $policy->per_location_charge,
            'per_branch_charge' => (float) $policy->per_branch_charge,
            'priority_multipliers' => $policy->priority_multipliers,
            'tax_rate' => (float) $policy->tax_rate,
            'deposit_type' => $policy->deposit_type,
            'deposit_value' => (float) $policy->deposit_value,
            'deposit_minimum' => (float) $policy->deposit_minimum,
            'deposit_maximum' => (float) $policy->deposit_maximum,
            'deposit_due_days' => (int) $policy->deposit_due_days,
            'balance_due_days' => (int) $policy->balance_due_days,
            'cancellation_fee_percent' => (float) $policy->cancellation_fee_percent,
            'deposit_policy_overrides' => $policy->deposit_policy_overrides ?? [],
            'updated_at' => $policy->updated_at?->toIso8601String(),
        ];
    }
}
