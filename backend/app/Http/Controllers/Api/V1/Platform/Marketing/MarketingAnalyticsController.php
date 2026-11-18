<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketingAnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->overview($this->days($request)));
    }

    public function funnel(Request $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->funnel($this->days($request)));
    }

    public function campaigns(Request $request): JsonResponse
    {
        return ApiResponse::success(['campaigns' => $this->analytics->campaigns($this->days($request))]);
    }

    public function landingPages(Request $request): JsonResponse
    {
        return ApiResponse::success(['pages' => $this->analytics->landingPages($this->days($request))]);
    }

    public function search(Request $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->search($this->days($request)));
    }

    public function events(Request $request): JsonResponse
    {
        return ApiResponse::success($this->analytics->recentEvents($this->days($request)));
    }

    private function days(Request $request): int
    {
        return max(1, min(365, (int) $request->query('days', 30)));
    }
}
