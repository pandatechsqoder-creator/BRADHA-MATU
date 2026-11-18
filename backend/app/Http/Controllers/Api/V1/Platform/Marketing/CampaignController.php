<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Marketing\CampaignAsset;
use App\Models\Marketing\MarketingCampaign;
use App\Services\Marketing\AnalyticsService;
use App\Services\Marketing\CampaignService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignService $campaigns,
        private readonly AnalyticsService $analytics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', CampaignService::STATUSES)],
            'channel' => ['nullable', 'in:'.implode(',', CampaignService::CHANNELS)],
        ]);

        return ApiResponse::success($this->campaigns->list($filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request, true);

        return $this->run(fn () => $this->campaigns->show($this->campaigns->create($request->user(), $data)), 201);
    }

    public function show(MarketingCampaign $campaign): JsonResponse
    {
        return ApiResponse::success($this->campaigns->show($campaign));
    }

    public function update(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->update($campaign, $request->user(), $this->validatePayload($request, false))));
    }

    public function submitReview(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->submitReview($campaign, $request->user())));
    }

    public function approve(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        return $this->run(fn () => $this->campaigns->show($this->campaigns->approve($campaign, $request->user(), $data['notes'] ?? null)));
    }

    public function reject(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:2000']]);

        return $this->run(fn () => $this->campaigns->show($this->campaigns->reject($campaign, $request->user(), $data['notes'])));
    }

    public function activate(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->activate($campaign, $request->user())));
    }

    public function pause(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->pause($campaign, $request->user())));
    }

    public function resume(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->resume($campaign, $request->user())));
    }

    public function complete(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->complete($campaign, $request->user())));
    }

    public function cancel(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        return $this->run(fn () => $this->campaigns->show($this->campaigns->cancel($campaign, $request->user())));
    }

    public function storeAsset(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:copy,image,email,social_post'],
            'content' => ['nullable', 'array'],
            'media_asset_id' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->run(function () use ($request, $campaign, $data) {
            $this->campaigns->addAsset($campaign, $request->user(), $data);

            return $this->campaigns->show($campaign->fresh());
        }, 201);
    }

    public function destroyAsset(Request $request, MarketingCampaign $campaign, CampaignAsset $asset): JsonResponse
    {
        if ((int) $asset->campaign_id !== (int) $campaign->id) {
            return ApiResponse::error('NOT_FOUND', 'Creative asset not found for this campaign.', 404);
        }

        return $this->run(function () use ($request, $campaign, $asset) {
            $this->campaigns->removeAsset($campaign, $asset, $request->user());

            return $this->campaigns->show($campaign->fresh());
        });
    }

    public function recordResults(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'results' => ['required', 'array'],
            'results.reach' => ['nullable', 'integer', 'min:0'],
            'results.impressions' => ['nullable', 'integer', 'min:0'],
            'results.clicks' => ['nullable', 'integer', 'min:0'],
            'results.leads' => ['nullable', 'integer', 'min:0'],
            'results.conversions' => ['nullable', 'integer', 'min:0'],
            'results.spend' => ['nullable', 'numeric', 'min:0'],
            'results.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->run(fn () => $this->campaigns->show($this->campaigns->recordResults($campaign, $request->user(), $data['results'])));
    }

    public function performance(Request $request, MarketingCampaign $campaign): JsonResponse
    {
        $days = max(1, min(365, (int) $request->query('days', 30)));

        return ApiResponse::success($this->analytics->campaignPerformance($campaign, $days));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $creating): array
    {
        $presence = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$presence, 'string', 'max:160'],
            'objective' => [$presence, 'in:'.implode(',', CampaignService::OBJECTIVES)],
            'channel' => [$presence, 'in:'.implode(',', CampaignService::CHANNELS)],
            'audience' => ['nullable', 'array'],
            'audience.summary' => ['nullable', 'string', 'max:500'],
            'audience.segments' => ['nullable', 'array', 'max:20'],
            'audience.segments.*' => ['string', 'max:120'],
            'budget_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'budget_currency' => ['nullable', 'string', 'size:3'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'landing_page_id' => ['nullable', 'string', 'max:64'],
            'utm' => ['nullable', 'array'],
            'utm.source' => ['nullable', 'string', 'max:80'],
            'utm.medium' => ['nullable', 'string', 'max:80'],
            'utm.campaign' => ['nullable', 'string', 'max:80'],
            'utm.term' => ['nullable', 'string', 'max:80'],
            'utm.content' => ['nullable', 'string', 'max:80'],
        ]);
    }

    private function run(callable $callback, int $status = 200): JsonResponse
    {
        try {
            return ApiResponse::success($callback(), $status);
        } catch (MarketingIntegrationException $exception) {
            return ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status);
        }
    }
}
