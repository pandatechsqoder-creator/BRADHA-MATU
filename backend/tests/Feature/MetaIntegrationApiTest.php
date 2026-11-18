<?php

namespace Tests\Feature;

use App\Jobs\SyncMarketingMeta;
use App\Models\Marketing\MarketingIntegrationSyncRun;
use App\Models\Marketing\MetaAdAccount;
use App\Models\Marketing\MetaCampaignRecord;
use App\Models\Marketing\MetaInstagramAccount;
use App\Models\Marketing\MetaPage;
use App\Models\Marketing\OAuthConnection;
use App\Models\Marketing\SocialPost;
use App\Models\User;
use App\Services\Marketing\Meta\MetaIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MetaIntegrationApiTest extends TestCase
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
            'services.meta.app_id' => 'meta-app-id',
            'services.meta.app_secret' => 'meta-app-secret',
            'services.meta.redirect_uri' => 'http://localhost/api/v1/platform/marketing/integrations/meta/callback',
            'services.meta.graph_version' => 'v21.0',
            'services.meta.scopes' => ['pages_show_list', 'pages_manage_posts', 'ads_read'],
            'services.meta.ad_account_id' => null,
        ]);
    }

    public function test_unconfigured_meta_reports_honest_state_and_cannot_start_oauth(): void
    {
        config(['services.meta.app_id' => null, 'services.meta.app_secret' => null]);

        $this->as($this->admin)->getJson('/api/v1/platform/marketing/integrations/meta')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.connection', null)
            ->assertJsonPath('data.capabilities.spend_actions', false);

        $this->as($this->admin)->getJson('/api/v1/platform/marketing/integrations/meta/connect')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'META_NOT_CONFIGURED');
    }

    public function test_connect_issues_facebook_oauth_url_and_callback_stores_encrypted_long_lived_token(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response([
                'access_token' => 'secret-long-lived-token',
                'expires_in' => 5184000,
                'token_type' => 'bearer',
            ]),
            'graph.facebook.com/*/me*' => Http::response([
                'id' => '1001',
                'name' => 'BRATHAMATU Page Admin',
                'email' => 'owner@brathamatu.test',
            ]),
        ]);

        $url = $this->as($this->admin)
            ->getJson('/api/v1/platform/marketing/integrations/meta/connect')
            ->assertOk()
            ->json('data.authorization_url');

        $this->assertStringStartsWith('https://www.facebook.com/v21.0/dialog/oauth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('meta-app-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('pages_show_list,pages_manage_posts,ads_read', $query['scope']);
        $this->assertNotSame('', $query['state']);

        $this->get('/api/v1/platform/marketing/integrations/meta/callback?code=auth-code&state='.urlencode($query['state']))
            ->assertRedirect('http://app.test/platform/marketing/integrations/meta?meta=connected');

        $connection = OAuthConnection::query()->where('provider', 'meta')->firstOrFail();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('BRATHAMATU Page Admin', $connection->account_label);
        $this->assertSame($this->admin->id, $connection->connected_by);
        $this->assertTrue($connection->isConnected());

        $raw = (string) DB::table('oauth_connections')->where('id', $connection->id)->value('credentials');
        $this->assertStringNotContainsString('secret-long-lived-token', $raw);

        $this->get('/api/v1/platform/marketing/integrations/meta/callback?code=auth-code&state='.urlencode($query['state']))
            ->assertRedirect('http://app.test/platform/marketing/integrations/meta?meta=error&reason=meta_oauth_state_invalid');
    }

    public function test_callback_denied_redirects_with_error_flag(): void
    {
        $this->get('/api/v1/platform/marketing/integrations/meta/callback?error=access_denied')
            ->assertRedirect('http://app.test/platform/marketing/integrations/meta?meta=error&reason=denied');

        $this->assertSame(0, OAuthConnection::query()->count());
    }

    public function test_sync_requires_connection_and_queues_runs(): void
    {
        Queue::fake();

        $this->as($this->admin)->postJson('/api/v1/platform/marketing/integrations/meta/sync', ['type' => 'all'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'META_NOT_CONNECTED');

        $this->connected();

        $this->as($this->admin)->postJson('/api/v1/platform/marketing/integrations/meta/sync', ['type' => 'all'])
            ->assertStatus(202)
            ->assertJsonCount(3, 'data.runs');

        $this->assertSame(3, MarketingIntegrationSyncRun::query()->where('provider', 'meta')->count());
        Queue::assertPushed(SyncMarketingMeta::class, 3);
    }

    public function test_asset_sync_stores_pages_instagram_and_ad_accounts_with_encrypted_page_token(): void
    {
        $connection = $this->connected();

        Http::fake([
            'graph.facebook.com/*/me/businesses*' => Http::response(['data' => [
                ['id' => 'b1', 'name' => 'BRATHAMATU Ltd'],
            ]]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                [
                    'id' => 'page-1',
                    'name' => 'BRATHAMATU',
                    'category' => 'Business service',
                    'access_token' => 'page-access-token',
                    'instagram_business_account' => ['id' => 'ig-1', 'username' => 'brathamatu'],
                ],
            ]]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_1', 'name' => 'BRATHAMATU Ads', 'currency' => 'GBP', 'account_status' => '1', 'spend_cap' => '50000'],
            ]]),
        ]);

        $run = $this->runFor($connection, 'assets');
        $this->service()->execute($run);

        $run->refresh();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->counts['pages']);
        $this->assertSame(1, $run->counts['instagram_accounts']);
        $this->assertSame(1, $run->counts['businesses']);

        $page = MetaPage::query()->where('external_id', 'page-1')->firstOrFail();
        $this->assertSame('BRATHAMATU', $page->name);
        $this->assertSame('page-access-token', $page->page_token);

        $raw = (string) DB::table('meta_pages')->where('id', $page->id)->value('page_token');
        $this->assertStringNotContainsString('page-access-token', $raw);

        $this->assertSame(1, MetaInstagramAccount::query()->where('external_id', 'ig-1')->count());
        $this->assertSame(1, MetaAdAccount::query()->where('external_id', 'act_1')->count());
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_synced_at);
    }

    public function test_ads_sync_converts_budget_cents_and_stores_spend(): void
    {
        $connection = $this->connected();
        MetaAdAccount::create([
            'connection_id' => $connection->id,
            'external_id' => 'act_1',
            'name' => 'BRATHAMATU Ads',
            'currency' => 'GBP',
            'status' => '1',
        ]);

        Http::fake([
            'graph.facebook.com/*/act_1/campaigns*' => Http::response(['data' => [
                ['id' => 'c1', 'name' => 'Spring retail', 'status' => 'ACTIVE', 'daily_budget' => '5000'],
            ]]),
            'graph.facebook.com/*/act_1/insights*' => Http::response(['data' => [
                [
                    'campaign_id' => 'c1',
                    'campaign_name' => 'Spring retail',
                    'spend' => '12.50',
                    'impressions' => '1000',
                    'clicks' => '25',
                    'actions' => [['action_type' => 'lead', 'value' => '2']],
                ],
            ]]),
        ]);

        $this->service()->execute($this->runFor($connection, 'ads'));

        $campaign = MetaCampaignRecord::query()->where('external_id', 'c1')->firstOrFail();
        $this->assertSame('Spring retail', $campaign->name);
        $this->assertEquals(50.0, (float) $campaign->budget_amount);
        $this->assertEquals(12.5, (float) $campaign->spend_amount);
        $this->assertSame(1000, (int) $campaign->impressions);
        $this->assertSame(25, (int) $campaign->clicks);
    }

    public function test_insights_sync_stores_page_metrics(): void
    {
        $connection = $this->connected();
        MetaPage::create([
            'connection_id' => $connection->id,
            'external_id' => 'page-1',
            'name' => 'BRATHAMATU',
            'page_token' => 'page-access-token',
            'status' => 'active',
        ]);

        Http::fake([
            'graph.facebook.com/*/page-1/insights*' => Http::response(['data' => [
                ['name' => 'page_impressions', 'values' => [['value' => 1200]]],
                ['name' => 'page_post_engagements', 'values' => [['value' => 45]]],
            ]]),
        ]);

        $this->service()->execute($this->runFor($connection, 'insights'));

        $page = MetaPage::query()->where('external_id', 'page-1')->firstOrFail();
        $this->assertSame(1200, $page->insights['page_impressions'][0]['value']);
    }

    public function test_rejected_token_marks_connection_expired_and_run_failed(): void
    {
        $connection = $this->connected();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Session has expired']], 401),
        ]);

        $run = $this->runFor($connection, 'assets');

        try {
            $this->service()->execute($run);
            $this->fail('Expected the sync to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Reconnect', $e->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('expired', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_error);
    }

    public function test_disconnect_revokes_connection_and_clears_assets_but_keeps_posts(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        $connection = $this->connected();
        $page = MetaPage::create([
            'connection_id' => $connection->id,
            'external_id' => 'page-1',
            'name' => 'BRATHAMATU',
            'page_token' => 'page-access-token',
            'status' => 'active',
        ]);
        SocialPost::create([
            'meta_page_id' => $page->id,
            'caption' => 'Draft retained',
            'status' => 'draft',
            'created_by' => $this->admin->id,
        ]);

        $this->as($this->admin)->deleteJson('/api/v1/platform/marketing/integrations/meta')
            ->assertOk()
            ->assertJsonPath('data.disconnected', true);

        $connection->refresh();
        $this->assertSame('revoked', $connection->status);
        $this->assertNull($connection->credentials);
        $this->assertSame(0, MetaPage::query()->count());
        $this->assertSame(1, SocialPost::query()->count());
    }

    public function test_tenant_roles_cannot_access_meta_endpoints(): void
    {
        $tenant = $this->userWithRole('business-owner');

        $this->as($tenant)->getJson('/api/v1/platform/marketing/integrations/meta')->assertForbidden();
        $this->as($tenant)->getJson('/api/v1/platform/marketing/integrations/meta/connect')->assertForbidden();
        $this->as($tenant)->postJson('/api/v1/platform/marketing/integrations/meta/sync', ['type' => 'all'])->assertForbidden();
        $this->as($tenant)->deleteJson('/api/v1/platform/marketing/integrations/meta')->assertForbidden();
    }

    private function connected(): OAuthConnection
    {
        return OAuthConnection::create([
            'provider' => 'meta',
            'account_label' => 'BRATHAMATU Page Admin',
            'credentials' => [
                'access_token' => 'long-lived-token',
                'expires_at' => now()->addDays(50)->toIso8601String(),
                'token_type' => 'bearer',
            ],
            'scopes' => ['pages_show_list'],
            'status' => 'connected',
            'connected_by' => $this->admin->id,
        ]);
    }

    private function runFor(OAuthConnection $connection, string $type): MarketingIntegrationSyncRun
    {
        return MarketingIntegrationSyncRun::create([
            'connection_id' => $connection->id,
            'provider' => 'meta',
            'type' => $type,
            'status' => 'queued',
        ]);
    }

    private function service(): MetaIntegrationService
    {
        return $this->app->make(MetaIntegrationService::class);
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
}
