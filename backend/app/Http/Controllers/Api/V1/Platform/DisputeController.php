<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Models\StocktakingDispute;
use App\Services\Review\TenantReviewService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DisputeController extends Controller
{
    public function __construct(private readonly TenantReviewService $reviews) {}

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }

    public function show(string $job, StocktakingDispute $dispute, Request $request, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $dispute->loadMissing(['messages', 'decisions', 'evidence']);

        return ApiResponse::success([
            'id' => $dispute->uuid,
            'status' => $dispute->status,
            'category' => $dispute->category,
            'priority' => $dispute->priority,
            'reason' => $dispute->reason,
            'job' => ['id' => $model->uuid, 'reference' => $model->reference, 'status' => $model->status],
            'messages' => $dispute->messages->map(fn ($m) => [
                'id' => $m->uuid, 'message' => $m->message, 'sender_role' => $m->sender_role,
                'sent_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'decisions' => $dispute->decisions->map(fn ($d) => [
                'id' => $d->uuid, 'decision' => $d->decision, 'reasoning' => $d->reasoning,
            ])->values()->all(),
            'evidence' => $dispute->evidence->map(fn ($e) => [
                'id' => $e->uuid, 'kind' => $e->kind, 'description' => $e->description,
            ])->values()->all(),
        ]);
    }

    public function addMessage(string $job, StocktakingDispute $dispute, Request $request, TenantContext $context): JsonResponse
    {
        $this->findJob($job, $context);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success(
            $this->reviews->addDisputeMessage($dispute, $data, $request->user(), 'platform'),
            201,
        );
    }

    public function addEvidence(string $job, StocktakingDispute $dispute, Request $request, TenantContext $context): JsonResponse
    {
        $this->findJob($job, $context);
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:photo,document,note,count_comparison'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->reviews->addDisputeEvidence($dispute, $data, $request->user()), 201);
    }

    public function decide(string $job, StocktakingDispute $dispute, Request $request, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);
        $data = $request->validate([
            'decision' => ['required', 'string', 'in:uphold,overturn,partial,negotiate'],
            'reasoning' => ['nullable', 'string', 'max:2000'],
            'conditions' => ['nullable', 'array'],
        ]);

        return ApiResponse::success($this->reviews->decideDispute($model, $dispute, $data, $request->user()));
    }
}
