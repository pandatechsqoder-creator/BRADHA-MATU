<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\Marketing\Meta\MetaIntegrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetaIntegrationController extends Controller
{
    public function __construct(private readonly MetaIntegrationService $meta) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success($this->meta->status());
    }

    public function connect(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success([
                'authorization_url' => $this->meta->connectUrl($request->user()),
            ]);
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->meta->disconnect($request->user());

        return ApiResponse::success([
            'disconnected' => true,
            'status' => $this->meta->status(),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:assets,insights,ads,all'],
        ]);

        try {
            $runs = $this->meta->queueSync($data['type'], $request->user());
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
