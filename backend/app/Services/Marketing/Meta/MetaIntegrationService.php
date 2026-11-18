<?php

namespace App\Services\Marketing\Meta;

use App\Exceptions\MarketingIntegrationException;
use App\Jobs\SyncMarketingMeta;
use App\Models\Marketing\MarketingIntegrationLog;
use App\Models\Marketing\MarketingIntegrationSyncRun;
use App\Models\Marketing\MetaAdAccount;
use App\Models\Marketing\MetaBusinessAccount;
use App\Models\Marketing\MetaCampaignRecord;
use App\Models\Marketing\MetaInstagramAccount;
use App\Models\Marketing\MetaPage;
use App\Models\Marketing\OAuthConnection;
use App\Models\Marketing\SocialPost;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class MetaIntegrationService
{
    public const SYNC_TYPES = ['assets', 'insights', 'ads'];

    public function __construct(
        private readonly MetaOAuthService $oauth,
        private readonly MetaGraphClient $graph,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connection = $this->connection();

        return [
            'provider' => 'meta',
            'configured' => $this->oauth->configured(),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'required_scopes' => $this->oauth->scopes(),
            'connection' => $connection ? [
                'id' => $connection->uuid,
                'status' => $connection->status,
                'account_label' => $connection->account_label,
                'scopes' => $connection->scopes ?? [],
                'connected_by' => $connection->connectedBy?->name,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
                'last_error' => $connection->last_error,
                'expires_at' => $connection->credentials['expires_at'] ?? null,
            ] : null,
            'capabilities' => [
                'pages' => true,
                'instagram' => true,
                'publishing' => true,
                'insights' => true,
                'ad_reporting' => true,
                'spend_actions' => false,
            ],
            'counts' => [
                'business_accounts' => MetaBusinessAccount::query()->count(),
                'pages' => MetaPage::query()->count(),
                'instagram_accounts' => MetaInstagramAccount::query()->count(),
                'ad_accounts' => MetaAdAccount::query()->count(),
                'campaigns' => MetaCampaignRecord::query()->count(),
                'social_posts' => SocialPost::query()->count(),
                'published_posts' => SocialPost::query()->where('status', 'published')->count(),
            ],
            'assets' => [
                'businesses' => MetaBusinessAccount::query()->orderBy('name')->get()
                    ->map(fn (MetaBusinessAccount $business) => [
                        'id' => $business->external_id,
                        'name' => $business->name,
                    ])->all(),
                'pages' => MetaPage::query()->with('instagramAccounts')->orderBy('name')->get()
                    ->map(fn (MetaPage $page) => [
                        'id' => $page->external_id,
                        'name' => $page->name,
                        'category' => $page->category,
                        'status' => $page->status,
                        'instagram_accounts' => $page->instagramAccounts->map(fn (MetaInstagramAccount $account) => [
                            'id' => $account->external_id,
                            'username' => $account->username,
                        ])->all(),
                    ])->all(),
                'ad_accounts' => MetaAdAccount::query()->orderBy('name')->get()
                    ->map(fn (MetaAdAccount $account) => [
                        'id' => $account->external_id,
                        'name' => $account->name,
                        'currency' => $account->currency,
                        'spend_cap' => $account->spend_cap,
                        'status' => $account->status,
                    ])->all(),
                'campaigns' => MetaCampaignRecord::query()->with('adAccount')->latest('synced_at')->limit(50)->get()
                    ->map(fn (MetaCampaignRecord $campaign) => [
                        'id' => $campaign->external_id,
                        'name' => $campaign->name,
                        'status' => $campaign->status,
                        'ad_account' => $campaign->adAccount?->name,
                        'budget_amount' => $campaign->budget_amount,
                        'spend_amount' => $campaign->spend_amount,
                        'impressions' => $campaign->impressions,
                        'clicks' => $campaign->clicks,
                        'synced_at' => $campaign->synced_at?->toIso8601String(),
                    ])->all(),
            ],
            'runs' => MarketingIntegrationSyncRun::query()
                ->where('provider', 'meta')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (MarketingIntegrationSyncRun $run) => [
                    'id' => $run->uuid,
                    'type' => $run->type,
                    'status' => $run->status,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'counts' => $run->counts ?? [],
                    'error' => $run->error,
                ])->all(),
            'logs' => MarketingIntegrationLog::query()
                ->where('provider', 'meta')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MarketingIntegrationLog $log) => [
                    'level' => $log->level,
                    'event' => $log->event,
                    'message' => $log->message,
                    'created_at' => $log->created_at?->toIso8601String(),
                ])->all(),
        ];
    }

    public function connectUrl(User $user): string
    {
        return $this->oauth->authorizationUrl($user);
    }

    public function handleCallback(string $code, string $state): OAuthConnection
    {
        $payload = $this->oauth->consumeState($state);
        if ($payload === null) {
            throw new MarketingIntegrationException(
                'META_OAUTH_STATE_INVALID',
                'This Meta sign-in link is invalid or has already been used. Start the connection again.',
                422,
            );
        }

        $user = User::query()->find($payload['user_id']);
        if ($user === null) {
            throw new MarketingIntegrationException(
                'META_OAUTH_STATE_INVALID',
                'The user who started this Meta connection no longer exists.',
                422,
            );
        }

        $tokens = $this->oauth->exchange($code);
        $profile = $this->oauth->userInfo($tokens['access_token']);

        $connection = DB::transaction(function () use ($tokens, $profile, $user) {
            $connection = OAuthConnection::query()->updateOrCreate(
                ['provider' => 'meta'],
                [
                    'account_label' => $profile['name'] ?? ($profile['email'] ?? 'Meta account'),
                    'credentials' => $tokens,
                    'scopes' => $this->oauth->scopes(),
                    'status' => 'connected',
                    'connected_by' => $user->id,
                    'last_error' => null,
                ],
            );

            $this->audit->log('marketing.integration.meta.connected', $connection, null, null, [
                'account' => $connection->account_label,
            ], null, $user->id);

            return $connection;
        });

        $this->log($connection, 'info', 'meta.connected', 'Meta account connected.', [
            'account' => $connection->account_label,
        ]);

        return $connection;
    }

    public function disconnect(User $actor): void
    {
        $connection = $this->connection();
        if ($connection === null) {
            return;
        }

        $accessToken = $connection->credentials['access_token'] ?? null;
        if (is_string($accessToken)) {
            $this->oauth->revoke($accessToken);
        }

        DB::transaction(function () use ($connection, $actor) {
            MetaBusinessAccount::query()->where('connection_id', $connection->id)->delete();
            MetaPage::query()->where('connection_id', $connection->id)->delete();
            MetaInstagramAccount::query()->where('connection_id', $connection->id)->delete();
            MetaAdAccount::query()->where('connection_id', $connection->id)->delete();

            $connection->forceFill([
                'credentials' => null,
                'status' => 'revoked',
                'last_error' => null,
            ])->save();

            $this->audit->log('marketing.integration.meta.disconnected', $connection, null, null, null, null, $actor->id);
        });

        $this->log($connection, 'info', 'meta.disconnected', 'Meta account disconnected and connected assets cleared.');
    }

    /**
     * @return list<MarketingIntegrationSyncRun>
     */
    public function queueSync(string $type, User $actor): array
    {
        $connection = $this->connection();
        if ($connection === null || ! $connection->isConnected()) {
            throw new MarketingIntegrationException(
                'META_NOT_CONNECTED',
                'Connect a Meta account before syncing data.',
                409,
            );
        }

        $types = $type === 'all' ? self::SYNC_TYPES : [$type];

        $runs = [];
        foreach ($types as $syncType) {
            $run = MarketingIntegrationSyncRun::create([
                'connection_id' => $connection->id,
                'provider' => 'meta',
                'type' => $syncType,
                'status' => 'queued',
            ]);
            $runs[] = $run;

            $this->audit->log('marketing.integration.meta.sync_queued', $connection, null, null, [
                'type' => $syncType,
            ], null, $actor->id);

            SyncMarketingMeta::dispatch($run->uuid);
        }

        return $runs;
    }

    public function execute(MarketingIntegrationSyncRun $run): void
    {
        $connection = $run->connection;
        if ($connection === null) {
            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => now(), 'error' => null])->save();

        try {
            $counts = match ($run->type) {
                'assets' => $this->syncAssets($connection),
                'insights' => $this->syncInsights($connection),
                'ads' => $this->syncAds($connection),
                default => throw new MarketingIntegrationException('META_SYNC_UNKNOWN', 'Unknown Meta sync type.', 422),
            };

            $run->forceFill([
                'status' => 'success',
                'finished_at' => now(),
                'counts' => $counts,
            ])->save();

            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
            $this->log($connection, 'info', 'meta.sync_completed', "Meta {$run->type} sync completed.", $counts);
        } catch (\Throwable $e) {
            $message = $e instanceof MarketingIntegrationException
                ? $e->getMessage()
                : 'The Meta sync failed before it could complete.';

            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => $message])->save();
            $connection->forceFill(['last_error' => $message])->save();

            if ($e instanceof MarketingIntegrationException && $e->errorCode === 'META_REAUTH_REQUIRED') {
                $connection->forceFill(['status' => 'expired'])->save();
            }

            $this->log($connection, 'error', 'meta.sync_failed', $message, ['type' => $run->type]);
            throw new \RuntimeException($message, 0, $e);
        }
    }

    /**
     * @return array<string, int>
     */
    private function syncAssets(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $businessCount = 0;

        try {
            $businesses = $this->graph->get('me/businesses', $token, [
                'fields' => 'id,name',
                'limit' => 100,
            ]);

            foreach ($businesses['data'] ?? [] as $business) {
                $externalId = $business['id'] ?? null;
                if (! is_string($externalId)) {
                    continue;
                }

                MetaBusinessAccount::updateOrCreate(
                    ['connection_id' => $connection->id, 'external_id' => $externalId],
                    ['name' => $business['name'] ?? $externalId, 'synced_at' => now()],
                );
                $businessCount++;
            }
        } catch (MarketingIntegrationException $e) {
            if (! in_array($e->errorCode, ['META_PERMISSION_DENIED'], true)) {
                throw $e;
            }
            $this->log($connection, 'warning', 'meta.businesses.unsupported', 'Meta Business accounts are not available for this login; continuing with Pages and ad accounts.');
        }

        $pages = $this->graph->get('me/accounts', $token, [
            'fields' => 'id,name,category,access_token,instagram_business_account{id,username}',
            'limit' => 100,
        ]);

        $pageCount = 0;
        $instagramCount = 0;

        foreach ($pages['data'] ?? [] as $page) {
            $externalId = $page['id'] ?? null;
            if (! is_string($externalId)) {
                continue;
            }

            $record = MetaPage::updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $externalId],
                [
                    'name' => $page['name'] ?? $externalId,
                    'category' => $page['category'] ?? null,
                    'page_token' => $page['access_token'] ?? null,
                    'status' => 'active',
                    'synced_at' => now(),
                ],
            );
            $pageCount++;

            $instagram = $page['instagram_business_account'] ?? null;
            if (is_array($instagram) && isset($instagram['id'])) {
                MetaInstagramAccount::updateOrCreate(
                    ['connection_id' => $connection->id, 'external_id' => (string) $instagram['id']],
                    [
                        'meta_page_id' => $record->id,
                        'username' => $instagram['username'] ?? (string) $instagram['id'],
                        'status' => 'active',
                        'synced_at' => now(),
                    ],
                );
                $instagramCount++;
            }
        }

        $adAccounts = $this->graph->get('me/adaccounts', $token, [
            'fields' => 'id,account_id,name,currency,account_status,spend_cap',
            'limit' => 100,
        ]);

        $adAccountCount = 0;
        foreach ($adAccounts['data'] ?? [] as $account) {
            $externalId = $account['id'] ?? null;
            if (! is_string($externalId)) {
                continue;
            }

            MetaAdAccount::updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $externalId],
                [
                    'name' => $account['name'] ?? $externalId,
                    'currency' => $account['currency'] ?? null,
                    'spend_cap' => isset($account['spend_cap']) ? (float) $account['spend_cap'] : null,
                    'status' => isset($account['account_status']) ? (string) $account['account_status'] : null,
                    'synced_at' => now(),
                ],
            );
            $adAccountCount++;
        }

        return [
            'businesses' => $businessCount,
            'pages' => $pageCount,
            'instagram_accounts' => $instagramCount,
            'ad_accounts' => $adAccountCount,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function syncInsights(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $pages = 0;
        $posts = 0;

        foreach (MetaPage::query()->orderBy('id')->get() as $page) {
            $response = $this->graph->get("{$page->external_id}/insights", $token, [
                'metric' => 'page_impressions,page_post_engagements',
                'period' => 'day',
                'date_preset' => 'last_28d',
            ]);

            $insights = [];
            foreach ($response['data'] ?? [] as $metric) {
                if (isset($metric['name'])) {
                    $insights[$metric['name']] = $metric['values'] ?? [];
                }
            }

            $page->forceFill(['insights' => $insights, 'synced_at' => now()])->save();
            $pages++;
        }

        $published = SocialPost::query()
            ->where('status', 'published')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->get();

        foreach ($published as $post) {
            try {
                $response = $this->graph->get("{$post->external_id}/insights", $token, [
                    'metric' => 'post_impressions,post_impressions_unique,post_reactions_by_type_total',
                ]);
            } catch (MarketingIntegrationException $e) {
                $this->log($connection, 'warning', 'meta.post_insights.failed', 'Post insights were unavailable for one published post.', [
                    'post' => $post->uuid,
                    'code' => $e->errorCode,
                ]);

                continue;
            }

            $insights = [];
            foreach ($response['data'] ?? [] as $metric) {
                if (isset($metric['name'])) {
                    $insights[$metric['name']] = $metric['values'] ?? [];
                }
            }

            $post->forceFill(['insights' => $insights])->save();
            $posts++;
        }

        return ['pages' => $pages, 'posts' => $posts];
    }

    /**
     * @return array<string, int>
     */
    private function syncAds(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $filter = (string) config('services.meta.ad_account_id', '');
        $accounts = 0;
        $campaigns = 0;
        $rows = 0;

        foreach (MetaAdAccount::query()->orderBy('id')->get() as $account) {
            if ($filter !== '' && $account->external_id !== $filter && $account->external_id !== 'act_'.$filter) {
                continue;
            }

            $campaignResponse = $this->graph->get("{$account->external_id}/campaigns", $token, [
                'fields' => 'id,name,status,daily_budget,lifetime_budget',
                'limit' => 200,
            ]);

            $budgets = [];
            foreach ($campaignResponse['data'] ?? [] as $campaign) {
                $campaignId = $campaign['id'] ?? null;
                if (! is_string($campaignId)) {
                    continue;
                }
                $budgets[$campaignId] = $campaign;
            }

            $insightResponse = $this->graph->get("{$account->external_id}/insights", $token, [
                'level' => 'campaign',
                'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions',
                'date_preset' => 'last_28d',
                'limit' => 500,
            ]);

            $seen = [];
            foreach ($insightResponse['data'] ?? [] as $row) {
                $campaignId = $row['campaign_id'] ?? null;
                if (! is_string($campaignId)) {
                    continue;
                }

                $campaign = $budgets[$campaignId] ?? [];
                $dailyBudget = isset($campaign['daily_budget']) ? (float) $campaign['daily_budget'] : null;
                $lifetimeBudget = isset($campaign['lifetime_budget']) ? (float) $campaign['lifetime_budget'] : null;

                MetaCampaignRecord::updateOrCreate(
                    ['ad_account_id' => $account->id, 'external_id' => $campaignId],
                    [
                        'name' => $row['campaign_name'] ?? ($campaign['name'] ?? $campaignId),
                        'status' => $campaign['status'] ?? null,
                        'budget_amount' => $dailyBudget !== null ? $dailyBudget / 100 : ($lifetimeBudget !== null ? $lifetimeBudget / 100 : null),
                        'spend_amount' => (float) ($row['spend'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'results' => $row['actions'] ?? [],
                        'synced_at' => now(),
                    ],
                );
                $seen[$campaignId] = true;
                $rows++;
            }

            foreach ($budgets as $campaignId => $campaign) {
                if (isset($seen[$campaignId])) {
                    continue;
                }

                $dailyBudget = isset($campaign['daily_budget']) ? (float) $campaign['daily_budget'] : null;
                $lifetimeBudget = isset($campaign['lifetime_budget']) ? (float) $campaign['lifetime_budget'] : null;

                MetaCampaignRecord::updateOrCreate(
                    ['ad_account_id' => $account->id, 'external_id' => $campaignId],
                    [
                        'name' => $campaign['name'] ?? $campaignId,
                        'status' => $campaign['status'] ?? null,
                        'budget_amount' => $dailyBudget !== null ? $dailyBudget / 100 : ($lifetimeBudget !== null ? $lifetimeBudget / 100 : null),
                        'spend_amount' => 0,
                        'impressions' => 0,
                        'clicks' => 0,
                        'results' => [],
                        'synced_at' => now(),
                    ],
                );
                $campaigns++;
            }

            $campaigns += count($seen);
            $accounts++;
        }

        return ['ad_accounts' => $accounts, 'campaigns' => $campaigns, 'rows' => $rows];
    }

    private function accessToken(OAuthConnection $connection): string
    {
        if (! $connection->isConnected()) {
            throw new MarketingIntegrationException(
                'META_NOT_CONNECTED',
                'Connect a Meta account before syncing data.',
                409,
            );
        }

        $credentials = $connection->credentials;
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt !== null && $expiresAt->isPast()) {
            $connection->forceFill([
                'status' => 'expired',
                'last_error' => 'The Meta access token expired. Reconnect the account.',
            ])->save();
            $this->log($connection, 'error', 'meta.token_expired', 'The Meta access token expired. Reconnect the account.');

            throw new MarketingIntegrationException(
                'META_REAUTH_REQUIRED',
                'The Meta connection has expired. Reconnect the account to continue.',
                409,
            );
        }

        return (string) $credentials['access_token'];
    }

    private function connection(): ?OAuthConnection
    {
        return OAuthConnection::query()->with('connectedBy')->where('provider', 'meta')->first();
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
