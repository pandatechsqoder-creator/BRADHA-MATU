<?php

namespace Tests\Feature;

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
use App\Services\Marketing\Google\GoogleIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->admin = $this->userWithRole('marketing-integration-admin');
        config([
            'marketing.app_url' => 'http://app.test',
            'services.google.client_id' => 'client-id',
            'services.google.client_secret' => 'client-secret',
            'services.google.redirect_uri' => 'http://localhost/api/v1/platform/marketing/integrations/google/callback',
            'services.google.scopes' => ['openid', 'email', 'https://www.googleapis.com/auth/webmasters.readonly'],
            'services.google.business_account_id' => null,
            'services.google.analytics_property_id' => null,
        ]);
    }

    public function test_unconfigured_google_reports_honest_state_and_cannot_start_oauth(): void
    {
        config([
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
        ]);

        $this->as($this->admin)->getJson('/api/v1/platform/marketing/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.connection', null)
            ->assertJsonPath('data.capabilities.reviews', false);

        $this->as($this->admin)->getJson('/api/v1/platform/marketing/integrations/google/connect')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'GOOGLE_NOT_CONFIGURED');
    }

    public function test_connect_uses_pkce_and_callback_stores_encrypted_credentials_with_single_use_state(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'secret-access-token',
                'refresh_token' => 'secret-refresh-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
                'scope' => 'openid email',
            ]),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'email' => 'owner@brathamatu.test',
                'name' => 'Owner',
            ]),
        ]);

        $url = $this->as($this->admin)
            ->getJson('/api/v1/platform/marketing/integrations/google/connect')
            ->assertOk()
            ->json('data.authorization_url');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('true', $query['include_granted_scopes']);
        $this->assertArrayHasKey('code_challenge', $query);
        $this->assertNotSame('', $query['state']);

        $this->get('/api/v1/platform/marketing/integrations/google/callback?code=auth-code&state='.urlencode($query['state']))
            ->assertRedirect('http://app.test/platform/marketing/integrations/google?google=connected');

        $connection = OAuthConnection::query()->where('provider', 'google')->firstOrFail();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('owner@brathamatu.test', $connection->account_label);
        $this->assertSame($this->admin->id, $connection->connected_by);
        $this->assertTrue($connection->isConnected());

        $raw = (string) DB::table('oauth_connections')->where('id', $connection->id)->value('credentials');
        $this->assertStringNotContainsString('secret-access-token', $raw);
        $this->assertStringNotContainsString('secret-refresh-token', $raw);

        $this->get('/api/v1/platform/marketing/integrations/google/callback?code=auth-code&state='.urlencode($query['state']))
            ->assertRedirect('http://app.test/platform/marketing/integrations/google?google=error&reason=google_oauth_state_invalid');

        Http::assertSentCount(2);
    }

    public function test_callback_denied_redirects_with_error_flag(): void
    {
        $this->get('/api/v1/platform/marketing/integrations/google/callback?error=access_denied')
            ->assertRedirect('http://app.test/platform/marketing/integrations/google?google=error&reason=denied');

        $this->assertSame(0, OAuthConnection::query()->count());
    }

    public function test_sync_requires_a_connected_account_and_queues_requested_types(): void
    {
        $this->as($this->admin)->postJson('/api/v1/platform/marketing/integrations/google/sync', ['type' => 'ga4'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'GOOGLE_NOT_CONNECTED');

        $this->connected();

        Queue::fake();
        $response = $this->as($this->admin)->postJson('/api/v1/platform/marketing/integrations/google/sync', ['type' => 'all'])
            ->assertStatus(202)
            ->assertJsonCount(4, 'data.runs');

        $this->assertSame(
            ['search_console', 'ga4', 'business', 'gtm'],
            array_column($response->json('data.runs'), 'type'),
        );
        Queue::assertPushed(SyncMarketingGoogle::class, 4);
        $this->assertSame(4, MarketingIntegrationSyncRun::query()->where('status', 'queued')->count());

        $this->as($this->admin)->postJson('/api/v1/platform/marketing/integrations/google/sync', ['type' => 'unknown'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_search_console_sync_stores_properties_and_daily_metrics(): void
    {
        $connection = $this->connected();
        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://brathamatu.test/', 'permissionLevel' => 'siteOwner'],
                ],
            ]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [
                    ['keys' => ['2026-11-15'], 'clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 8.5],
                    ['keys' => ['2026-11-16'], 'clicks' => 20, 'impressions' => 500, 'ctr' => 0.04, 'position' => 7.2],
                ],
            ]),
        ]);

        $run = $this->runFor($connection, 'search_console');
        $this->service()->execute($run);

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(['properties' => 1, 'rows' => 2, 'window_days' => 28], $run->counts);
        $this->assertNotNull($run->finished_at);

        $property = GoogleSearchProperty::query()->firstOrFail();
        $this->assertSame('https://brathamatu.test/', $property->site_url);
        $this->assertSame(2, GoogleSearchMetric::query()->count());
        $this->assertSame(20, (int) GoogleSearchMetric::query()->orderByDesc('date')->value('clicks'));
        $this->assertSame(1, GoogleSearchMetric::query()->distinct()->count('dimension_hash'));

        $connection->refresh();
        $this->assertNotNull($connection->last_synced_at);
        $this->assertTrue(MarketingIntegrationLog::query()->where('event', 'google.sync_completed')->exists());
    }

    public function test_ga4_and_gtm_syncs_store_properties_daily_metrics_and_containers(): void
    {
        $connection = $this->connected();
        Http::fake([
            'analyticsadmin.googleapis.com/v1beta/accountSummaries*' => Http::response([
                'accountSummaries' => [
                    [
                        'displayName' => 'BRATHAMATU',
                        'propertySummaries' => [
                            ['property' => 'properties/123456', 'displayName' => 'Website'],
                        ],
                    ],
                ],
            ]),
            'analyticsdata.googleapis.com/v1beta/properties/*:runReport' => Http::response([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '20261116']],
                        'metricValues' => [['value' => '41'], ['value' => '35'], ['value' => '3']],
                    ],
                ],
            ]),
            'www.googleapis.com/tagmanager/v2/users/me/accounts*' => Http::response([
                'account' => [['accountId' => '6001']],
            ]),
            'www.googleapis.com/tagmanager/v2/accounts/6001/containers*' => Http::response([
                'container' => [
                    ['containerId' => '9001', 'publicId' => 'GTM-ABC123', 'name' => 'Website container'],
                ],
            ]),
        ]);

        $ga4Run = $this->runFor($connection, 'ga4');
        $this->service()->execute($ga4Run);
        $this->assertSame('success', $ga4Run->fresh()->status);
        $this->assertSame(1, Ga4Property::query()->count());
        $this->assertSame(41, (int) Ga4Metric::query()->value('sessions'));
        $this->assertSame(3.0, (float) Ga4Metric::query()->value('conversions'));

        $gtmRun = $this->runFor($connection, 'gtm');
        $this->service()->execute($gtmRun);
        $this->assertSame('success', $gtmRun->fresh()->status);
        $this->assertSame(1, GtmContainer::query()->count());
        $this->assertSame('GTM-ABC123', GtmContainer::query()->value('public_id'));
    }

    public function test_business_sync_stores_accounts_and_locations_and_records_reviews_as_unsupported(): void
    {
        $connection = $this->connected();
        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/v1/accounts*' => Http::response([
                'accounts' => [
                    ['name' => 'accounts/777', 'accountName' => 'BRATHAMATU', 'type' => 'PERSONAL', 'role' => 'OWNER'],
                ],
            ]),
            'mybusinessbusinessinformation.googleapis.com/v1/accounts/777/locations*' => Http::response([
                'locations' => [
                    [
                        'name' => 'locations/555',
                        'title' => 'BRATHAMATU Showroom',
                        'storefrontAddress' => ['addressLines' => ['1 Example Road']],
                        'phoneNumbers' => ['primaryPhone' => '+254700000000'],
                    ],
                ],
            ]),
        ]);

        $run = $this->runFor($connection, 'business');
        $this->service()->execute($run);

        $this->assertSame(['accounts' => 1, 'locations' => 1], $run->fresh()->counts);
        $this->assertSame(1, GoogleBusinessAccount::query()->count());
        $this->assertSame(1, GoogleBusinessLocation::query()->count());
        $this->assertTrue(MarketingIntegrationLog::query()->where('event', 'google.reviews.unsupported')->exists());
    }

    public function test_expired_connection_without_refresh_token_marks_the_connection_expired(): void
    {
        $connection = $this->connected();
        $connection->forceFill(['credentials' => array_merge($connection->credentials, [
            'refresh_token' => null,
            'expires_at' => Carbon::now()->subMinute()->toIso8601String(),
        ])])->save();
        Http::fake();

        $run = $this->runFor($connection, 'search_console');

        try {
            $this->service()->execute($run);
            $this->fail('Expected the expired connection to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_error);
        $this->assertTrue(MarketingIntegrationLog::query()->where('event', 'google.sync_failed')->exists());
    }

    public function test_google_401_during_a_sync_requires_reauthorisation(): void
    {
        $connection = $this->connected();
        Http::fake([
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['error' => ['message' => 'Invalid Credentials']], 401),
        ]);

        $run = $this->runFor($connection, 'search_console');

        try {
            $this->service()->execute($run);
            $this->fail('Expected the Google 401 to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reconnect', $e->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertStringContainsString('Reconnect', (string) $run->fresh()->error);
        $this->assertSame('expired', $connection->fresh()->status);
    }

    public function test_status_reports_synced_counts_and_does_not_claim_review_metrics(): void
    {
        $connection = $this->connected();
        GoogleSearchProperty::create([
            'connection_id' => $connection->id,
            'site_url' => 'https://brathamatu.test/',
            'permission_level' => 'siteOwner',
            'synced_at' => now(),
        ]);

        $this->as($this->admin)->getJson('/api/v1/platform/marketing/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.connection.status', 'connected')
            ->assertJsonPath('data.capabilities.reviews', false)
            ->assertJsonPath('data.capabilities.search_console', true)
            ->assertJsonPath('data.counts.search_properties', 1);
    }

    public function test_disconnect_revokes_the_token_and_clears_synced_records(): void
    {
        $connection = $this->connected();
        GoogleSearchProperty::create([
            'connection_id' => $connection->id,
            'site_url' => 'https://brathamatu.test/',
            'synced_at' => now(),
        ]);
        $account = GoogleBusinessAccount::create([
            'connection_id' => $connection->id,
            'external_id' => 'accounts/777',
            'name' => 'BRATHAMATU',
            'synced_at' => now(),
        ]);
        GoogleBusinessLocation::create([
            'connection_id' => $connection->id,
            'account_id' => $account->id,
            'external_id' => 'locations/555',
            'name' => 'BRATHAMATU Showroom',
            'synced_at' => now(),
        ]);
        Ga4Property::create([
            'connection_id' => $connection->id,
            'property_id' => '123456',
            'display_name' => 'Website',
            'synced_at' => now(),
        ]);
        GtmContainer::create([
            'connection_id' => $connection->id,
            'container_id' => '9001',
            'name' => 'Website container',
        ]);

        Http::fake(['oauth2.googleapis.com/revoke' => Http::response([], 200)]);

        $this->as($this->admin)->deleteJson('/api/v1/platform/marketing/integrations/google')
            ->assertOk()
            ->assertJsonPath('data.disconnected', true)
            ->assertJsonPath('data.status.connection.status', 'revoked')
            ->assertJsonPath('data.status.counts.search_properties', 0);

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNull($connection->credentials);
        $this->assertFalse($connection->isConnected());
        $this->assertSame(0, GoogleSearchProperty::query()->count());
        $this->assertSame(0, GoogleBusinessAccount::query()->count());
        $this->assertSame(0, GoogleBusinessLocation::query()->count());
        $this->assertSame(0, Ga4Property::query()->count());
        $this->assertSame(0, GtmContainer::query()->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/revoke'));
    }

    public function test_tenant_roles_cannot_use_google_integration_apis(): void
    {
        $tenant = $this->userWithRole('business-owner');

        $this->as($tenant)->getJson('/api/v1/platform/marketing/integrations/google')->assertForbidden();
        $this->as($tenant)->getJson('/api/v1/platform/marketing/integrations/google/connect')->assertForbidden();
        $this->as($tenant)->postJson('/api/v1/platform/marketing/integrations/google/sync', ['type' => 'all'])->assertForbidden();
        $this->as($tenant)->deleteJson('/api/v1/platform/marketing/integrations/google')->assertForbidden();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function connected(array $overrides = []): OAuthConnection
    {
        return OAuthConnection::create(array_merge([
            'provider' => 'google',
            'account_label' => 'owner@brathamatu.test',
            'credentials' => [
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_at' => Carbon::now()->addHour()->toIso8601String(),
                'token_type' => 'Bearer',
            ],
            'scopes' => config('services.google.scopes'),
            'status' => 'connected',
            'connected_by' => $this->admin->id,
        ], $overrides));
    }

    private function runFor(OAuthConnection $connection, string $type): MarketingIntegrationSyncRun
    {
        return MarketingIntegrationSyncRun::create([
            'connection_id' => $connection->id,
            'provider' => 'google',
            'type' => $type,
            'status' => 'queued',
        ]);
    }

    private function service(): GoogleIntegrationService
    {
        return app(GoogleIntegrationService::class);
    }
}
