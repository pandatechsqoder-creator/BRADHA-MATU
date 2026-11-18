<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_tenant_owner_can_save_and_resume_optional_onboarding_steps(): void
    {
        $tenant = Tenant::create(['name' => 'Green Valley', 'status' => 'active']);
        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->assignRole('business-owner', $tenant->id);
        $this->withToken($owner->createToken('test')->plainTextToken);

        $this->getJson("/api/v1/tenants/{$tenant->uuid}/onboarding")
            ->assertOk()
            ->assertJsonPath('data.steps.0.status', 'completed')
            ->assertJsonPath('data.steps.1.status', 'pending');

        $this->patchJson("/api/v1/tenants/{$tenant->uuid}/onboarding/departments", [
            'action' => 'skip',
        ])->assertOk()
            ->assertJsonPath('data.steps.3.status', 'skipped');

        $this->getJson("/api/v1/tenants/{$tenant->uuid}/onboarding")
            ->assertOk()
            ->assertJsonPath('data.steps.3.status', 'skipped');

        $this->patchJson("/api/v1/tenants/{$tenant->uuid}/onboarding/departments", [
            'action' => 'resume',
        ])->assertOk()
            ->assertJsonPath('data.steps.3.status', 'pending');

        $this->patchJson("/api/v1/tenants/{$tenant->uuid}/onboarding/business-profile", [
            'action' => 'skip',
        ])->assertUnprocessable();
    }

    public function test_tenant_onboarding_is_isolated_and_finish_requires_required_steps(): void
    {
        $tenant = Tenant::create(['name' => 'Green Valley', 'status' => 'active']);
        $otherTenant = Tenant::create(['name' => 'Other Shop', 'status' => 'active']);
        $owner = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $owner->assignRole('business-owner', $tenant->id);
        $this->withToken($owner->createToken('test')->plainTextToken);

        $this->getJson("/api/v1/tenants/{$otherTenant->uuid}/onboarding")->assertForbidden();

        $this->patchJson("/api/v1/tenants/{$tenant->uuid}/onboarding/final-review", [
            'action' => 'finish',
        ])->assertUnprocessable()
            ->assertJsonPath('error.message', 'Complete the required Business locations step before finishing setup.');
    }
}
