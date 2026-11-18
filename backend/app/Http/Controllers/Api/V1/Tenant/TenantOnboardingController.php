<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\PosTerminal;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantOnboardingController extends Controller
{
    use ChecksTenantAccess;

    private const STEPS = [
        ['id' => 'business-profile', 'title' => 'Business profile', 'required' => true, 'href' => '/settings'],
        ['id' => 'locations', 'title' => 'Business locations', 'required' => true, 'href' => '/business/locations'],
        ['id' => 'branches', 'title' => 'Branches', 'required' => false, 'href' => '/business/locations'],
        ['id' => 'departments', 'title' => 'Departments', 'required' => false, 'href' => '/business/locations'],
        ['id' => 'warehouses', 'title' => 'Warehouses and storage', 'required' => false, 'href' => '/business/locations'],
        ['id' => 'users', 'title' => 'Users and staff', 'required' => false, 'href' => '/business/team'],
        ['id' => 'roles', 'title' => 'Roles and permissions', 'required' => false, 'href' => '/business/team'],
        ['id' => 'catalogue', 'title' => 'Product catalogue', 'required' => true, 'href' => '/business/catalogue'],
        ['id' => 'inventory', 'title' => 'Inventory configuration', 'required' => false, 'href' => '/business/inventory'],
        ['id' => 'operations', 'title' => 'Business operations', 'required' => false, 'href' => '/pos'],
        ['id' => 'stocktake-readiness', 'title' => 'First stock-taking request', 'required' => true, 'href' => '/business/stocktaking/new'],
        ['id' => 'final-review', 'title' => 'Final review', 'required' => false, 'href' => '/business/onboarding'],
    ];

    public function show(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.view');

        return ApiResponse::success($this->progress($tenant));
    }

    public function update(
        Request $request,
        Tenant $tenant,
        AuditLogger $audit,
        string $step,
    ): JsonResponse {
        $this->assertCan($request, $tenant, 'tenant.update');
        abort_unless(collect(self::STEPS)->contains(fn (array $item): bool => $item['id'] === $step), 404);

        $data = $request->validate([
            'action' => ['required', 'in:skip,resume,continue,finish'],
        ]);
        if ($data['action'] === 'finish') {
            abort_unless($step === 'final-review', 422, 'Only the final review can finish onboarding.');
            $requiredIncomplete = collect($this->progress($tenant)['steps'])
                ->first(fn (array $item): bool => $item['required'] && $item['status'] !== 'completed');
            abort_if(
                $requiredIncomplete !== null,
                422,
                'Complete the required '.$requiredIncomplete['title'].' step before finishing setup.',
            );
        }

        $setting = TenantSetting::firstOrNew([
            'tenant_id' => $tenant->id,
            'key' => 'onboarding.progress',
        ]);
        $state = is_array($setting->value) ? $setting->value : [];
        $skipped = array_values(array_filter($state['skipped'] ?? [], 'is_string'));

        if ($data['action'] === 'skip') {
            $stepDefinition = collect(self::STEPS)->firstWhere('id', $step);
            abort_if($stepDefinition['required'], 422, 'This setup step is required and cannot be skipped.');
            $skipped = array_values(array_unique([...$skipped, $step]));
        } elseif ($data['action'] === 'resume') {
            $skipped = array_values(array_diff($skipped, [$step]));
        } elseif ($data['action'] === 'finish') {
            $state['finished_at'] = now()->toIso8601String();
        }

        $state['skipped'] = $skipped;
        $state['current_step'] = $step;
        $setting->value = $state;
        $setting->save();

        $audit->log('tenant.onboarding.updated', $setting, null, null, [
            'step' => $step,
            'action' => $data['action'],
        ], $tenant->id);

        return ApiResponse::success($this->progress($tenant));
    }

    private function progress(Tenant $tenant): array
    {
        $setting = TenantSetting::query()
            ->where('tenant_id', $tenant->id)
            ->where('key', 'onboarding.progress')
            ->first();
        $state = $setting?->value;
        $state = is_array($state) ? $state : [];
        $skipped = array_values(array_filter($state['skipped'] ?? [], 'is_string'));

        $facts = [
            'business-profile' => filled($tenant->name) && filled($tenant->timezone),
            'locations' => StockLocation::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'branches' => Branch::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'departments' => Department::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'warehouses' => Warehouse::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'users' => $tenant->memberships()->where('status', 'active')->count() > 1,
            'roles' => DB::table('user_roles')->where('tenant_id', $tenant->id)->exists(),
            'catalogue' => Product::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'inventory' => UnitOfMeasure::query()->where('tenant_id', $tenant->id)->exists()
                && StockLocation::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'operations' => PosTerminal::query()->where('tenant_id', $tenant->id)->where('status', 'active')->exists(),
            'stocktake-readiness' => StocktakeJob::query()
                ->where('tenant_id', $tenant->id)
                ->whereNotNull('submitted_at')
                ->exists(),
            'final-review' => filled($state['finished_at'] ?? null),
        ];

        $steps = collect(self::STEPS)->map(function (array $step) use ($facts, $skipped): array {
            $step['status'] = $facts[$step['id']]
                ? 'completed'
                : (in_array($step['id'], $skipped, true) ? 'skipped' : 'pending');

            return $step;
        })->values();

        $finishedCount = $steps->whereIn('status', ['completed', 'skipped'])->count();
        $nextStep = $steps->first(fn (array $step): bool => in_array($step['status'], ['pending'], true));

        return [
            'steps' => $steps,
            'completion_percent' => (int) round(($finishedCount / count(self::STEPS)) * 100),
            'current_step' => $state['current_step'] ?? $nextStep['id'] ?? 'final-review',
            'recommended_step' => $nextStep,
            'finished_at' => $state['finished_at'] ?? null,
        ];
    }
}
