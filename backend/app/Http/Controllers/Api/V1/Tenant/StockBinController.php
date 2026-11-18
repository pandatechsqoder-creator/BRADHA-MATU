<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\StockBin;
use App\Models\StockZone;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StockBinController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, StockZone $stockZone): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.view');
        $this->assertBelongsToTenant($tenant, $stockZone);

        return ApiResponse::success(
            $stockZone->bins()->orderBy('name')->get()->map(fn (StockBin $bin) => [
                'id' => $bin->uuid,
                'name' => $bin->name,
                'code' => $bin->code,
            ])->values()
        );
    }

    public function store(Request $request, Tenant $tenant, StockZone $stockZone, AuditLogger $audit): JsonResponse
    {
        $this->assertCan($request, $tenant, 'branch.update');
        $this->assertBelongsToTenant($tenant, $stockZone);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
        ]);

        $bin = StockBin::create([
            'tenant_id' => $tenant->id,
            'stock_zone_id' => $stockZone->id,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
        ]);

        $audit->log('stock_bin.created', $bin, null, null, $bin->only(['name', 'code']), $tenant->id);

        return ApiResponse::success(['id' => $bin->uuid, 'name' => $bin->name, 'code' => $bin->code], 201);
    }
}
