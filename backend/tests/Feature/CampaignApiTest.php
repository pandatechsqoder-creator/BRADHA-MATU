<?php

namespace Tests\Feature;

use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MarketingCampaign;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private CmsPage $landingPage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->manager = $this->userWithRole('marketing-manager');

        $this->landingPage = CmsPage::create([
            'slug' => 'stock-taking-services',
            'title' => 'Stock-taking services',
            'type' => 'page',
            'template' => 'public.page',
            'status' => 'published',
            'published_at' => now(),
            'noindex' => false,
            'requires_auth' => false,
        ]);
    }

    public function test_campaign_is_created_as_draft_with_utm_and_landing_page(): void
    {
        $payload = $this->payload();

        $response = $this->as($this->manager)
            ->postJson('/api/v1/platform/marketing/campaigns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.landing_page.slug', 'stock-taking-services')
            ->assertJsonPath('data.owner', $this->manager->name)
            ->assertJsonPath('data.budget_amount', 1500);

        $campaign = MarketingCampaign::query()->firstOrFail();
        $this->assertSame('draft', $campaign->status);
        $this->assertSame(1, $campaign->revisions()->count());
        $this->assertStringContainsString('utm_source=google', $response->json('data.utm_url'));
        $this->assertStringContainsString('utm_campaign=autumn-stocktake', $response->json('data.utm_url'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.campaign.created']);
    }

    public function test_submit_requires_budget_and_start_date(): void
    {
        $id = $this->createCampaign(['budget_amount' => null]);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAMPAIGN_BUDGET_REQUIRED');

        $this->as($this->manager)->patchJson("/api/v1/platform/marketing/campaigns/{$id}", ['budget_amount' => 800])
            ->assertOk();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")
            ->assertOk()
            ->assertJsonPath('data.status', 'review');
    }

    public function test_full_approval_and_activation_workflow_with_separation_of_duties(): void
    {
        $id = $this->createCampaign();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAMPAIGN_APPROVAL_CONFLICT');

        $approver = $this->userWithRole('content-manager');
        $this->as($approver)->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve", ['notes' => 'Budget approved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approver', $approver->name);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/pause")
            ->assertOk()
            ->assertJsonPath('data.status', 'paused');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/resume")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $detail = $this->as($this->manager)->getJson("/api/v1/platform/marketing/campaigns/{$id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('approved', $detail['approvals'][0]['decision']);
        $this->assertSame('Campaign completed', $detail['revisions'][0]['change_summary']);
        $this->assertContains('Campaign approved', array_column($detail['revisions'], 'change_summary'));
        $this->assertGreaterThanOrEqual(6, count($detail['revisions']));
    }

    public function test_activation_requires_approval_and_spend_permission(): void
    {
        $id = $this->createCampaign();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAMPAIGN_APPROVAL_REQUIRED');

        $editor = $this->userWithRole('content-editor');

        $this->as($editor)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();
        $this->as($this->userWithRole('content-manager'))->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")->assertOk();

        $this->as($editor)->postJson("/api/v1/platform/marketing/campaigns/{$id}/activate")->assertForbidden();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/activate")->assertOk();
    }

    public function test_approved_campaign_is_locked_and_rejection_returns_it_to_draft(): void
    {
        $id = $this->createCampaign();
        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();

        $approver = $this->userWithRole('content-manager');
        $this->as($approver)->postJson("/api/v1/platform/marketing/campaigns/{$id}/reject")
            ->assertStatus(422);
        $this->as($approver)->postJson("/api/v1/platform/marketing/campaigns/{$id}/reject", ['notes' => 'Budget too high'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->as($this->manager)->patchJson("/api/v1/platform/marketing/campaigns/{$id}", ['budget_amount' => 900])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.approver', null);

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();
        $this->as($approver)->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")->assertOk();

        $this->as($this->manager)->patchJson("/api/v1/platform/marketing/campaigns/{$id}", ['budget_amount' => 2000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAMPAIGN_LOCKED');
    }

    public function test_creative_is_locked_once_approved(): void
    {
        $id = $this->createCampaign();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/assets", [
            'type' => 'copy',
            'content' => ['headline' => 'Count every shelf', 'body' => 'Book a stock take'],
        ])->assertCreated()->assertJsonPath('data.assets.0.type', 'copy');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();
        $this->as($this->userWithRole('content-manager'))->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")->assertOk();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/assets", [
            'type' => 'copy',
            'content' => ['headline' => 'Late addition'],
        ])->assertStatus(422)->assertJsonPath('error.code', 'CAMPAIGN_LOCKED');
    }

    public function test_results_are_recorded_only_for_live_campaigns(): void
    {
        $id = $this->createCampaign();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/results", [
            'results' => ['leads' => 12],
        ])->assertStatus(422)->assertJsonPath('error.code', 'CAMPAIGN_STATUS_INVALID');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();
        $this->as($this->userWithRole('content-manager'))->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")->assertOk();
        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/activate")->assertOk();

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/results", [
            'results' => ['leads' => 12, 'conversions' => 4, 'spend' => 250.5],
        ])->assertOk()
            ->assertJsonPath('data.results.leads', 12)
            ->assertJsonPath('data.results.spend', 250.5);
    }

    public function test_list_filters_and_counts(): void
    {
        $this->createCampaign();
        $this->createCampaign(['name' => 'Email retention push', 'channel' => 'email']);

        $this->as($this->manager)->getJson('/api/v1/platform/marketing/campaigns?channel=email')
            ->assertOk()
            ->assertJsonPath('data.counts.draft', 2)
            ->assertJsonCount(1, 'data.campaigns')
            ->assertJsonPath('data.campaigns.0.channel', 'email');
    }

    public function test_approval_requires_permission_and_tenants_are_forbidden(): void
    {
        $id = $this->createCampaign();
        $this->as($this->manager)->postJson("/api/v1/platform/marketing/campaigns/{$id}/submit-review")->assertOk();

        $this->as($this->userWithRole('lead-agent'))
            ->postJson("/api/v1/platform/marketing/campaigns/{$id}/approve")
            ->assertForbidden();

        $this->as($this->userWithRole('business-owner'))
            ->getJson('/api/v1/platform/marketing/campaigns')
            ->assertForbidden();

        $this->as($this->userWithRole('content-editor'))
            ->getJson('/api/v1/platform/marketing/campaigns')
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Autumn stock take campaign',
            'objective' => 'stocktake_requests',
            'channel' => 'google_ads',
            'audience' => ['summary' => 'Independent retailers in Nairobi', 'segments' => ['retail', 'supermarket']],
            'budget_amount' => 1500,
            'budget_currency' => 'GBP',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeeks(5)->toIso8601String(),
            'landing_page_id' => $this->landingPage->uuid,
            'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'autumn-stocktake'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCampaign(array $overrides = []): string
    {
        return $this->as($this->manager)
            ->postJson('/api/v1/platform/marketing/campaigns', $this->payload($overrides))
            ->assertCreated()
            ->json('data.id');
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
