<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Models\TenantResultReview;
use App\Models\TenantResultReviewItem;
use App\Services\Review\TenantReviewService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantReviewController extends Controller
{
    use ChecksTenantAccess;

    private const REVIEW_PERMISSIONS = ['stocktake.results.review', 'stocktake.results.accept', 'stocktake.dispute.create', 'stocktake.count.recount.request'];

    public function show(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, self::REVIEW_PERMISSIONS);
        $this->assertBelongsToTenant($tenant, $job);

        // Auto-publish results if the job is awaiting tenant review and no review exists yet
        if ($job->status === 'awaiting_tenant_review') {
            $review = TenantResultReview::where('job_id', $job->id)->latest('id')->first();
            if ($review === null) {
                return ApiResponse::success($reviews->publishResults($job));
            }
            return ApiResponse::success($reviews->reviewPayload($review, $job));
        }

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->first();
        abort_if($review === null, 404, 'No tenant review found for this job.');

        return ApiResponse::success($reviews->reviewPayload($review, $job));
    }

    public function accept(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.results.accept']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();

        return ApiResponse::success($reviews->acceptResults($job, $review, $request->all(), $request->user()));
    }

    public function requestCorrection(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.results.review']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();

        return ApiResponse::success($reviews->requestCorrection($job, $review, $request->all(), $request->user()));
    }

    public function requestRecount(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.count.recount.request']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();

        return ApiResponse::success($reviews->requestRecount($job, $review, $request->all(), $request->user()));
    }

    public function raiseDispute(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.dispute.create']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();

        return ApiResponse::success($reviews->raiseDispute($job, $review, $request->all(), $request->user()), 201);
    }

    public function flagItem(Request $request, Tenant $tenant, StocktakeJob $job, TenantResultReviewItem $item, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.results.review']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();
        abort_unless($item->tenant_result_review_id === $review->id, 404);

        return ApiResponse::success($reviews->flagItem($review, $item, $request->all(), $request->user()));
    }

    public function generateReconciliation(Request $request, Tenant $tenant, StocktakeJob $job, TenantReviewService $reviews): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.results.review', 'stocktake.results.accept']);
        $this->assertBelongsToTenant($tenant, $job);

        $review = TenantResultReview::where('job_id', $job->id)->latest('id')->firstOrFail();

        return ApiResponse::success($reviews->generateReconciliation($review, $job), 201);
    }
}
