<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function memberOf(Tenant $tenant, string $role = 'business-owner'): User
    {
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->assignRole($role, $tenant->id);

        return $user;
    }

    public function test_login_returns_roles_permissions_and_tenants(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $user = $this->memberOf($tenant);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.roles.0.slug', 'business-owner')
            ->assertJsonPath('data.user.tenants.0.id', $tenant->uuid)
            ->assertJsonPath('data.user.current_tenant.id', $tenant->uuid);

        $this->assertContains('catalogue.view', $response->json('data.user.permissions'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login']);
    }

    public function test_tenant_onboarding_creates_membership_and_owner_role(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/tenants', [
            'name' => 'Nia Provisions',
            'industry' => 'Retail',
            'operating_mode' => 'manual',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Nia Provisions')
            ->assertJsonPath('data.status', 'active');

        $tenant = Tenant::where('name', 'Nia Provisions')->firstOrFail();
        $this->assertTrue($user->fresh()->isMemberOf($tenant));
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.created']);
    }

    public function test_tenant_cannot_view_another_tenants_record(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);
        $user = $this->memberOf($tenantA);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/tenants/'.$tenantB->uuid)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_spoofed_tenant_header_is_rejected(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);
        $user = $this->memberOf($tenantA);
        $token = $user->createToken('test')->plainTextToken;

        $me = $this->withToken($token)->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/me')
            ->assertOk();

        // A spoofed header never selects another tenant; the user's own tenant is used.
        $this->assertSame($tenantA->uuid, $me->json('data.current_tenant.id'));

        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)->withHeader('X-Tenant-Id', $tenantB->uuid)
            ->getJson('/api/v1/tenants/'.$tenantB->uuid)
            ->assertStatus(403);
    }

    public function test_platform_admin_can_list_all_tenants_and_see_overview(): void
    {
        Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        Tenant::create(['name' => 'Tenant B', 'status' => 'active']);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/v1/platform/tenants')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/v1/platform/overview')
            ->assertOk()
            ->assertJsonPath('data.tenants.total', 2);
    }

    public function test_tenant_user_cannot_access_platform_endpoints(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $user = $this->memberOf($tenant);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/platform/tenants')
            ->assertStatus(403);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/platform/audit-logs')
            ->assertStatus(403);
    }

    public function test_tenant_update_requires_tenant_update_permission(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $owner = $this->memberOf($tenant, 'business-owner');
        $inventory = $this->memberOf($tenant, 'inventory-manager');

        $this->withToken($inventory->createToken('test')->plainTextToken)
            ->patchJson('/api/v1/tenants/'.$tenant->uuid, ['name' => 'Renamed'])
            ->assertStatus(403);

        $this->app->make('auth')->forgetGuards();

        $this->withToken($owner->createToken('test')->plainTextToken)
            ->patchJson('/api/v1/tenants/'.$tenant->uuid, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.updated']);
    }

    public function test_dashboard_is_tenant_aware(): void
    {
        $tenant = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $user = $this->memberOf($tenant);

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.workspace', 'tenant')
            ->assertJsonPath('data.tenant.id', $tenant->uuid)
            ->assertJsonPath('data.stats.tenant_members', 1);
    }

    public function test_audit_log_failure_is_recorded_for_bad_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }
}
