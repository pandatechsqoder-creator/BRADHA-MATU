<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\PayoutBatch;
use App\Models\StocktakeJob;
use App\Services\Finance\PayoutService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->payouts->pendingBatches());
    }

    public function prepare(string $job, Request $request, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $data = $request->validate(['method' => ['nullable', 'string', 'in:mpesa,bank,manual']]);

        return ApiResponse::success(
            $this->payouts->prepareBatch($model, $request->user(), $data['method'] ?? 'mpesa'),
            201,
        );
    }

    public function show(PayoutBatch $batch): JsonResponse
    {
        return ApiResponse::success(
            $this->payouts->batchPayload($batch->loadMissing('payouts')),
        );
    }

    public function approve(PayoutBatch $batch, Request $request): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return ApiResponse::success(
            $this->payouts->approveBatch($batch, $request->user(), $data['notes'] ?? null),
        );
    }

    public function process(PayoutBatch $batch, Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->payouts->processBatch($batch, $request->user()),
        );
    }

    public function closeJob(string $job, Request $request, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success(
            $this->payouts->closeJob($model, $request->user()),
        );
    }

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }
}
