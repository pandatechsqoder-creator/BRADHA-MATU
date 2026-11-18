<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Services\Operations\JobOperationsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JobOperationsController extends Controller
{
    use ChecksTenantAccess;

    private const VIEW_PERMISSIONS = [
        'stocktake.request.create', 'stocktake.quote.view', 'stocktake.results.review', 'finance.invoice.view', 'report.view',
    ];

    public function show(
        Request $request,
        Tenant $tenant,
        StocktakeJob $job,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertAnyCan($request, $tenant, self::VIEW_PERMISSIONS);
        $this->assertBelongsToTenant($tenant, $job);

        return ApiResponse::success($operations->tenantPayload($job));
    }
}
