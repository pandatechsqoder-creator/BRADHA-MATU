<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\StockLocation;
use App\Models\StockZone;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockZoneController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, StockLocation $stockLocation): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');
        $this->assertBelongsToTenant($tenant, $stockLocation);

        return ApiResponse::success(
            $stockLocation->zones()->withCount('bins')->orderBy('name')->get()->map(fn (StockZone $zone) => [
                'id' => $zone->uuid,
                'name' => $zone->name,
                'code' => $zone->code,
                'bins_count' => $zone->bins_count ?? 0,
            ])->values()
        );
    }

    public function store(Request $request, Tenant $tenant, StockLocation $stockLocation, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $stockLocation);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
        ]);

        $zone = StockZone::create([
            'tenant_id' => $tenant->id,
            'stock_location_id' => $stockLocation->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
        ]);

        $audit->log('stock_zone.created', $zone, null, null, $zone->only(['name', 'code']), $tenant->id);

        return ApiResponse::success(['id' => $zone->uuid, 'name' => $zone->name, 'code' => $zone->code, 'bins_count' => 0], 201);
    }
}
