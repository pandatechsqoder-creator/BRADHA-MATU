<?php

namespace App\Services\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Models\Marketing\CampaignApproval;
use App\Models\Marketing\CampaignAsset;
use App\Models\Marketing\CampaignRevision;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MarketingCampaign;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CampaignService
{
    public const CHANNELS = ['website', 'email', 'sms', 'whatsapp', 'facebook', 'instagram', 'meta_ads', 'google_ads', 'referral'];

    public const OBJECTIVES = ['lead_generation', 'pos_demos', 'stocktake_requests', 'brand_awareness', 'retention', 'other'];

    public const STATUSES = ['draft', 'review', 'approved', 'rejected', 'active', 'paused', 'completed', 'cancelled'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{campaigns: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(array $filters = []): array
    {
        $query = MarketingCampaign::query()
            ->with(['owner', 'approver', 'landingPage', 'creator'])
            ->latest('id');

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['channel'] ?? null)) {
            $query->where('channel', $filters['channel']);
        }

        $counts = MarketingCampaign::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        return [
            'campaigns' => $query->limit(100)->get()->map(fn (MarketingCampaign $campaign) => $this->present($campaign))->all(),
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(MarketingCampaign $campaign): array
    {
        $campaign->load(['owner', 'approver', 'landingPage', 'creator', 'assets.mediaAsset', 'approvals.requestedBy', 'approvals.decidedBy', 'revisions.creator']);

        return $this->present($campaign, detailed: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): MarketingCampaign
    {
        $campaign = DB::transaction(function () use ($actor, $data) {
            $campaign = MarketingCampaign::create([
                'name' => trim((string) $data['name']),
                'objective' => $data['objective'],
                'channel' => $data['channel'],
                'status' => 'draft',
                'audience' => $data['audience'] ?? null,
                'budget_amount' => $data['budget_amount'] ?? null,
                'budget_currency' => strtoupper((string) ($data['budget_currency'] ?? 'GBP')),
                'starts_at' => $data['starts_at'] ?? null,
                'ends_at' => $data['ends_at'] ?? null,
                'landing_page_id' => $this->landingPageId($data['landing_page_id'] ?? null),
                'utm' => $this->normaliseUtm($data['utm'] ?? null),
                'owner_id' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $this->revise($campaign, $actor, 'Campaign created');

            $this->audit->log('marketing.campaign.created', $campaign, null, null, [
                'name' => $campaign->name,
                'channel' => $campaign->channel,
            ], null, $actor->id);

            return $campaign;
        });

        return $campaign;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MarketingCampaign $campaign, User $actor, array $data): MarketingCampaign
    {
        if (! in_array($campaign->status, ['draft', 'rejected'], true)) {
            throw new MarketingIntegrationException(
                'CAMPAIGN_LOCKED',
                'Only draft or rejected campaigns can be edited. Budget or audience changes after approval require a new review.',
                422,
            );
        }

        $before = $campaign->only(['name', 'objective', 'channel', 'budget_amount', 'starts_at', 'ends_at']);

        DB::transaction(function () use ($campaign, $actor, $data, $before) {
            $campaign->forceFill([
                'name' => array_key_exists('name', $data) ? trim((string) $data['name']) : $campaign->name,
                'objective' => $data['objective'] ?? $campaign->objective,
                'channel' => $data['channel'] ?? $campaign->channel,
                'audience' => array_key_exists('audience', $data) ? $data['audience'] : $campaign->audience,
                'budget_amount' => array_key_exists('budget_amount', $data) ? $data['budget_amount'] : $campaign->budget_amount,
                'budget_currency' => isset($data['budget_currency']) ? strtoupper((string) $data['budget_currency']) : $campaign->budget_currency,
                'starts_at' => array_key_exists('starts_at', $data) ? $data['starts_at'] : $campaign->starts_at,
                'ends_at' => array_key_exists('ends_at', $data) ? $data['ends_at'] : $campaign->ends_at,
                'landing_page_id' => array_key_exists('landing_page_id', $data) ? $this->landingPageId($data['landing_page_id']) : $campaign->landing_page_id,
                'utm' => array_key_exists('utm', $data) ? $this->normaliseUtm($data['utm']) : $campaign->utm,
                'status' => 'draft',
                'approver_id' => null,
                'approved_at' => null,
            ])->save();

            $this->revise($campaign, $actor, 'Campaign edited');
            $this->audit->log('marketing.campaign.updated', $campaign, null, $before, [
                'name' => $campaign->name,
                'budget_amount' => $campaign->budget_amount,
            ], null, $actor->id);
        });

        return $campaign;
    }

    public function submitReview(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if (! in_array($campaign->status, ['draft', 'rejected'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_LOCKED', 'Only draft or rejected campaigns can be submitted for review.', 422);
        }

        if ($campaign->budget_amount === null || (float) $campaign->budget_amount <= 0) {
            throw new MarketingIntegrationException('CAMPAIGN_BUDGET_REQUIRED', 'Set a budget above zero before submitting a campaign for approval.', 422);
        }

        if ($campaign->starts_at === null) {
            throw new MarketingIntegrationException('CAMPAIGN_DATES_REQUIRED', 'Set a start date before submitting a campaign for approval.', 422);
        }

        DB::transaction(function () use ($campaign, $actor) {
            $campaign->forceFill(['status' => 'review'])->save();

            CampaignApproval::create([
                'campaign_id' => $campaign->id,
                'requested_by' => $actor->id,
                'decision' => 'pending',
                'created_at' => now(),
            ]);

            $this->revise($campaign, $actor, 'Submitted for approval');
            $this->audit->log('marketing.campaign.submitted', $campaign, null, ['status' => 'draft'], ['status' => 'review'], null, $actor->id);
        });

        return $campaign;
    }

    public function approve(MarketingCampaign $campaign, User $actor, ?string $notes = null): MarketingCampaign
    {
        $this->assertDecidable($campaign, $actor);

        DB::transaction(function () use ($campaign, $actor, $notes) {
            $campaign->forceFill([
                'status' => 'approved',
                'approver_id' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $this->decide($campaign, $actor, 'approved', $notes);

            $this->revise($campaign, $actor, 'Campaign approved');
            $this->audit->log('marketing.campaign.approved', $campaign, $notes, ['status' => 'review'], ['status' => 'approved'], null, $actor->id);
        });

        return $campaign;
    }

    public function reject(MarketingCampaign $campaign, User $actor, ?string $notes = null): MarketingCampaign
    {
        $this->assertDecidable($campaign, $actor);

        DB::transaction(function () use ($campaign, $actor, $notes) {
            $campaign->forceFill(['status' => 'rejected'])->save();

            $this->decide($campaign, $actor, 'rejected', $notes);

            $this->revise($campaign, $actor, 'Campaign rejected');
            $this->audit->log('marketing.campaign.rejected', $campaign, $notes, ['status' => 'review'], ['status' => 'rejected'], null, $actor->id);
        });

        return $campaign;
    }

    public function activate(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if ($campaign->status !== 'approved' || $campaign->approved_at === null) {
            throw new MarketingIntegrationException('CAMPAIGN_APPROVAL_REQUIRED', 'Campaigns can only be activated after approval.', 422);
        }

        $before = $campaign->only(['status', 'activated_at']);
        $campaign->forceFill([
            'status' => 'active',
            'activated_at' => $campaign->activated_at ?? now(),
        ])->save();

        $this->revise($campaign, $actor, 'Campaign activated');
        $this->audit->log('marketing.campaign.activated', $campaign, null, $before, ['status' => 'active'], null, $actor->id);

        return $campaign;
    }

    public function pause(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if ($campaign->status !== 'active') {
            throw new MarketingIntegrationException('CAMPAIGN_STATUS_INVALID', 'Only active campaigns can be paused.', 422);
        }

        return $this->transition($campaign, $actor, 'paused', 'marketing.campaign.paused', 'Campaign paused');
    }

    public function resume(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if ($campaign->status !== 'paused') {
            throw new MarketingIntegrationException('CAMPAIGN_STATUS_INVALID', 'Only paused campaigns can be resumed.', 422);
        }

        return $this->transition($campaign, $actor, 'active', 'marketing.campaign.resumed', 'Campaign resumed');
    }

    public function complete(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if (! in_array($campaign->status, ['active', 'paused'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_STATUS_INVALID', 'Only active or paused campaigns can be completed.', 422);
        }

        return $this->transition($campaign, $actor, 'completed', 'marketing.campaign.completed', 'Campaign completed');
    }

    public function cancel(MarketingCampaign $campaign, User $actor): MarketingCampaign
    {
        if (in_array($campaign->status, ['completed', 'cancelled'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_STATUS_INVALID', 'Completed or cancelled campaigns cannot be cancelled again.', 422);
        }

        return $this->transition($campaign, $actor, 'cancelled', 'marketing.campaign.cancelled', 'Campaign cancelled');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addAsset(MarketingCampaign $campaign, User $actor, array $data): CampaignAsset
    {
        if (! in_array($campaign->status, ['draft', 'rejected', 'review'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_LOCKED', 'Creative can only change before a campaign is approved.', 422);
        }

        $asset = CampaignAsset::create([
            'campaign_id' => $campaign->id,
            'media_asset_id' => $this->mediaAssetId($data['media_asset_id'] ?? null),
            'type' => $data['type'],
            'content' => $data['content'] ?? null,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        $this->revise($campaign, $actor, 'Campaign creative added');
        $this->audit->log('marketing.campaign.asset_added', $campaign, null, null, [
            'asset_id' => $asset->id,
            'type' => $asset->type,
        ], null, $actor->id);

        return $asset;
    }

    public function removeAsset(MarketingCampaign $campaign, CampaignAsset $asset, User $actor): void
    {
        if (! in_array($campaign->status, ['draft', 'rejected', 'review'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_LOCKED', 'Creative can only change before a campaign is approved.', 422);
        }

        $this->audit->log('marketing.campaign.asset_removed', $campaign, null, ['type' => $asset->type], null, null, $actor->id);
        $asset->delete();
    }

    /**
     * @param  array<string, mixed>  $results
     */
    public function recordResults(MarketingCampaign $campaign, User $actor, array $results): MarketingCampaign
    {
        if (! in_array($campaign->status, ['active', 'paused', 'completed'], true)) {
            throw new MarketingIntegrationException('CAMPAIGN_STATUS_INVALID', 'Results can only be recorded for active, paused or completed campaigns.', 422);
        }

        $before = $campaign->results;
        $campaign->forceFill(['results' => array_merge($campaign->results ?? [], $results)])->save();

        $this->audit->log('marketing.campaign.results_recorded', $campaign, null, $before, $campaign->results, null, $actor->id);

        return $campaign;
    }

    private function transition(MarketingCampaign $campaign, User $actor, string $status, string $auditAction, string $summary): MarketingCampaign
    {
        $before = $campaign->status;
        $campaign->forceFill(['status' => $status])->save();

        $this->revise($campaign, $actor, $summary);
        $this->audit->log($auditAction, $campaign, null, ['status' => $before], ['status' => $status], null, $actor->id);

        return $campaign;
    }

    private function decide(MarketingCampaign $campaign, User $actor, string $decision, ?string $notes): void
    {
        $pending = CampaignApproval::query()
            ->where('campaign_id', $campaign->id)
            ->where('decision', 'pending')
            ->latest('id')
            ->first();

        if ($pending !== null) {
            $pending->forceFill([
                'decided_by' => $actor->id,
                'decision' => $decision,
                'notes' => $notes,
                'decided_at' => now(),
            ])->save();

            return;
        }

        CampaignApproval::create([
            'campaign_id' => $campaign->id,
            'requested_by' => $campaign->owner_id,
            'decided_by' => $actor->id,
            'decision' => $decision,
            'notes' => $notes,
            'decided_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function assertDecidable(MarketingCampaign $campaign, User $actor): void
    {        if ($campaign->status !== 'review') {
            throw new MarketingIntegrationException('CAMPAIGN_NOT_IN_REVIEW', 'Only campaigns in review can be approved or rejected.', 422);
        }

        if ($campaign->owner_id === $actor->id || $campaign->created_by === $actor->id) {
            throw new MarketingIntegrationException(
                'CAMPAIGN_APPROVAL_CONFLICT',
                'A campaign cannot be approved or rejected by its owner or creator.',
                422,
            );
        }
    }

    private function landingPageId(?string $uuid): ?int
    {
        if (blank($uuid)) {
            return null;
        }

        $page = CmsPage::query()->where('uuid', $uuid)->first();
        if ($page === null) {
            throw new MarketingIntegrationException('CAMPAIGN_LANDING_PAGE_NOT_FOUND', 'The selected landing page no longer exists.', 422);
        }

        return $page->id;
    }

    private function mediaAssetId(?string $uuid): ?int
    {
        if (blank($uuid)) {
            return null;
        }

        $asset = \App\Models\Marketing\MediaAsset::query()->where('uuid', $uuid)->first();
        if ($asset === null) {
            throw new MarketingIntegrationException('CAMPAIGN_MEDIA_NOT_FOUND', 'The selected media asset no longer exists.', 422);
        }

        return $asset->id;
    }

    /**
     * @param  array<string, mixed>|null  $utm
     * @return array<string, string>|null
     */
    private function normaliseUtm(?array $utm): ?array
    {
        if ($utm === null) {
            return null;
        }

        $clean = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $value = $utm[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $clean[$key] = mb_substr(trim($value), 0, 80);
            }
        }

        return $clean === [] ? null : $clean;
    }

    private function revise(MarketingCampaign $campaign, User $actor, string $summary): void
    {
        $next = (int) (CampaignRevision::query()->where('campaign_id', $campaign->id)->max('revision_no') ?? 0) + 1;

        CampaignRevision::create([
            'campaign_id' => $campaign->id,
            'revision_no' => $next,
            'snapshot' => [
                'name' => $campaign->name,
                'status' => $campaign->status,
                'objective' => $campaign->objective,
                'channel' => $campaign->channel,
                'budget_amount' => $campaign->budget_amount,
                'budget_currency' => $campaign->budget_currency,
                'starts_at' => $campaign->starts_at?->toIso8601String(),
                'ends_at' => $campaign->ends_at?->toIso8601String(),
                'utm' => $campaign->utm,
                'audience' => $campaign->audience,
            ],
            'change_summary' => $summary,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MarketingCampaign $campaign, bool $detailed = false): array
    {
        $data = [
            'id' => $campaign->uuid,
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'channel' => $campaign->channel,
            'status' => $campaign->status,
            'audience' => $campaign->audience ?? [],
            'budget_amount' => $campaign->budget_amount !== null ? (float) $campaign->budget_amount : null,
            'budget_currency' => $campaign->budget_currency,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'landing_page' => $campaign->landingPage ? [
                'id' => $campaign->landingPage->uuid,
                'slug' => $campaign->landingPage->slug,
                'title' => $campaign->landingPage->title,
            ] : null,
            'utm' => $campaign->utm ?? [],
            'utm_url' => $this->utmUrl($campaign),
            'owner' => $campaign->owner?->name,
            'approver' => $campaign->approver?->name,
            'approved_at' => $campaign->approved_at?->toIso8601String(),
            'activated_at' => $campaign->activated_at?->toIso8601String(),
            'results' => $campaign->results ?? [],
            'created_by' => $campaign->creator?->name,
            'created_at' => $campaign->created_at?->toIso8601String(),
            'updated_at' => $campaign->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['assets'] = $campaign->assets->map(fn (CampaignAsset $asset) => [
                'id' => $asset->id,
                'type' => $asset->type,
                'status' => $asset->status,
                'content' => $asset->content ?? [],
                'media_asset' => $asset->mediaAsset ? [
                    'id' => $asset->mediaAsset->uuid,
                    'filename' => $asset->mediaAsset->filename,
                    'mime' => $asset->mediaAsset->mime,
                ] : null,
                'created_by' => $asset->creator?->name,
                'created_at' => $asset->created_at?->toIso8601String(),
            ])->values()->all();
            $data['approvals'] = $campaign->approvals->sortByDesc('id')->map(fn (CampaignApproval $approval) => [
                'decision' => $approval->decision,
                'notes' => $approval->notes,
                'requested_by' => $approval->requestedBy?->name,
                'decided_by' => $approval->decidedBy?->name,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'created_at' => $approval->created_at?->toIso8601String(),
            ])->values()->all();
            $data['revisions'] = $campaign->revisions->map(fn (CampaignRevision $revision) => [
                'revision_no' => $revision->revision_no,
                'change_summary' => $revision->change_summary,
                'status' => $revision->snapshot['status'] ?? null,
                'budget_amount' => $revision->snapshot['budget_amount'] ?? null,
                'created_by' => $revision->creator?->name,
                'created_at' => $revision->created_at?->toIso8601String(),
            ])->values()->all();
        }

        return $data;
    }

    private function utmUrl(MarketingCampaign $campaign): ?string
    {
        if ($campaign->landingPage === null) {
            return null;
        }

        $path = $campaign->landingPage->is_home ? '/' : '/'.ltrim($campaign->landingPage->slug, '/');
        $base = rtrim((string) config('marketing.public_url', config('app.url')), '/').$path;

        $utm = $campaign->utm ?? [];
        if ($utm === []) {
            return $base;
        }

        if (empty($utm['campaign'])) {
            $utm['campaign'] = Str::slug($campaign->name);
        }

        $query = collect($utm)->mapWithKeys(fn ($value, $key) => ["utm_{$key}" => $value])->all();

        return $base.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
