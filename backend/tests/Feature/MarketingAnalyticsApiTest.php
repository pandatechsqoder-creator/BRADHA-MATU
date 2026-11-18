<?php

namespace Tests\Feature;

use App\Models\Marketing\AnalyticsEvent;
use App\Models\Marketing\ConversionEvent;
use App\Models\Marketing\MarketingCampaign;
use App\Models\Marketing\MarketingLead;
use App\Models\Marketing\TrackingPreference;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private MarketingCampaign $campaign;

    private MarketingLead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->manager = $this->userWithRole('marketing-manager');

        $this->campaign = MarketingCampaign::create([
            'name' => 'Demo Launch',
            'objective' => 'lead_generation',
            'channel' => 'website',
            'status' => 'active',
            'budget_amount' => 5000,
            'budget_currency' => 'KES',
            'utm' => ['source' => 'demo', 'medium' => 'website', 'campaign' => 'demo-launch'],
            'results' => ['reach' => 1200, 'impressions' => 4000, 'clicks' => 80, 'leads' => 2, 'conversions' => 1, 'spend' => 1000],
        ]);

        $this->lead = MarketingLead::create([
            'reference' => 'LEAD-TEST-1',
            'submission_token' => 'test-token-1',
            'type' => 'quote',
            'status' => 'qualified',
            'contact_name' => 'Test Lead',
            'email' => 'lead@example.test',
            'consent_transactional' => true,
            'consent_at' => now(),
            'last_activity_at' => now(),
        ]);

        TrackingPreference::create(['session_id' => 'session-aaa', 'analytics' => true]);
        TrackingPreference::create(['session_id' => 'session-bbb', 'analytics' => false]);

        foreach ([['session-aaa', '/'], ['session-aaa', '/pos'], ['session-bbb', '/']] as [$session, $path]) {
            AnalyticsEvent::create([
                'event_name' => 'page_view',
                'occurred_at' => now(),
                'session_id' => $session,
                'campaign_id' => $this->campaign->id,
                'path' => $path,
                'utm' => ['utm_source' => 'demo', 'utm_campaign' => 'demo-launch'],
                'device' => 'desktop',
                'consent_state' => ['analytics' => true],
                'created_at' => now(),
            ]);
        }

        ConversionEvent::create([
            'event_name' => 'quote_request',
            'lead_id' => $this->lead->id,
            'campaign_id' => $this->campaign->id,
            'occurred_at' => now(),
            'consent_state' => ['analytics' => true, 'basis' => 'form_submission'],
            'metadata' => ['form' => 'quote', 'path' => '/'],
            'created_at' => now(),
        ]);
    }

    public function test_overview_reports_consented_sessions_conversions_and_consent_coverage(): void
    {
        $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/overview?days=30')
            ->assertOk()
            ->assertJsonPath('data.sessions', 2)
            ->assertJsonPath('data.page_views', 3)
            ->assertJsonPath('data.conversions', 1)
            ->assertJsonPath('data.lead_conversions', 1)
            ->assertJsonPath('data.consent.decisions', 2)
            ->assertJsonPath('data.consent.analytics_granted', 1)
            ->assertJsonPath('data.consent.necessary_only', 1)
            ->assertJsonPath('data.consent.unconsented_excluded', true)
            ->assertJsonCount(30, 'data.daily');
    }

    public function test_funnel_matches_lead_pipeline_and_form_conversions(): void
    {
        $response = $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/funnel')
            ->assertOk()
            ->assertJsonPath('data.leads', 1)
            ->assertJsonPath('data.converted_leads', 1)
            ->assertJsonPath('data.reconciled', true);

        $stages = collect($response->json('data.stages'))->keyBy('status');
        $this->assertSame(1, $stages['qualified']['count']);
        $this->assertSame(0, $stages['won']['count']);
        $this->assertSame('quote_request', $response->json('data.conversion_events.0.event'));
    }

    public function test_campaign_and_landing_page_reports_use_consented_data(): void
    {
        $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/campaigns')
            ->assertOk()
            ->assertJsonPath('data.campaigns.0.name', 'Demo Launch')
            ->assertJsonPath('data.campaigns.0.analytics_sessions', 2)
            ->assertJsonPath('data.campaigns.0.analytics_conversions', 1)
            ->assertJsonPath('data.campaigns.0.recorded_results.leads', 2)
            ->assertJsonPath('data.campaigns.0.cost_per_lead', 500);

        $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/landing-pages')
            ->assertOk()
            ->assertJsonPath('data.pages.0.path', '/')
            ->assertJsonPath('data.pages.0.views', 2)
            ->assertJsonPath('data.pages.0.conversions', 1);
    }

    public function test_search_report_is_honest_when_google_has_no_data(): void
    {
        $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/search')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.reason', 'NO_SEARCH_DATA')
            ->assertJsonPath('data.clicks', 0);
    }

    public function test_campaign_performance_endpoint_combines_recorded_and_analytics_data(): void
    {
        $this->as($this->manager)->getJson("/api/v1/platform/marketing/campaigns/{$this->campaign->uuid}/performance")
            ->assertOk()
            ->assertJsonPath('data.campaign.utm_campaign', 'demo-launch')
            ->assertJsonPath('data.events', 3)
            ->assertJsonPath('data.sessions', 2)
            ->assertJsonPath('data.conversions', 1)
            ->assertJsonPath('data.recorded_results.leads', 2);
    }

    public function test_recent_events_are_sanitized_and_limited(): void
    {
        $this->as($this->manager)->getJson('/api/v1/platform/marketing/analytics/events')
            ->assertOk()
            ->assertJsonCount(3, 'data.events')
            ->assertJsonPath('data.events.0.event', 'page_view');
    }

    public function test_analytics_permission_and_tenant_isolation(): void
    {
        $editor = $this->userWithRole('content-editor');
        $this->as($editor)->getJson('/api/v1/platform/marketing/analytics/overview')->assertForbidden();

        $tenant = $this->userWithRole('business-owner');
        $this->as($tenant)->getJson('/api/v1/platform/marketing/analytics/overview')->assertForbidden();
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
