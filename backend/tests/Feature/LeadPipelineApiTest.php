<?php

namespace Tests\Feature;

use App\Models\Marketing\LeadExport;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class LeadPipelineApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_list_and_filter_leads(): void
    {
        $admin = $this->platformUser('super-admin');
        MarketingLead::create($this->leadAttributes('quote', 'Alpha Retail'));
        MarketingLead::create($this->leadAttributes('contact', 'Beta Traders'));

        $this->as($admin)
            ->getJson('/api/v1/platform/marketing/leads?type=contact')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.leads.0.business_name', 'Beta Traders')
            ->assertJsonPath('data.pii_masked', false)
            ->assertJsonPath('data.type_counts.quote', 1);
    }

    public function test_pii_is_masked_in_lists_for_users_without_export_permission(): void
    {
        $admin = $this->platformUser('super-admin');
        MarketingLead::create($this->leadAttributes('quote', 'Alpha Retail', email: 'amina@example.test', phone: '0712345678'));

        $editor = $this->platformUser('content-editor');

        $this->as($editor)
            ->getJson('/api/v1/platform/marketing/leads')
            ->assertOk()
            ->assertJsonPath('data.pii_masked', true)
            ->assertJsonPath('data.leads.0.email', 'a****@example.test')
            ->assertJsonPath('data.leads.0.phone', '***678');

        $this->as($admin)
            ->getJson('/api/v1/platform/marketing/leads')
            ->assertOk()
            ->assertJsonPath('data.leads.0.email', 'amina@example.test');
    }

    public function test_assigning_a_lead_records_history_and_notifies_the_assignee(): void
    {
        $admin = $this->platformUser('super-admin');
        $agent = $this->platformUser('lead-agent');
        $lead = MarketingLead::create($this->leadAttributes('quote', 'Gamma Stores'));

        $this->as($admin)
            ->postJson("/api/v1/platform/marketing/leads/{$lead->uuid}/assign", [
                'assigned_to' => $agent->uuid,
                'reason' => 'Retail quotation follow-up',
            ])
            ->assertOk()
            ->assertJsonPath('data.lead.status', 'assigned')
            ->assertJsonPath('data.lead.assigned_to.name', $agent->name);

        $this->assertDatabaseHas('lead_assignments', [
            'lead_id' => $lead->id,
            'assigned_to' => $agent->id,
            'reason' => 'Retail quotation follow-up',
        ]);
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'assignment',
        ]);
        $this->assertSame(1, Notification::query()->where('user_id', $agent->id)->count());

        $this->as($admin)
            ->getJson("/api/v1/platform/marketing/leads/{$lead->uuid}")
            ->assertOk()
            ->assertJsonPath('data.lead.assignments.0.assigned_to.name', $agent->name);
    }

    public function test_status_changes_and_notes_are_recorded_as_activities(): void
    {
        $admin = $this->platformUser('super-admin');
        $lead = MarketingLead::create($this->leadAttributes('quote', 'Delta Foods'));

        $this->as($admin)
            ->postJson("/api/v1/platform/marketing/leads/{$lead->uuid}/status", ['status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.lead.status', 'contacted');

        $this->as($admin)
            ->postJson("/api/v1/platform/marketing/leads/{$lead->uuid}/activities", [
                'type' => 'call',
                'body' => 'Discussed branch locations and preferred dates.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.activity.type', 'call');

        $lead->refresh();
        $this->assertNotNull($lead->last_activity_at);
        $this->assertSame(2, $lead->activities()->count());

        $this->as($admin)
            ->postJson("/api/v1/platform/marketing/leads/{$lead->uuid}/status", ['status' => 'won'])
            ->assertOk();
        $this->assertNotNull($lead->fresh()->converted_at);
    }

    public function test_consent_can_be_recorded_and_withdrawn(): void
    {
        $admin = $this->platformUser('super-admin');
        $lead = MarketingLead::create($this->leadAttributes('quote', 'Epsilon Pharmacy'));

        $this->as($admin)
            ->postJson("/api/v1/platform/marketing/leads/{$lead->uuid}/consents", [
                'channel' => 'marketing',
                'status' => 'withdrawn',
            ])
            ->assertOk()
            ->assertJsonPath('data.lead.consent_marketing', false)
            ->assertJsonPath('data.lead.consents.0.status', 'withdrawn');

        $this->assertSame(1, MarketingConsent::query()->where('subject_id', $lead->id)->count());
    }

    public function test_export_requires_leads_export_permission_and_is_audited(): void
    {
        $editor = $this->platformUser('content-editor');
        $manager = $this->platformUser('content-manager');
        MarketingLead::create($this->leadAttributes('quote', 'Zeta Wholesale'));

        $this->as($editor)
            ->getJson('/api/v1/platform/marketing/leads/export')
            ->assertForbidden();

        $response = $this->as($manager)->get('/api/v1/platform/marketing/leads/export');
        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('Reference,Created,Type,Status,Business', $content);
        $this->assertStringContainsString('Zeta Wholesale', $content);
        $this->assertSame(1, LeadExport::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.leads.exported']);
    }

    public function test_export_applies_the_same_filters_as_the_pipeline_list(): void
    {
        $manager = $this->platformUser('content-manager');
        MarketingLead::create($this->leadAttributes('quote', 'Export Match Ltd'));
        MarketingLead::create($this->leadAttributes('contact', 'Export Other Ltd'));

        $content = $this->as($manager)
            ->get('/api/v1/platform/marketing/leads/export?search=Export%20Match')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Export Match Ltd', $content);
        $this->assertStringNotContainsString('Export Other Ltd', $content);

        $filtered = $this->as($manager)
            ->get('/api/v1/platform/marketing/leads/export?status=lost')
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Export Match Ltd', $filtered);
    }

    public function test_export_is_rate_limited_per_user(): void
    {
        $manager = $this->platformUser('content-manager');
        RateLimiter::clear('marketing-export:'.$manager->id);

        for ($i = 0; $i < 6; $i++) {
            $this->as($manager)->get('/api/v1/platform/marketing/leads/export')->assertOk();
        }

        $this->as($manager)->getJson('/api/v1/platform/marketing/leads/export')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    public function test_tenant_users_and_unpermitted_roles_cannot_reach_the_pipeline(): void
    {
        $tenantUser = $this->platformUser('business-owner');
        $seo = $this->platformUser('seo-manager');
        $lead = MarketingLead::create($this->leadAttributes('contact', 'Theta Ltd'));

        $this->as($tenantUser)
            ->getJson('/api/v1/platform/marketing/leads')
            ->assertForbidden();

        $this->as($seo)
            ->getJson('/api/v1/platform/marketing/leads')
            ->assertOk();

        $this->as($seo)
            ->patchJson("/api/v1/platform/marketing/leads/{$lead->uuid}", ['status' => 'contacted'])
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function leadAttributes(string $type, string $business, ?string $email = null, ?string $phone = null): array
    {
        return [
            'reference' => 'LD-TEST-'.strtoupper(substr(md5($business.microtime()), 0, 6)),
            'submission_token' => hash('sha256', $business.microtime()),
            'type' => $type,
            'status' => 'new',
            'business_name' => $business,
            'contact_name' => 'Contact '.$business,
            'email' => $email ?? strtolower(str_replace(' ', '', $business)).'@example.test',
            'phone' => $phone ?? '0700000000',
            'source' => 'website',
            'consent_transactional' => true,
            'consent_at' => now(),
            'last_activity_at' => now(),
        ];
    }

    private function platformUser(string $role): User
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
