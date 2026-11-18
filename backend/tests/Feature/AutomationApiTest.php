<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Marketing\AutomationRun;
use App\Models\Marketing\AutomationStep;
use App\Models\Marketing\AutomationWorkflow;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\Notification;
use App\Models\User;
use App\Services\Marketing\AutomationService;
use App\Services\Marketing\LeadService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        RateLimiter::clear('form-ip:127.0.0.1');
        $this->manager = $this->userWithRole('marketing-manager');
    }

    public function test_catalogue_workflow_crud_and_activation(): void
    {
        $catalogue = $this->as($this->manager)->getJson('/api/v1/platform/marketing/workflows')->assertOk()
            ->assertJsonPath('data.settings.enabled', true)
            ->assertJsonPath('data.settings.outbound_provider.approved', false)
            ->assertJsonCount(3, 'data.settings.triggers')
            ->assertJsonCount(4, 'data.settings.step_types')
            ->json('data.settings');

        $this->assertSame('automation.enabled', $catalogue['kill_switch']['key']);

        $created = $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.workflow.status', 'draft')
            ->assertJsonPath('data.workflow.trigger', 'lead.created')
            ->json('data.workflow');

        $id = $created['id'];

        $this->as($this->manager)->patchJson("/api/v1/platform/marketing/workflows/{$id}", [
            'name' => 'Renamed follow-up',
            'steps' => [['type' => 'wait', 'config' => ['minutes' => 5]]],
        ])->assertOk()
            ->assertJsonPath('data.workflow.name', 'Renamed follow-up')
            ->assertJsonPath('data.workflow.steps.0.type', 'wait');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/workflows/{$id}/activate")
            ->assertOk()
            ->assertJsonPath('data.workflow.status', 'active');

        $this->as($this->manager)->patchJson("/api/v1/platform/marketing/workflows/{$id}", ['name' => 'Blocked edit'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AUTOMATION_LOCKED');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/workflows/{$id}/pause")
            ->assertOk()
            ->assertJsonPath('data.workflow.status', 'paused');
    }

    public function test_validation_rejects_bad_triggers_and_step_configs(): void
    {
        $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload([
            'trigger' => 'lead.deleted',
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['trigger']]]);

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload([
            'steps' => [['type' => 'wait', 'config' => ['minutes' => 0]]],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['steps.0.config.minutes']]]);

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload([
            'steps' => [['type' => 'notify_external', 'config' => ['channel' => 'fax', 'title' => 'Hello']]],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['steps.0.config.channel']]]);

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload([
            'steps' => [['type' => 'notify_internal', 'config' => ['recipients' => 'lead_managers', 'title' => '']]],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['steps.0.config.title']]]);

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $this->payload([
            'steps' => [],
        ]))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['steps']]]);
    }

    public function test_public_lead_capture_runs_active_workflow_and_notifies_the_team(): void
    {
        $this->activateWorkflow($this->payload());

        $this->postJson('/api/v1/public/forms/quote', [
            'contact_name' => 'Grace Wambui',
            'business_name' => 'Wambui Traders',
            'email' => 'grace@automation.test',
            'consent_transactional' => true,
            'utm_campaign' => 'automation-e2e',
        ])->assertCreated();

        $lead = MarketingLead::query()->where('email', 'grace@automation.test')->firstOrFail();
        $run = AutomationRun::query()->where('lead_id', $lead->id)->firstOrFail();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->steps()->where('status', 'executed')->count());

        $first = $run->steps()->where('position', 1)->firstOrFail();
        $this->assertSame('in_app', $first->output['channel']);
        $this->assertGreaterThanOrEqual(1, $first->output['recipient_count']);

        $notified = Notification::query()
            ->where('user_id', $this->manager->id)
            ->get()
            ->contains(fn (Notification $notification) => ($notification->data['run_id'] ?? null) === $run->uuid);
        $this->assertTrue($notified);
        $this->assertStringContainsString($lead->reference, (string) Notification::query()->where('user_id', $this->manager->id)->value('title'));

        $activity = $lead->activities()->where('metadata->run_id', $run->uuid)->first();
        $this->assertNotNull($activity);
        $this->assertStringContainsString('Follow up with Grace Wambui', (string) $activity->body);
    }

    public function test_conditions_limit_workflows_to_matching_leads(): void
    {
        $this->activateWorkflow($this->payload([
            'conditions' => ['lead_type' => ['pos_demo']],
        ]));

        $this->postJson('/api/v1/public/forms/quote', [
            'contact_name' => 'Quote Only',
            'business_name' => 'Quote Traders',
            'email' => 'quote-only@automation.test',
            'consent_transactional' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/public/forms/pos-demo', [
            'contact_name' => 'Demo Wanted',
            'business_name' => 'Demo Traders',
            'email' => 'demo-wanted@automation.test',
            'consent_transactional' => true,
        ])->assertCreated();

        $demoLead = MarketingLead::query()->where('email', 'demo-wanted@automation.test')->firstOrFail();
        $quoteLead = MarketingLead::query()->where('email', 'quote-only@automation.test')->firstOrFail();

        $this->assertSame(1, AutomationRun::query()->where('lead_id', $demoLead->id)->count());
        $this->assertSame('completed', AutomationRun::query()->where('lead_id', $demoLead->id)->firstOrFail()->status);
        $this->assertSame(0, AutomationRun::query()->where('lead_id', $quoteLead->id)->count());
    }

    public function test_external_steps_are_blocked_without_consent_or_provider(): void
    {
        $this->activateWorkflow([
            'name' => 'External follow-up',
            'trigger' => 'lead.created',
            'steps' => [['type' => 'notify_external', 'config' => [
                'channel' => 'email',
                'title' => 'Next step for {reference}',
                'body' => 'Hello {contact_name}',
            ]]],
        ]);

        $withoutConsent = $this->lead('no-consent@automation.test');
        $this->app->make(AutomationService::class)->dispatch('lead.created', $withoutConsent, ['signature' => 'created']);

        $run = AutomationRun::query()->where('lead_id', $withoutConsent->id)->firstOrFail();
        $this->assertSame('blocked', $run->status);
        $this->assertSame('CONSENT_MISSING', $run->steps()->firstOrFail()->last_error);
        $this->assertSame(0, Notification::query()->count());

        $withConsent = $this->lead('with-consent@automation.test', marketingConsent: true);
        MarketingConsent::create([
            'subject_type' => 'lead',
            'subject_id' => $withConsent->id,
            'channel' => 'email',
            'status' => 'granted',
            'source' => 'crm',
            'policy_version' => '2026-11-16',
            'occurred_at' => now(),
        ]);

        $this->app->make(AutomationService::class)->dispatch('lead.created', $withConsent, ['signature' => 'created']);

        $run = AutomationRun::query()->where('lead_id', $withConsent->id)->firstOrFail();
        $step = $run->steps()->firstOrFail();

        $this->assertSame('blocked', $run->status);
        $this->assertSame('PROVIDER_NOT_APPROVED', $step->last_error);
        $this->assertFalse($step->output['sent']);
        $this->assertSame('Next step for '.$withConsent->reference, $step->output['preview']['title']);
    }

    public function test_status_change_and_assignment_triggers_create_runs(): void
    {
        $this->activateWorkflow([
            'name' => 'Status follow-up',
            'trigger' => 'lead.status_changed',
            'steps' => [['type' => 'add_activity', 'config' => ['body' => 'Automation logged the new status {status}.']]],
        ]);

        $this->activateWorkflow([
            'name' => 'Assignment alert',
            'trigger' => 'lead.assigned',
            'steps' => [['type' => 'notify_internal', 'config' => ['recipients' => 'lead_owner', 'title' => 'Lead {reference} is yours', 'body' => 'Please contact {contact_name}.']]],
        ]);

        $lead = $this->lead('pipeline@automation.test');
        $leadService = $this->app->make(LeadService::class);

        $leadService->updateStatus($lead, 'contacted', $this->manager);
        $statusRun = AutomationRun::query()->where('lead_id', $lead->id)->where('trigger', 'lead.status_changed')->firstOrFail();
        $this->assertSame('completed', $statusRun->status);
        $this->assertTrue($lead->activities()->where('body', 'Automation logged the new status contacted.')->exists());

        $assignee = $this->userWithRole('lead-agent');
        $leadService->assign($lead, $assignee, $this->manager, 'Automation test');

        $assignmentRun = AutomationRun::query()->where('lead_id', $lead->id)->where('trigger', 'lead.assigned')->firstOrFail();
        $this->assertSame('completed', $assignmentRun->status);
        $this->assertTrue(Notification::query()
            ->where('user_id', $assignee->id)
            ->get()
            ->contains(fn (Notification $notification) => ($notification->data['run_id'] ?? null) === $assignmentRun->uuid));
    }

    public function test_wait_steps_resume_when_due(): void
    {
        $this->activateWorkflow($this->payload([
            'steps' => [
                ['type' => 'wait', 'config' => ['minutes' => 15]],
                ['type' => 'add_activity', 'config' => ['body' => 'Waited then followed up for {reference}.']],
            ],
        ]));

        $lead = $this->lead('waiter@automation.test');
        $automation = $this->app->make(AutomationService::class);
        $automation->dispatch('lead.created', $lead, ['signature' => 'created']);

        $run = AutomationRun::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('waiting', $run->status);
        $this->assertNotNull($run->resume_at);
        $this->assertTrue($run->resume_at->greaterThan(now()->addMinutes(14)));
        $this->assertSame(0, $automation->processDue());

        $this->travel(16)->minutes();

        $this->assertSame(1, $automation->processDue());
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertTrue($lead->activities()->where('body', 'Waited then followed up for '.$lead->reference.'.')->exists());
    }

    public function test_kill_switch_stops_runs_and_is_audited(): void
    {
        $this->activateWorkflow($this->payload());

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/automation/kill-switch', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.settings.enabled', false);

        $this->assertTrue(AuditLog::query()->where('action', 'marketing.automation.kill_switch')->exists());

        $automation = $this->app->make(AutomationService::class);
        $lead = $this->lead('paused@automation.test');
        $automation->dispatch('lead.created', $lead, ['signature' => 'created']);

        $run = AutomationRun::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('blocked', $run->status);
        $this->assertSame(AutomationService::KILL_SWITCH_MESSAGE, $run->last_error);
        $this->assertSame(0, $run->steps()->where('status', 'executed')->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, $automation->processDue());

        $this->as($this->manager)->postJson('/api/v1/platform/marketing/automation/kill-switch', ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.settings.enabled', true);

        $second = $this->lead('resumed@automation.test');
        $automation->dispatch('lead.created', $second, ['signature' => 'created']);
        $this->assertSame('completed', AutomationRun::query()->where('lead_id', $second->id)->firstOrFail()->status);
    }

    public function test_dispatch_is_idempotent_for_the_same_event(): void
    {
        $this->activateWorkflow($this->payload());

        $lead = $this->lead('idempotent@automation.test');
        $automation = $this->app->make(AutomationService::class);

        $this->assertSame(1, $automation->dispatch('lead.created', $lead, ['signature' => 'created']));
        $this->assertSame(0, $automation->dispatch('lead.created', $lead, ['signature' => 'created']));

        $this->assertSame(1, AutomationRun::query()->count());
        $this->assertSame(1, Notification::query()->count());
        $this->assertSame(2, AutomationRun::query()->firstOrFail()->steps()->where('status', 'executed')->count());
    }

    public function test_failed_steps_retry_with_backoff_then_fail(): void
    {
        $workflow = AutomationWorkflow::create([
            'name' => 'Broken workflow',
            'trigger' => 'lead.created',
            'status' => 'active',
            'created_by' => $this->manager->id,
        ]);
        AutomationStep::create([
            'workflow_id' => $workflow->id,
            'position' => 1,
            'type' => 'broken_step',
            'config' => [],
        ]);

        $lead = $this->lead('retry@automation.test');
        $automation = $this->app->make(AutomationService::class);
        $automation->dispatch('lead.created', $lead, ['signature' => 'created']);

        $run = AutomationRun::query()->where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('waiting', $run->status);
        $this->assertSame(1, $run->attempts);
        $this->assertStringContainsString('Unknown automation step type', (string) $run->last_error);

        $this->travel(2)->minutes();
        $automation->processDue();
        $this->assertSame(2, $run->fresh()->attempts);

        $this->travel(6)->minutes();
        $automation->processDue();

        $run = $run->fresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('failed', $run->steps()->firstOrFail()->status);
    }

    public function test_permissions_block_editors_and_tenants(): void
    {
        $editor = $this->userWithRole('content-editor');
        $this->as($editor)->getJson('/api/v1/platform/marketing/workflows')->assertForbidden();

        $executive = $this->userWithRole('read-only-executive');
        $this->as($executive)->getJson('/api/v1/platform/marketing/workflows')->assertOk();
        $this->as($executive)->postJson('/api/v1/platform/marketing/workflows', $this->payload())->assertForbidden();
        $this->as($executive)->postJson('/api/v1/platform/marketing/automation/kill-switch', ['enabled' => false])->assertForbidden();

        $tenant = $this->userWithRole('business-owner');
        $this->as($tenant)->getJson('/api/v1/platform/marketing/workflows')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Lead follow-up',
            'description' => 'Test workflow',
            'trigger' => 'lead.created',
            'steps' => [
                ['type' => 'notify_internal', 'config' => [
                    'recipients' => 'lead_managers',
                    'title' => 'New lead {reference}',
                    'body' => '{contact_name} — {business_name}',
                ]],
                ['type' => 'add_activity', 'config' => ['body' => 'Follow up with {contact_name} about the {type} request.']],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function activateWorkflow(array $payload): string
    {
        $id = $this->as($this->manager)->postJson('/api/v1/platform/marketing/workflows', $payload)
            ->assertCreated()
            ->json('data.workflow.id');

        $this->as($this->manager)->postJson("/api/v1/platform/marketing/workflows/{$id}/activate")->assertOk();

        return $id;
    }

    private function lead(string $email, bool $marketingConsent = false): MarketingLead
    {
        return MarketingLead::create([
            'reference' => 'LD-'.strtoupper(substr(md5($email), 0, 8)),
            'submission_token' => 'token-'.md5($email),
            'type' => 'quote',
            'status' => 'new',
            'business_name' => 'Automation Traders',
            'contact_name' => 'Automation Lead',
            'email' => $email,
            'source' => 'website',
            'consent_marketing' => $marketingConsent,
            'consent_transactional' => true,
            'consent_at' => now(),
            'last_activity_at' => now(),
        ]);
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
