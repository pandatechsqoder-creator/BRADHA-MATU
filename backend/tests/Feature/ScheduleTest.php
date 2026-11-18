<?php

namespace Tests\Feature;

use App\Models\ScheduleOccurrence;
use App\Models\StocktakeSchedule;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);
        $this->tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
    }

    private function as(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();
        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function tenantOwner(): User
    {
        $user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $user->assignRole('business-owner', $this->tenant->id);
        return $user;
    }

    public function test_tenant_can_create_a_monthly_schedule(): void
    {
        $owner = $this->tenantOwner();

        $resp = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'Monthly full count',
                'mode' => 'reminder',
                'frequency' => 'monthly',
                'recurrence_config' => ['day_of_month' => 1],
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('monthly', $resp['frequency']);
        $this->assertSame('reminder', $resp['mode']);
        $this->assertTrue($resp['active']);
        $this->assertNotNull($resp['next_occurrence_at']);
    }

    public function test_generating_occurrences_creates_no_duplicates(): void
    {
        $owner = $this->tenantOwner();

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'Weekly spot check',
                'mode' => 'proposed',
                'frequency' => 'weekly',
                'starts_at' => now()->toDateString(),
            ])
            ->assertCreated()->json('data');

        // Generate 3
        $result = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}/generate", ['count' => 3])
            ->assertOk()->json('data');

        $this->assertSame(3, $result['generated']);
        $this->assertSame(3, ScheduleOccurrence::where('tenant_id', $this->tenant->id)->count());

        // Generate again — no new duplicates
        $second = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}/generate", ['count' => 3])
            ->assertOk()->json('data');

        $this->assertSame(0, $second['generated']);
        $this->assertSame(3, ScheduleOccurrence::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_on_demand_trigger_creates_one_occurrence(): void
    {
        $owner = $this->tenantOwner();

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'On-demand count',
                'mode' => 'reminder',
                'frequency' => 'on_demand',
            ])
            ->assertCreated()->json('data');

        $occ = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}/trigger")
            ->assertCreated()->json('data');

        $this->assertSame('pending', $occ['status']);
        $this->assertNotNull($occ['scheduled_at']);
        $this->assertSame(1, ScheduleOccurrence::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_occurrence_can_be_skipped_with_reason(): void
    {
        $owner = $this->tenantOwner();

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'Skip test',
                'mode' => 'reminder',
                'frequency' => 'monthly',
            ])
            ->assertCreated()->json('data');

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}/generate", ['count' => 1])
            ->assertOk();

        $occUuid = ScheduleOccurrence::where('tenant_id', $this->tenant->id)->value('uuid');

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedule-occurrences/{$occUuid}/skip", [
                'reason' => 'Business closed for the month.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'skipped');

        $this->assertSame('skipped', ScheduleOccurrence::where('uuid', $occUuid)->value('status'));
    }

    public function test_schedule_can_be_paused_and_resumed(): void
    {
        $owner = $this->tenantOwner();

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'Pause test',
                'mode' => 'reminder',
                'frequency' => 'quarterly',
            ])
            ->assertCreated()->json('data');

        $this->assertTrue($schedule['active']);

        $paused = $this->as($owner)
            ->patchJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}", ['active' => false])
            ->assertOk()->json('data');

        $this->assertFalse($paused['active']);
        $this->assertNull($paused['next_occurrence_at']);

        $resumed = $this->as($owner)
            ->patchJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}", ['active' => true])
            ->assertOk()->json('data');

        $this->assertTrue($resumed['active']);
        $this->assertNotNull($resumed['next_occurrence_at']);
    }

    public function test_on_demand_schedule_cannot_auto_generate_occurrences(): void
    {
        $owner = $this->tenantOwner();

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'On-demand',
                'mode' => 'reminder',
                'frequency' => 'on_demand',
            ])
            ->assertCreated()->json('data');

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules/{$schedule['id']}/generate")
            ->assertStatus(422);
    }

    public function test_isolation_another_tenant_cannot_access_schedule(): void
    {
        $owner = $this->tenantOwner();
        $otherTenant = Tenant::create(['name' => 'Other', 'status' => 'active']);
        $other = User::factory()->create();
        $otherTenant->memberships()->create(['user_id' => $other->id, 'status' => 'active', 'joined_at' => now()]);
        $other->assignRole('business-owner', $otherTenant->id);

        $schedule = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/schedules", [
                'title' => 'Isolated',
                'mode' => 'reminder',
                'frequency' => 'monthly',
            ])
            ->assertCreated()->json('data');

        $this->as($other)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/schedules/{$schedule['id']}")
            ->assertNotFound();
    }
}
