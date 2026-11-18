<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\Marketing\Google\GoogleIntegrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleIntegrationController extends Controller
{
    public function __construct(private readonly GoogleIntegrationService $google) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success($this->google->status());
    }

    public function connect(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success([
                'authorization_url' => $this->google->connectUrl($request->user()),
            ]);
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->google->disconnect($request->user());

        return ApiResponse::success([
            'disconnected' => true,
            'status' => $this->google->status(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:search_console,ga4,business,gtm,all'],
        ]);

        try {
            $runs = $this->google->queueSync($data['type'], $request->user());
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success([
            'runs' => array_map(fn ($run) => [
                'id' => $run->uuid,
                'type' => $run->type,
                'status' => $run->status,
            ], $runs),
        ], 202);
    }
}
