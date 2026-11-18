<?php

namespace App\Services\Marketing\Meta;

use App\Exceptions\MarketingIntegrationException;
use App\Models\Marketing\MarketingIntegrationLog;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MetaInstagramAccount;
use App\Models\Marketing\MetaPage;
use App\Models\Marketing\OAuthConnection;
use App\Models\Marketing\SocialPost;
use App\Models\Marketing\SocialPostApproval;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SocialPostService
{
    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{posts: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(?string $status = null): array
    {
        $query = SocialPost::query()
            ->with(['page', 'instagramAccount', 'mediaAsset', 'creator', 'approvals.decidedBy'])
            ->latest('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        $posts = $query->limit(100)->get();

        $counts = SocialPost::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();

        return [
            'posts' => $posts->map(fn (SocialPost $post) => $this->present($post))->all(),
            'counts' => $counts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        $pages = MetaPage::query()
            ->with('instagramAccounts')
            ->orderBy('name')
            ->get()
            ->map(fn (MetaPage $page) => [
                'id' => $page->external_id,
                'name' => $page->name,
                'status' => $page->status,
                'instagram_accounts' => $page->instagramAccounts->map(fn (MetaInstagramAccount $account) => [
                    'id' => $account->external_id,
                    'username' => $account->username,
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $media = MediaAsset::query()
            ->where('mime', 'like', 'image/%')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (MediaAsset $asset) => [
                'id' => $asset->uuid,
                'filename' => $asset->filename,
                'mime' => $asset->mime,
                'url' => Storage::disk($asset->disk)->url($asset->path),
            ])
            ->values()
            ->all();

        return [
            'pages' => $pages,
            'media_assets' => $media,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(SocialPost $post): array
    {
        $post->load(['page', 'instagramAccount', 'mediaAsset', 'creator', 'approvals.decidedBy']);

        return $this->present($post);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): SocialPost
    {
        [$page, $instagram, $asset] = $this->resolveTarget($data);

        $post = SocialPost::create([
            'meta_page_id' => $page?->id,
            'meta_instagram_account_id' => $instagram?->id,
            'media_asset_id' => $asset?->id,
            'caption' => trim((string) $data['caption']),
            'link_url' => $data['link_url'] ?? null,
            'status' => 'draft',
            'created_by' => $actor->id,
        ]);

        $this->audit->log('marketing.social_post.created', $post, null, null, [
            'status' => 'draft',
        ], null, $actor->id);

        return $post;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SocialPost $post, User $actor, array $data): SocialPost
    {
        if (! in_array($post->status, ['draft', 'rejected'], true)) {
            throw new MarketingIntegrationException(
                'SOCIAL_POST_LOCKED',
                'Only draft or rejected posts can be edited. Unpublish or create a new draft to change a scheduled or published post.',
                422,
            );
        }

        [$page, $instagram, $asset] = $this->resolveTarget($data, $post);

        $before = $post->only(['caption', 'status']);
        $post->forceFill([
            'meta_page_id' => $page?->id,
            'meta_instagram_account_id' => $instagram?->id,
            'media_asset_id' => $asset?->id,
            'caption' => trim((string) ($data['caption'] ?? $post->caption)),
            'link_url' => array_key_exists('link_url', $data) ? $data['link_url'] : $post->link_url,
            'status' => 'draft',
            'error' => null,
        ])->save();

        $this->audit->log('marketing.social_post.updated', $post, null, $before, [
            'status' => 'draft',
        ], null, $actor->id);

        return $post;
    }

    public function delete(SocialPost $post, User $actor): void
    {
        if (! in_array($post->status, ['draft', 'rejected'], true)) {
            throw new MarketingIntegrationException(
                'SOCIAL_POST_LOCKED',
                'Only draft or rejected posts can be deleted.',
                422,
            );
        }

        $this->audit->log('marketing.social_post.deleted', $post, null, $post->only(['caption', 'status']), null, null, $actor->id);
        $post->delete();
    }

    public function submitReview(SocialPost $post, User $actor): SocialPost
    {
        if (! in_array($post->status, ['draft', 'rejected'], true)) {
            throw new MarketingIntegrationException('SOCIAL_POST_LOCKED', 'Only draft or rejected posts can be submitted for review.', 422);
        }

        if ($post->meta_page_id === null) {
            throw new MarketingIntegrationException(
                'SOCIAL_PAGE_REQUIRED',
                'Choose a Facebook Page before submitting this post for review.',
                422,
            );
        }

        DB::transaction(function () use ($post, $actor) {
            $post->forceFill(['status' => 'review', 'error' => null])->save();

            SocialPostApproval::create([
                'social_post_id' => $post->id,
                'requested_by' => $actor->id,
                'decision' => 'requested',
            ]);

            $this->audit->log('marketing.social_post.submitted', $post, null, null, ['status' => 'review'], null, $actor->id);
        });

        return $post;
    }

    public function approve(SocialPost $post, User $actor, ?string $notes = null): SocialPost
    {
        $this->assertDecidable($post, $actor);

        DB::transaction(function () use ($post, $actor, $notes) {
            $post->forceFill(['status' => 'approved'])->save();

            SocialPostApproval::create([
                'social_post_id' => $post->id,
                'requested_by' => $post->created_by,
                'decided_by' => $actor->id,
                'decision' => 'approved',
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $this->audit->log('marketing.social_post.approved', $post, $notes, ['status' => 'review'], ['status' => 'approved'], null, $actor->id);
        });

        return $post;
    }

    public function reject(SocialPost $post, User $actor, ?string $notes = null): SocialPost
    {
        $this->assertDecidable($post, $actor);

        DB::transaction(function () use ($post, $actor, $notes) {
            $post->forceFill(['status' => 'rejected'])->save();

            SocialPostApproval::create([
                'social_post_id' => $post->id,
                'requested_by' => $post->created_by,
                'decided_by' => $actor->id,
                'decision' => 'rejected',
                'notes' => $notes,
                'decided_at' => now(),
            ]);

            $this->audit->log('marketing.social_post.rejected', $post, $notes, ['status' => 'review'], ['status' => 'rejected'], null, $actor->id);
        });

        return $post;
    }

    public function schedule(SocialPost $post, User $actor, string $scheduledAt): SocialPost
    {
        if ($post->status !== 'approved') {
            throw new MarketingIntegrationException('SOCIAL_APPROVAL_REQUIRED', 'Only approved posts can be scheduled.', 422);
        }

        $at = Carbon::parse($scheduledAt);
        if ($at->isPast()) {
            throw new MarketingIntegrationException('SOCIAL_SCHEDULE_PAST', 'Choose a scheduling time in the future.', 422);
        }

        $before = $post->only(['status', 'scheduled_at']);
        $post->forceFill(['status' => 'scheduled', 'scheduled_at' => $at, 'error' => null])->save();

        $this->audit->log('marketing.social_post.scheduled', $post, null, $before, [
            'scheduled_at' => $at->toIso8601String(),
        ], null, $actor->id);

        return $post;
    }

    public function publish(SocialPost $post, ?User $actor = null): SocialPost
    {
        if (! in_array($post->status, ['approved', 'scheduled'], true)) {
            throw new MarketingIntegrationException(
                'SOCIAL_APPROVAL_REQUIRED',
                'Only approved or scheduled posts can be published.',
                422,
            );
        }

        $page = $post->page;
        if ($page === null || blank($page->page_token)) {
            throw new MarketingIntegrationException(
                'META_PAGE_TOKEN_MISSING',
                'The Facebook Page for this post is unavailable. Sync Meta assets or choose another Page.',
                409,
            );
        }

        $connection = OAuthConnection::query()->where('provider', 'meta')->first();
        if ($connection === null || $connection->status !== 'connected') {
            throw new MarketingIntegrationException(
                'META_NOT_CONNECTED',
                'Connect a Meta account before publishing a post.',
                409,
            );
        }

        try {
            if ($post->instagramAccount !== null) {
                $externalId = $this->publishToInstagram($post, $page);
            } else {
                $body = ['message' => $post->caption];
                if (filled($post->link_url)) {
                    $body['link'] = $post->link_url;
                }
                $response = $this->graph->post("{$page->external_id}/feed", $page->page_token, $body);
                $externalId = (string) ($response['id'] ?? '');
            }

            $post->forceFill([
                'status' => 'published',
                'published_at' => now(),
                'external_id' => $externalId !== '' ? $externalId : null,
                'error' => null,
            ])->save();

            $this->audit->log('marketing.social_post.published', $post, null, null, [
                'external_id' => $post->external_id,
                'channel' => $post->instagramAccount !== null ? 'instagram' : 'facebook',
            ], null, $actor?->id);
            $this->log($connection, 'info', 'meta.post.published', 'Approved social post published.', [
                'post' => $post->uuid,
                'channel' => $post->instagramAccount !== null ? 'instagram' : 'facebook',
            ]);
        } catch (MarketingIntegrationException $e) {
            $post->forceFill(['status' => 'failed', 'error' => $e->getMessage()])->save();
            $this->audit->log('marketing.social_post.failed', $post, $e->getMessage(), null, null, null, $actor?->id);
            $this->log($connection, 'error', 'meta.post.failed', $e->getMessage(), ['post' => $post->uuid]);

            throw $e;
        }

        return $post;
    }

    public function publishScheduled(SocialPost $post): void
    {
        $this->publish($post->fresh(['page', 'instagramAccount']), null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: MetaPage|null, 1: MetaInstagramAccount|null, 2: MediaAsset|null}
     */
    private function resolveTarget(array $data, ?SocialPost $existing = null): array
    {
        $page = null;
        $pageId = $data['meta_page_id'] ?? $existing?->page?->external_id;
        if (filled($pageId)) {
            $page = MetaPage::query()->where('external_id', (string) $pageId)->first();
            if ($page === null) {
                throw new MarketingIntegrationException('META_PAGE_NOT_FOUND', 'The selected Facebook Page is not connected. Sync Meta assets first.', 422);
            }
        }

        $instagram = null;
        $instagramId = $data['meta_instagram_account_id'] ?? $existing?->instagramAccount?->external_id;
        if (filled($instagramId)) {
            $instagram = MetaInstagramAccount::query()->where('external_id', (string) $instagramId)->first();
            if ($instagram === null || ($page !== null && $instagram->meta_page_id !== $page->id)) {
                throw new MarketingIntegrationException('META_INSTAGRAM_NOT_FOUND', 'The selected Instagram account does not belong to this Facebook Page.', 422);
            }
        }

        $asset = null;
        $assetUuid = $data['media_asset_id'] ?? $existing?->mediaAsset?->uuid;
        if (filled($assetUuid)) {
            $asset = MediaAsset::query()->where('uuid', (string) $assetUuid)->first();
            if ($asset === null) {
                throw new MarketingIntegrationException('MEDIA_ASSET_NOT_FOUND', 'The selected media asset no longer exists.', 422);
            }
        }

        if ($instagram !== null) {
            if ($asset === null) {
                throw new MarketingIntegrationException('META_INSTAGRAM_MEDIA_REQUIRED', 'Instagram posts require a media image.', 422);
            }
            if (! str_starts_with((string) $asset->mime, 'image/jpeg')) {
                throw new MarketingIntegrationException('META_INSTAGRAM_MEDIA_TYPE', 'Instagram feed publishing requires a JPEG image.', 422);
            }
        }

        return [$page, $instagram, $asset];
    }

    private function assertDecidable(SocialPost $post, User $actor): void
    {
        if ($post->status !== 'review') {
            throw new MarketingIntegrationException('SOCIAL_POST_NOT_IN_REVIEW', 'Only posts in review can be approved or rejected.', 422);
        }

        if ($post->created_by === $actor->id) {
            throw new MarketingIntegrationException(
                'SOCIAL_APPROVAL_CONFLICT',
                'A post cannot be approved or rejected by the person who created it.',
                422,
            );
        }
    }

    private function publishToInstagram(SocialPost $post, MetaPage $page): string
    {
        $asset = $post->mediaAsset;
        $instagram = $post->instagramAccount;

        if ($asset === null || $instagram === null) {
            throw new MarketingIntegrationException('META_INSTAGRAM_MEDIA_REQUIRED', 'Instagram posts require a connected account and a JPEG image.', 422);
        }

        $imageUrl = url(Storage::disk($asset->disk)->url($asset->path));

        $container = $this->graph->post("{$instagram->external_id}/media", $page->page_token, [
            'image_url' => $imageUrl,
            'caption' => $post->caption,
        ]);

        $creationId = $container['id'] ?? null;
        if (! is_string($creationId)) {
            throw new MarketingIntegrationException('META_API_ERROR', 'Meta did not return an Instagram media container.', 422);
        }

        $published = $this->graph->post("{$instagram->external_id}/media_publish", $page->page_token, [
            'creation_id' => $creationId,
        ]);

        return (string) ($published['id'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SocialPost $post): array
    {
        return [
            'id' => $post->uuid,
            'caption' => $post->caption,
            'status' => $post->status,
            'link_url' => $post->link_url,
            'page' => $post->page ? [
                'id' => $post->page->external_id,
                'name' => $post->page->name,
            ] : null,
            'instagram_account' => $post->instagramAccount ? [
                'id' => $post->instagramAccount->external_id,
                'username' => $post->instagramAccount->username,
            ] : null,
            'media_asset' => $post->mediaAsset ? [
                'id' => $post->mediaAsset->uuid,
                'filename' => $post->mediaAsset->filename,
                'mime' => $post->mediaAsset->mime,
            ] : null,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'published_at' => $post->published_at?->toIso8601String(),
            'external_id' => $post->external_id,
            'error' => $post->error,
            'insights' => $post->insights ?? [],
            'created_by' => $post->creator?->name,
            'approvals' => $post->approvals->sortByDesc('id')->map(fn (SocialPostApproval $approval) => [
                'decision' => $approval->decision,
                'notes' => $approval->notes,
                'decided_by' => $approval->decidedBy?->name,
                'decided_at' => $approval->decided_at?->toIso8601String(),
                'created_at' => $approval->created_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(OAuthConnection $connection, string $level, string $event, string $message, array $context = []): void
    {
        MarketingIntegrationLog::create([
            'connection_id' => $connection->id,
            'provider' => 'meta',
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
