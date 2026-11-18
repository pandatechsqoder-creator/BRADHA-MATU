<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Services\Count\CountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantCountController extends Controller
{
    use ChecksTenantAccess;

    private const VIEW_PERMISSIONS = [
        'stocktake.count.view', 'stocktake.count.recount.request',
        'stocktake.results.review', 'report.view',
    ];

    public function show(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        CountService $counts,
    ): JsonResponse {
        $this->assertAnyCan($request, $tenant, self::VIEW_PERMISSIONS);
        $this->assertBelongsToTenant($tenant, $job);

        return ApiResponse::success($counts->tenantCounts($job));
    }
}
