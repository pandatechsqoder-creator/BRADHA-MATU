<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\Discrepancy;
use App\Models\QaReview;
use App\Models\RecountRequest;
use App\Models\StocktakeJob;
use App\Models\VerificationSample;
use App\Models\VerificationTask;
use App\Services\QA\QAService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QAController extends Controller
{
    public function __construct(private readonly QAService $qa) {}

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }

    public function startReview(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success($qa->startReview($model, $request->user()));
    }

    public function getReview(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $review = QaReview::where('job_id', $model->id)->where('status', 'in_progress')->latest('id')->first();
        abort_if($review === null, 404, 'No active QA review found.');

        return ApiResponse::success($qa->reviewPayload($review, $model));
    }

    public function recordFinding(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $review = QaReview::where('job_id', $model->id)->where('status', 'in_progress')->latest('id')->firstOrFail();

        return ApiResponse::success($qa->recordFinding($model, $review, $request->all(), $request->user()), 201);
    }

    public function completeReview(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $review = QaReview::where('job_id', $model->id)->where('status', 'in_progress')->latest('id')->firstOrFail();

        return ApiResponse::success($qa->completeReview($model, $review, $request->all(), $request->user()));
    }

    public function createVerificationTask(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $review = QaReview::where('job_id', $model->id)->latest('id')->firstOrFail();

        return ApiResponse::success($qa->createVerificationTask($model, $review, $request->all(), $request->user()), 201);
    }

    public function recordVerificationSample(string $job, VerificationTask $task, VerificationSample $sample, Request $request, QAService $qa): JsonResponse
    {
        $data = $request->validate([
            'verified_quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success($qa->recordVerificationSample($task, $sample, $data, $request->user()));
    }

    public function completeVerificationTask(string $job, VerificationTask $task, Request $request, QAService $qa): JsonResponse
    {
        return ApiResponse::success($qa->completeVerificationTask($task, $request->user()));
    }

    public function requestRecount(string $job, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success($qa->requestRecount($model, $request->all(), $request->user()), 201);
    }

    public function approveRecount(string $job, RecountRequest $recount, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success($qa->approveRecount($model, $recount, $request->user()));
    }

    public function completeRecount(string $job, RecountRequest $recount, Request $request, QAService $qa, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success($qa->completeRecount($model, $recount, $request->user()));
    }

    public function resolveDiscrepancy(Discrepancy $discrepancy, Request $request, QAService $qa): JsonResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:worker_error,system_error,expected_variance,unknown'],
            'resolution' => ['required', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($qa->resolveDiscrepancy($discrepancy, $data, $request->user()));
    }
}
