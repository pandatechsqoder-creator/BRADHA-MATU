<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\UnitConversion;
use App\Models\UnitOfMeasure;
use App\Services\Audit\AuditLogger;
use App\Services\Catalogue\UnitService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UnitController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, UnitService $units): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.view');

        $conversions = UnitConversion::query()
            ->where('tenant_id', $tenant->id)
            ->with(['fromUnit', 'toUnit'])
            ->get()
            ->map(fn (UnitConversion $conversion) => [
                'id' => $conversion->uuid,
                'from' => $conversion->fromUnit?->code,
                'to' => $conversion->toUnit?->code,
                'factor' => (float) $conversion->factor,
            ])
            ->values();

        return ApiResponse::success([
            'units' => $units->directory($tenant->id),
            'conversions' => $conversions,
        ]);
    }

    public function store(Request $request, Tenant $tenant, UnitService $units, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:80'],
            'dimension' => ['nullable', Rule::in(['count', 'weight', 'volume', 'length', 'other'])],
            'factor_to_base' => ['nullable', 'numeric', 'gt:0'],
            'is_base' => ['nullable', 'boolean'],
        ]);

        $code = $units->normaliseCode($data['code']);
        if ($code === '') {
            return ApiResponse::error('VALIDATION_FAILED', 'The supplied data is invalid.', 422, ['code' => ['Enter a valid unit code.']]);
        }

        $exists = UnitOfMeasure::query()->where('tenant_id', $tenant->id)->where('code', $code)->exists();
        if ($exists) {
            return ApiResponse::error('VALIDATION_FAILED', 'The supplied data is invalid.', 422, ['code' => ['This unit already exists.']]);
        }

        $unit = UnitOfMeasure::create([
            'tenant_id' => $tenant->id,
            'code' => $code,
            'name' => $data['name'],
            'dimension' => $data['dimension'] ?? 'count',
            'factor_to_base' => $data['factor_to_base'] ?? 1,
            'is_base' => $data['is_base'] ?? false,
            'status' => 'active',
        ]);

        $audit->log('unit.created', $unit, null, null, ['code' => $unit->code, 'name' => $unit->name], $tenant->id);

        return ApiResponse::success([
            'id' => $unit->uuid,
            'code' => $unit->code,
            'name' => $unit->name,
            'dimension' => $unit->dimension,
            'factor_to_base' => (float) $unit->factor_to_base,
            'is_base' => (bool) $unit->is_base,
            'custom' => true,
        ], 201);
    }

    public function storeConversion(Request $request, Tenant $tenant, UnitService $units, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'catalogue.update');

        $data = $request->validate([
            'from' => ['required', 'string', 'max:20'],
            'to' => ['required', 'string', 'max:20'],
            'factor' => ['required', 'numeric', 'gt:0'],
        ]);

        $from = $units->ensure($tenant->id, $data['from']);
        $to = $units->ensure($tenant->id, $data['to']);

        if ($from->id === $to->id) {
            return ApiResponse::error('VALIDATION_FAILED', 'The supplied data is invalid.', 422, ['to' => ['Choose a different unit.']]);
        }

        $conversion = UnitConversion::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'from_unit_id' => $from->id,
                'to_unit_id' => $to->id,
            ],
            ['factor' => $data['factor']],
        );

        $audit->log('unit.conversion_saved', $conversion, null, null, [
            'from' => $from->code,
            'to' => $to->code,
            'factor' => (float) $conversion->factor,
        ], $tenant->id);

        return ApiResponse::success([
            'id' => $conversion->uuid,
            'from' => $from->code,
            'to' => $to->code,
            'factor' => (float) $conversion->factor,
        ], 201);
    }
}
