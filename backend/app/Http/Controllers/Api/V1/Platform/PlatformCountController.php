<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Services\Count\CountService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class PlatformCountController extends Controller
{
    public function show(string $job, CountService $counts, TenantContext $context): JsonResponse
    {
        $model = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $job)->firstOrFail();
        $context->set($model->tenant);

        return ApiResponse::success($counts->platformCounts($model));
    }
}
