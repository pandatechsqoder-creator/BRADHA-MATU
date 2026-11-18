<?php

namespace App\Services\Marketing\Google;

use App\Exceptions\MarketingIntegrationException;
use App\Jobs\SyncMarketingGoogle;
use App\Models\Marketing\Ga4Metric;
use App\Models\Marketing\Ga4Property;
use App\Models\Marketing\GoogleBusinessAccount;
use App\Models\Marketing\GoogleBusinessLocation;
use App\Models\Marketing\GoogleSearchMetric;
use App\Models\Marketing\GoogleSearchProperty;
use App\Models\Marketing\GtmContainer;
use App\Models\Marketing\MarketingIntegrationLog;
use App\Models\Marketing\MarketingIntegrationSyncRun;
use App\Models\Marketing\OAuthConnection;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class GoogleIntegrationService
{
    public const SYNC_TYPES = ['search_console', 'ga4', 'business', 'gtm'];

    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $connection = $this->connection();

        return [
            'provider' => 'google',
            'configured' => $this->oauth->configured(),
            'redirect_uri' => config('services.google.redirect_uri'),
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
                'search_console' => true,
                'ga4' => true,
                'business_profile' => true,
                'reviews' => false,
                'tag_manager' => true,
            ],
            'counts' => [
                'search_properties' => GoogleSearchProperty::query()->count(),
                'search_metric_rows' => GoogleSearchMetric::query()->count(),
                'ga4_properties' => Ga4Property::query()->count(),
                'ga4_metric_rows' => Ga4Metric::query()->count(),
                'business_accounts' => GoogleBusinessAccount::query()->count(),
                'business_locations' => GoogleBusinessLocation::query()->count(),
                'gtm_containers' => GtmContainer::query()->count(),
            ],
            'runs' => MarketingIntegrationSyncRun::query()
                ->where('provider', 'google')
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
                ->where('provider', 'google')
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
                'GOOGLE_OAUTH_STATE_INVALID',
                'This Google sign-in link is invalid or has already been used. Start the connection again.',
                422,
            );
        }

        $user = User::query()->find($payload['user_id']);
        if ($user === null) {
            throw new MarketingIntegrationException(
                'GOOGLE_OAUTH_STATE_INVALID',
                'The user who started this Google connection no longer exists.',
                422,
            );
        }

        $tokens = $this->oauth->exchange($code, $payload['verifier']);
        $profile = $this->oauth->userInfo($tokens['access_token']);

        $connection = DB::transaction(function () use ($tokens, $profile, $user) {
            $connection = OAuthConnection::query()->updateOrCreate(
                ['provider' => 'google'],
                [
                    'account_label' => $profile['email'] ?? 'Google account',
                    'credentials' => $tokens,
                    'scopes' => $this->oauth->scopes(),
                    'status' => 'connected',
                    'connected_by' => $user->id,
                    'last_error' => null,
                ],
            );

            $this->audit->log('marketing.integration.google.connected', $connection, null, null, [
                'account' => $connection->account_label,
            ], null, $user->id);

            return $connection;
        });

        $this->log($connection, 'info', 'google.connected', 'Google account connected.', [
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
            GoogleBusinessAccount::query()->where('connection_id', $connection->id)->delete();
            GoogleSearchProperty::query()->where('connection_id', $connection->id)->delete();
            Ga4Property::query()->where('connection_id', $connection->id)->delete();
            GtmContainer::query()->where('connection_id', $connection->id)->delete();

            $connection->forceFill([
                'credentials' => null,
                'status' => 'revoked',
                'last_error' => null,
            ])->save();

            $this->audit->log('marketing.integration.google.disconnected', $connection, null, null, null, null, $actor->id);
        });

        $this->log($connection, 'info', 'google.disconnected', 'Google account disconnected and stored metrics cleared.');
    }

    /**
     * @return list<MarketingIntegrationSyncRun>
     */
    public function queueSync(string $type, User $actor): array
    {
        $connection = $this->connection();
        if ($connection === null || ! $connection->isConnected()) {
            throw new MarketingIntegrationException(
                'GOOGLE_NOT_CONNECTED',
                'Connect a Google account before syncing data.',
                409,
            );
        }

        $types = $type === 'all' ? self::SYNC_TYPES : [$type];

        $runs = [];
        foreach ($types as $syncType) {
            $run = MarketingIntegrationSyncRun::create([
                'connection_id' => $connection->id,
                'provider' => 'google',
                'type' => $syncType,
                'status' => 'queued',
            ]);
            $runs[] = $run;

            $this->audit->log('marketing.integration.google.sync_queued', $connection, null, null, [
                'type' => $syncType,
            ], null, $actor->id);

            SyncMarketingGoogle::dispatch($run->uuid);
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
                'search_console' => $this->syncSearchConsole($connection),
                'ga4' => $this->syncGa4($connection),
                'business' => $this->syncBusiness($connection),
                'gtm' => $this->syncGtm($connection),
                default => throw new MarketingIntegrationException('GOOGLE_SYNC_UNKNOWN', 'Unknown Google sync type.', 422),
            };

            $run->forceFill([
                'status' => 'success',
                'finished_at' => now(),
                'counts' => $counts,
            ])->save();

            $connection->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();
            $this->log($connection, 'info', 'google.sync_completed', "Google {$run->type} sync completed.", $counts);
        } catch (\Throwable $e) {
            $message = $e instanceof MarketingIntegrationException
                ? $e->getMessage()
                : 'The Google sync failed before it could complete.';

            $run->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => $message])->save();
            $connection->forceFill(['last_error' => $message])->save();

            if ($e instanceof MarketingIntegrationException && $e->errorCode === 'GOOGLE_REAUTH_REQUIRED') {
                $connection->forceFill(['status' => 'expired'])->save();
            }

            $this->log($connection, 'error', 'google.sync_failed', $message, ['type' => $run->type]);
            throw new \RuntimeException($message, 0, $e);
        }
    }

    /**
     * @return array<string, int>
     */
    private function syncSearchConsole(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $sites = $this->getJson('https://www.googleapis.com/webmasters/v3/sites', $token);
        $start = Carbon::today()->subDays(27);
        $end = Carbon::today()->subDay();
        $rows = 0;
        $properties = 0;

        foreach ($sites['siteEntry'] ?? [] as $site) {
            if (! is_array($site) || ! isset($site['siteUrl'])) {
                continue;
            }

            $property = GoogleSearchProperty::updateOrCreate(
                ['connection_id' => $connection->id, 'site_url' => (string) $site['siteUrl']],
                ['permission_level' => $site['permissionLevel'] ?? null, 'synced_at' => now()],
            );
            $properties++;

            $response = $this->postJson(
                'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property->site_url).'/searchAnalytics/query',
                $token,
                [
                    'startDate' => $start->toDateString(),
                    'endDate' => $end->toDateString(),
                    'dimensions' => ['date'],
                    'rowLimit' => 100,
                ],
            );

            foreach ($response['rows'] ?? [] as $row) {
                $date = $row['keys'][0] ?? null;
                if (! is_string($date)) {
                    continue;
                }

                GoogleSearchMetric::updateOrCreate(
                    [
                        'property_id' => $property->id,
                        'date' => $date,
                        'dimension_hash' => hash('sha256', '||'),
                    ],
                    [
                        'clicks' => (int) ($row['clicks'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0),
                        'ctr' => (float) ($row['ctr'] ?? 0),
                        'position' => (float) ($row['position'] ?? 0),
                    ],
                );
                $rows++;
            }
        }

        return ['properties' => $properties, 'rows' => $rows, 'window_days' => 28];
    }

    /**
     * @return array<string, int>
     */
    private function syncGa4(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $summaries = $this->getJson('https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200', $token);
        $filter = (string) config('services.google.analytics_property_id', '');
        $start = Carbon::today()->subDays(27);
        $end = Carbon::today()->subDay();
        $properties = 0;
        $rows = 0;

        foreach ($summaries['accountSummaries'] ?? [] as $account) {
            foreach ($account['propertySummaries'] ?? [] as $summary) {
                $resource = $summary['property'] ?? null;
                if (! is_string($resource)) {
                    continue;
                }

                $propertyId = str_replace('properties/', '', $resource);
                if ($filter !== '' && $propertyId !== $filter) {
                    continue;
                }

                $property = Ga4Property::updateOrCreate(
                    ['connection_id' => $connection->id, 'property_id' => $propertyId],
                    ['display_name' => $summary['displayName'] ?? $propertyId, 'synced_at' => now()],
                );
                $properties++;

                $report = $this->postJson(
                    "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:runReport",
                    $token,
                    [
                        'dateRanges' => [['startDate' => $start->toDateString(), 'endDate' => $end->toDateString()]],
                        'dimensions' => [['name' => 'date']],
                        'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'conversions']],
                        'limit' => 100,
                    ],
                );

                foreach ($report['rows'] ?? [] as $row) {
                    $rawDate = $row['dimensionValues'][0]['value'] ?? null;
                    if (! is_string($rawDate) || strlen($rawDate) !== 8) {
                        continue;
                    }

                    $metrics = $row['metricValues'] ?? [];
                    Ga4Metric::updateOrCreate(
                        ['property_id' => $property->id, 'date' => Carbon::createFromFormat('Ymd', $rawDate)->toDateString()],
                        [
                            'sessions' => (int) ($metrics[0]['value'] ?? 0),
                            'total_users' => (int) ($metrics[1]['value'] ?? 0),
                            'conversions' => (float) ($metrics[2]['value'] ?? 0),
                        ],
                    );
                    $rows++;
                }
            }
        }

        return ['properties' => $properties, 'rows' => $rows, 'window_days' => 28];
    }

    /**
     * @return array<string, int>
     */
    private function syncBusiness(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $accounts = $this->getJson('https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=100', $token);
        $filter = (string) config('services.google.business_account_id', '');
        $accountCount = 0;
        $locationCount = 0;

        foreach ($accounts['accounts'] ?? [] as $account) {
            $externalId = $account['name'] ?? null;
            if (! is_string($externalId)) {
                continue;
            }

            if ($filter !== '' && $externalId !== $filter && ! str_ends_with($externalId, '/'.$filter)) {
                continue;
            }

            $record = GoogleBusinessAccount::updateOrCreate(
                ['connection_id' => $connection->id, 'external_id' => $externalId],
                [
                    'name' => $account['accountName'] ?? $externalId,
                    'metadata' => [
                        'type' => $account['type'] ?? null,
                        'role' => $account['role'] ?? null,
                    ],
                    'synced_at' => now(),
                ],
            );
            $accountCount++;

            $locations = $this->getJson(
                "https://mybusinessbusinessinformation.googleapis.com/v1/{$externalId}/locations?readMask=name,title,storefrontAddress,phoneNumbers,metadata&pageSize=100",
                $token,
            );

            foreach ($locations['locations'] ?? [] as $location) {
                $locationId = $location['name'] ?? null;
                if (! is_string($locationId)) {
                    continue;
                }

                GoogleBusinessLocation::updateOrCreate(
                    ['account_id' => $record->id, 'external_id' => $locationId],
                    [
                        'connection_id' => $connection->id,
                        'name' => $location['title'] ?? $locationId,
                        'address' => $location['storefrontAddress'] ?? null,
                        'phone' => $location['phoneNumbers']['primaryPhone'] ?? null,
                        'status' => 'synced',
                        'insights' => $location['metadata'] ?? null,
                        'synced_at' => now(),
                    ],
                );
                $locationCount++;
            }
        }

        $this->log($connection, 'info', 'google.reviews.unsupported', 'Business Profile reviews are not available through the current API version; no review data was stored.');

        return ['accounts' => $accountCount, 'locations' => $locationCount];
    }

    /**
     * @return array<string, int>
     */
    private function syncGtm(OAuthConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $accounts = $this->getJson('https://www.googleapis.com/tagmanager/v2/users/me/accounts?pageSize=100', $token);
        $containers = 0;

        foreach ($accounts['account'] ?? [] as $account) {
            $accountId = $account['accountId'] ?? null;
            if (! is_string($accountId)) {
                continue;
            }

            $response = $this->getJson(
                "https://www.googleapis.com/tagmanager/v2/accounts/{$accountId}/containers?pageSize=100",
                $token,
            );

            foreach ($response['container'] ?? [] as $container) {
                $containerId = $container['containerId'] ?? null;
                if (! is_string($containerId)) {
                    continue;
                }

                GtmContainer::updateOrCreate(
                    ['connection_id' => $connection->id, 'container_id' => $containerId],
                    [
                        'public_id' => $container['publicId'] ?? null,
                        'name' => $container['name'] ?? $containerId,
                        'workspace_id' => null,
                        'last_published_at' => null,
                    ],
                );
                $containers++;
            }
        }

        return ['containers' => $containers];
    }

    private function accessToken(OAuthConnection $connection): string
    {
        if (! $connection->isConnected()) {
            throw new MarketingIntegrationException(
                'GOOGLE_NOT_CONNECTED',
                'Connect a Google account before syncing data.',
                409,
            );
        }

        $credentials = $connection->credentials;
        $expiresAt = isset($credentials['expires_at']) ? Carbon::parse($credentials['expires_at']) : null;

        if ($expiresAt !== null && $expiresAt->isAfter(now()->addMinute())) {
            return (string) $credentials['access_token'];
        }

        try {
            $refreshed = $this->oauth->refresh($credentials);
        } catch (MarketingIntegrationException $e) {
            $connection->forceFill(['status' => 'expired', 'last_error' => $e->getMessage()])->save();
            $this->log($connection, 'error', 'google.token_expired', $e->getMessage());
            throw $e;
        }

        $connection->forceFill([
            'credentials' => array_merge($credentials, $refreshed),
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return (string) $refreshed['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url, string $token): array
    {
        return $this->request('get', $url, $token);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function postJson(string $url, string $token, array $body): array
    {
        return $this->request('post', $url, $token, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, string $token, array $body = []): array
    {
        $client = Http::withToken($token)->acceptJson()->timeout(30);

        try {
            $response = $method === 'post' ? $client->post($url, $body) : $client->get($url);
        } catch (\Throwable $e) {
            Log::warning('Google integration request failed', ['url' => parse_url($url, PHP_URL_HOST)]);
            throw new MarketingIntegrationException(
                'GOOGLE_API_UNREACHABLE',
                'Google could not be reached. Try the sync again later.',
                502,
            );
        }

        if ($response->status() === 401) {
            throw new MarketingIntegrationException(
                'GOOGLE_REAUTH_REQUIRED',
                'Google rejected the access token. Reconnect the account.',
                409,
            );
        }

        if ($response->status() === 403) {
            throw new MarketingIntegrationException(
                'GOOGLE_PERMISSION_DENIED',
                'The connected Google account does not have access to this data. Check the account permissions and scopes.',
                403,
            );
        }

        if (! $response->successful()) {
            $detail = $response->json('error.message');
            $message = is_string($detail) && $detail !== ''
                ? 'Google returned an error: '.mb_substr($detail, 0, 300)
                : "Google returned HTTP {$response->status()}.";

            throw new MarketingIntegrationException('GOOGLE_API_ERROR', $message, 502);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function connection(): ?OAuthConnection
    {
        return OAuthConnection::query()->with('connectedBy')->where('provider', 'google')->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(OAuthConnection $connection, string $level, string $event, string $message, array $context = []): void
    {
        MarketingIntegrationLog::create([
            'connection_id' => $connection->id,
            'provider' => 'google',
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context,
            'created_at' => now(),
        ]);
    }
}
