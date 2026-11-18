<?php

namespace App\Http\Controllers\Api\V1\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebhookController extends Controller
{
    public function __invoke(Request $request, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        return ApiResponse::success($integrations->receiveWebhook($connection, $request));
    }
}
