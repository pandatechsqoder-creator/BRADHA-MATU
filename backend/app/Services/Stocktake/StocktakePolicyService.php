<?php

namespace App\Services\Stocktake;

use App\Models\StocktakePolicy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StocktakePolicyService
{
    private const DEFAULTS = [
        'currency' => 'KES',
        'base_charge' => 2500,
        'per_sku_charge' => 5,
        'per_location_charge' => 500,
        'per_branch_charge' => 1000,
        'priority_multipliers' => ['low' => 0.9, 'normal' => 1.0, 'high' => 1.25, 'critical' => 1.5],
        'tax_rate' => 0,
        'deposit_type' => 'percentage',
        'deposit_value' => 30,
        'deposit_minimum' => 1000,
        'deposit_maximum' => 100000,
        'deposit_due_days' => 3,
        'balance_due_days' => 7,
        'cancellation_fee_percent' => 10,
        'deposit_policy_overrides' => [],
    ];

    public function policy(): StocktakePolicy
    {
        $policy = StocktakePolicy::query()->latest('id')->first();
        if ($policy !== null) {
            return $policy;
        }

        return StocktakePolicy::create(self::DEFAULTS);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(array $data, ?User $actor = null): StocktakePolicy
    {
        return DB::transaction(function () use ($data, $actor): StocktakePolicy {
            $policy = $this->policy();
            $policy->fill($data);
            if ($actor) {
                $policy->updated_by = $actor->id;
            }
            $policy->save();

            return $policy->fresh();
        });
    }

    /**
     * Tenant setting overrides the platform policy; job-type overrides apply next.
     *
     * @return array<string, mixed>
     */
    public function depositRules(Tenant $tenant, string $jobType = 'full'): array
    {
        $policy = $this->policy();
        $rules = [
            'type' => $policy->deposit_type,
            'value' => (float) $policy->deposit_value,
            'minimum' => (float) $policy->deposit_minimum,
            'maximum' => (float) $policy->deposit_maximum,
            'due_days' => (int) $policy->deposit_due_days,
            'currency' => $policy->currency,
            'source' => 'platform',
        ];

        $overrides = (array) ($policy->deposit_policy_overrides ?? []);
        if (isset($overrides[$jobType]) && is_array($overrides[$jobType])) {
            $rules = [...$rules, ...array_intersect_key($overrides[$jobType], $rules), 'source' => 'job_type'];
            $rules['source'] = 'job_type';
        }

        $tenantPolicy = $tenant->setting('stocktake.deposit_policy');
        if (is_array($tenantPolicy)) {
            $rules = [
                ...$rules,
                'type' => $tenantPolicy['type'] ?? $rules['type'],
                'value' => isset($tenantPolicy['value']) ? (float) $tenantPolicy['value'] : $rules['value'],
                'minimum' => isset($tenantPolicy['minimum']) ? (float) $tenantPolicy['minimum'] : $rules['minimum'],
                'maximum' => isset($tenantPolicy['maximum']) ? (float) $tenantPolicy['maximum'] : $rules['maximum'],
                'currency' => $tenantPolicy['currency'] ?? $rules['currency'],
                'source' => 'tenant',
            ];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public function depositFor(float $amount, array $rules): array
    {
        $type = $rules['type'] ?? 'percentage';
        $value = (float) ($rules['value'] ?? 0);
        $minimum = (float) ($rules['minimum'] ?? 0);
        $maximum = (float) ($rules['maximum'] ?? 0);

        $deposit = match ($type) {
            'none', 'waived' => 0.0,
            'fixed' => $value,
            default => $amount * ($value / 100),
        };

        if ($type !== 'none' && $type !== 'waived') {
            $deposit = max($deposit, $minimum);
            if ($maximum > 0) {
                $deposit = min($deposit, $maximum);
            }
        }

        $deposit = round($deposit, 2);

        $summary = match ($type) {
            'none', 'waived' => 'No deposit is required for this job.',
            'fixed' => 'A fixed deposit of '.$this->money($deposit, $rules['currency'] ?? 'KES').' is required.',
            default => $value.'% of the job value, minimum '.$this->money($minimum, $rules['currency'] ?? 'KES')
                .', capped at '.$this->money($maximum, $rules['currency'] ?? 'KES').'.',
        };

        return [
            'required' => $deposit > 0,
            'type' => $type,
            'value' => $value,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'amount' => $deposit,
            'currency' => $rules['currency'] ?? 'KES',
            'due_days' => (int) ($rules['due_days'] ?? 3),
            'source' => $rules['source'] ?? 'platform',
            'summary' => $summary,
        ];
    }

    private function money(float $amount, string $currency): string
    {
        return $currency.' '.number_format($amount, 2);
    }
}
