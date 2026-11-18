<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        $result = $health->run();

        return ApiResponse::success([
            'status' => $result['status'],
            'service' => 'brathamatu-stocktake-api',
            'version' => 'v1',
            'time' => now()->toIso8601String(),
            'checks' => $result['checks'],
            'diagnostics' => $result['diagnostics'],
        ], $result['status'] === 'ok' ? 200 : 503);
    }
}
