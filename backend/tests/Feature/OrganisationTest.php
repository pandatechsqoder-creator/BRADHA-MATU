<?php

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\Branch;
use App\Models\Invitation;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\Tenancy\DefaultHeadOfficeProvisioner;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganisationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tokens = [];

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

    private function as(User $user, Tenant $tenant): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->tokens[$user->id] ??= $user->createToken('test')->plainTextToken;

        return $this->withToken($this->tokens[$user->id])->withHeader('X-Tenant-Id', $tenant->uuid);
    }

    public function test_default_head_office_provisioning_is_idempotent_and_reuses_existing_locations(): void
    {
        $tenant = Tenant::create(['name' => 'Legacy business', 'status' => 'active']);
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Head Office',
            'uuid' => (string) Str::ulid(),
        ]);
        $location = StockLocation::create([
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Existing stock room',
        ]);
        $provisioner = app(DefaultHeadOfficeProvisioner::class);

        $provisioner->provision($tenant);
        $provisioner->provision($tenant);

        $this->assertSame(1, Branch::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, StockLocation::where('tenant_id', $tenant->id)->count());
        $this->assertTrue($branch->fresh()->is_default);
        $this->assertTrue($location->fresh()->is_default);
        $this->assertSame(1, Branch::where('tenant_id', $tenant->id)->where('is_default', true)->count());
        $this->assertSame(1, StockLocation::where('tenant_id', $tenant->id)->where('is_default', true)->count());
    }

    public function test_new_tenants_receive_default_head_office_and_defaults_can_be_changed_safely(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);
        $otherTenant = Tenant::create(['name' => 'Other business', 'status' => 'active']);
        app(DefaultHeadOfficeProvisioner::class)->provision($otherTenant);
        $otherDefault = Branch::where('tenant_id', $otherTenant->id)->where('is_default', true)->firstOrFail();

        $created = $this->as($owner, $tenant)->postJson('/api/v1/tenants', [
            'name' => 'New organisation',
            'timezone' => 'Africa/Nairobi',
        ])->assertCreated()->json('data');
        $createdTenant = Tenant::where('uuid', $created['id'])->firstOrFail();
        $headOffice = Branch::where('tenant_id', $createdTenant->id)->where('is_default', true)->firstOrFail();
        $defaultLocation = StockLocation::where('tenant_id', $createdTenant->id)->where('is_default', true)->firstOrFail();

        $this->assertSame('Head Office', $headOffice->name);
        $this->assertSame($headOffice->id, $defaultLocation->branch_id);
        $this->assertSame('Main Store', $defaultLocation->name);

        $newBranch = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/branches", [
            'name' => 'Westlands',
        ])->assertCreated()->json('data');
        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/branches/{$newBranch['id']}", [
            'is_default' => true,
        ])->assertOk()->assertJsonPath('data.is_default', true);

        $newDefaultLocation = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/stock-locations", [
            'name' => 'Westlands Floor',
            'branch_id' => $newBranch['id'],
            'type' => 'floor',
            'is_default' => true,
        ])->assertCreated()->assertJsonPath('data.is_default', true)->json('data');

        $this->assertSame(1, Branch::where('tenant_id', $tenant->id)->where('is_default', true)->count());
        $this->assertSame(1, StockLocation::where('tenant_id', $tenant->id)->where('is_default', true)->count());
        $this->assertSame($newDefaultLocation['id'], StockLocation::where('tenant_id', $tenant->id)->where('is_default', true)->value('uuid'));
        $this->assertSame($otherDefault->id, Branch::where('tenant_id', $otherTenant->id)->where('is_default', true)->value('id'));

        $this->as($owner, $tenant)->deleteJson("/api/v1/tenants/{$tenant->uuid}/stock-locations/{$newDefaultLocation['id']}")
            ->assertUnprocessable();
        $this->as($owner, $tenant)->deleteJson("/api/v1/tenants/{$tenant->uuid}/branches/{$newBranch['id']}")
            ->assertUnprocessable();
    }

    public function test_owner_can_manage_branches_departments_warehouses_locations_zones_and_bins(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $branch = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/branches", [
            'name' => 'Nairobi CBD',
            'code' => 'NRB',
            'city' => 'Nairobi',
        ])->assertCreated()->json('data');

        $department = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/departments", [
            'name' => 'Grocery',
            'branch_id' => $branch['id'],
        ])->assertCreated()->json('data');
        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/departments/{$department['id']}", [
            'name' => 'Fresh Grocery',
            'code' => 'FRESH',
            'branch_id' => $branch['id'],
        ])->assertOk()->assertJsonPath('data.name', 'Fresh Grocery');

        $warehouse = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/warehouses", [
            'name' => 'Central Store',
            'branch_id' => $branch['id'],
        ])->assertCreated()->json('data');
        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/warehouses/{$warehouse['id']}", [
            'name' => 'Central Distribution',
            'code' => 'CENTRAL',
            'address' => '1 Market Road',
            'branch_id' => $branch['id'],
        ])->assertOk()->assertJsonPath('data.name', 'Central Distribution');

        $location = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/stock-locations", [
            'name' => 'Aisle 1',
            'type' => 'aisle',
            'branch_id' => $branch['id'],
            'warehouse_id' => $warehouse['id'],
        ])->assertCreated()->json('data');

        $zone = $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/stock-locations/{$location['id']}/zones",
            ['name' => 'Dry goods']
        )->assertCreated()->json('data');

        $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/stock-zones/{$zone['id']}/bins",
            ['name' => 'Bin A1', 'code' => 'A1']
        )->assertCreated()->assertJsonPath('data.name', 'Bin A1');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/branches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.departments_count', 1)
            ->assertJsonPath('data.0.warehouses_count', 1)
            ->assertJsonPath('data.0.locations_count', 1);

        $this->assertDatabaseHas('audit_logs', ['action' => 'branch.created']);
        $this->assertDatabaseHas('departments', [
            'uuid' => $department['id'],
            'name' => 'Fresh Grocery',
            'code' => 'FRESH',
        ]);
        $this->assertDatabaseHas('warehouses', [
            'uuid' => $warehouse['id'],
            'name' => 'Central Distribution',
            'code' => 'CENTRAL',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stock_bin.created']);
    }

    public function test_branch_scopes_restrict_directory_listing(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);
        $manager = $this->memberOf($tenant, 'branch-manager');

        Branch::create(['tenant_id' => $tenant->id, 'name' => 'Westlands', 'uuid' => (string) Str::ulid()]);
        $scoped = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Karen', 'uuid' => (string) Str::ulid()]);

        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/members/{$manager->uuid}", [
            'branch_ids' => [$scoped->uuid],
        ])->assertOk()->assertJsonPath('data.branch_scopes.0.name', 'Karen');

        $this->as($manager, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/branches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Karen');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/branches")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_tenant_cannot_modify_another_tenants_branch(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);
        $ownerA = $this->memberOf($tenantA);
        $ownerB = $this->memberOf($tenantB);

        $branchB = Branch::create(['tenant_id' => $tenantB->id, 'name' => 'B Branch', 'uuid' => (string) Str::ulid()]);

        $this->as($ownerA, $tenantA)
            ->patchJson("/api/v1/tenants/{$tenantA->uuid}/branches/{$branchB->uuid}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->as($ownerA, $tenantA)->postJson("/api/v1/tenants/{$tenantA->uuid}/departments", [
            'name' => 'Cross tenant',
            'branch_id' => $branchB->uuid,
        ])->assertStatus(422);

        $this->as($ownerB, $tenantB)->postJson("/api/v1/tenants/{$tenantB->uuid}/branches", [
            'name' => 'Valid B branch',
        ])->assertCreated();
    }

    public function test_invitation_flow_creates_membership_role_and_scope(): void
    {
        Mail::fake();
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Westlands', 'uuid' => (string) Str::ulid()]);

        $invite = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'new.manager@example.test',
            'role' => 'branch-manager',
            'branch_id' => $branch->uuid,
        ])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('token', $invite);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.created']);
        $invitationToken = $this->queuedInvitationToken('new.manager@example.test');

        $invitee = User::factory()->create(['email' => 'new.manager@example.test']);
        $token = $invitee->createToken('test')->plainTextToken;

        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)
            ->postJson("/api/v1/invitations/{$invitationToken}/accept")
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Retail');

        $this->assertTrue($invitee->fresh()->isMemberOf($tenant));
        $this->assertDatabaseHas('user_roles', ['user_id' => $invitee->id, 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('user_branch_scopes', ['user_id' => $invitee->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseHas('invitations', ['id' => Invitation::where('email', 'new.manager@example.test')->value('id'), 'status' => 'accepted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted']);
    }

    public function test_public_invitation_activation_creates_new_account_and_is_single_use(): void
    {
        Mail::fake();
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'new.member@example.test',
            'role' => 'tenant-read-only',
        ])->assertCreated();
        $invitationToken = $this->queuedInvitationToken('new.member@example.test');

        $this->getJson("/api/v1/public/invitations/{$invitationToken}")
            ->assertOk()
            ->assertJsonPath('data.email', 'new.member@example.test')
            ->assertJsonPath('data.status', 'pending');

        $response = $this->postJson("/api/v1/public/invitations/{$invitationToken}/activate", [
            'name' => 'New Member',
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ])->assertOk()
            ->assertJsonPath('data.user.email', 'new.member@example.test');

        $this->assertNotEmpty($response->json('data.token'));
        $user = User::where('email', 'new.member@example.test')->firstOrFail();
        $this->assertTrue($user->isMemberOf($tenant));
        $this->assertDatabaseHas('user_roles', ['user_id' => $user->id, 'tenant_id' => $tenant->id]);
        $this->postJson("/api/v1/public/invitations/{$invitationToken}/activate", [
            'name' => 'New Member',
            'password' => 'StrongPass1!',
            'password_confirmation' => 'StrongPass1!',
        ])->assertStatus(422);
    }

    public function test_public_invitation_activation_cannot_reset_an_existing_accounts_password(): void
    {
        Mail::fake();
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'existing.member@example.test',
            'role' => 'tenant-read-only',
        ])->assertCreated();
        $invitationToken = $this->queuedInvitationToken('existing.member@example.test');

        $existingUser = User::factory()->create([
            'email' => 'existing.member@example.test',
            'password' => Hash::make('OriginalPass1!'),
        ]);

        $this->postJson("/api/v1/public/invitations/{$invitationToken}/activate", [
            'name' => 'Changed Name',
            'password' => 'AttackerPass1!',
            'password_confirmation' => 'AttackerPass1!',
        ])->assertStatus(409)
            ->assertJsonPath('error.message', 'An account already exists for this email. Sign in and reopen the invitation to accept it.');

        $this->assertTrue(Hash::check('OriginalPass1!', $existingUser->fresh()->password));
        $this->assertFalse($existingUser->fresh()->isMemberOf($tenant));
        $this->assertDatabaseHas('invitations', [
            'token_hash' => hash('sha256', $invitationToken),
            'status' => 'pending',
        ]);
    }

    public function test_invitation_rejects_wrong_email_expired_and_revoked(): void
    {
        Mail::fake();
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $invite = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'invited@example.test',
            'role' => 'tenant-read-only',
        ])->assertCreated()->json('data');
        $invitationToken = $this->queuedInvitationToken('invited@example.test');

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'expired@example.test',
            'role' => 'tenant-read-only',
        ])->assertCreated();
        $expiredToken = $this->queuedInvitationToken('expired@example.test');
        Invitation::where('email', 'expired@example.test')->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/v1/public/invitations/{$expiredToken}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $wrong = User::factory()->create(['email' => 'someone.else@example.test']);
        $this->withToken($wrong->createToken('test')->plainTextToken)
            ->postJson("/api/v1/invitations/{$invitationToken}/accept")
            ->assertStatus(403);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/invitations/{$invite['id']}/revoke")
            ->assertOk();

        $invitee = User::factory()->create(['email' => 'invited@example.test']);
        $this->withToken($invitee->createToken('test')->plainTextToken)
            ->postJson("/api/v1/invitations/{$invitationToken}/accept")
            ->assertStatus(422);

        $this->withToken($invitee->createToken('test')->plainTextToken)
            ->getJson("/api/v1/invitations/{$invitationToken}")
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
    }

    private function queuedInvitationToken(string $recipient): string
    {
        $token = null;

        Mail::assertQueued(UserInvitationMail::class, function (UserInvitationMail $mail) use (&$token, $recipient): bool {
            if ($mail->recipientEmail !== $recipient) {
                return false;
            }

            parse_str((string) parse_url($mail->acceptUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return is_string($token) && $token !== '';
        });

        $this->assertNotEmpty($token);

        return $token;
    }

    public function test_member_roles_and_scopes_can_be_updated(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);
        $member = $this->memberOf($tenant, 'tenant-read-only');
        $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Westlands', 'uuid' => (string) Str::ulid()]);

        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/members/{$member->uuid}", [
            'roles' => ['inventory-manager'],
            'branch_ids' => [$branch->uuid],
        ])->assertOk()
            ->assertJsonPath('data.roles.0.slug', 'inventory-manager')
            ->assertJsonPath('data.branch_scopes.0.id', $branch->uuid);

        $this->assertDatabaseHas('audit_logs', ['action' => 'member.roles_updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.scopes_updated']);
    }

    public function test_owner_cannot_remove_own_owner_role_or_membership(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/members/{$owner->uuid}", [
            'roles' => ['tenant-read-only'],
        ])->assertStatus(422);

        $this->as($owner, $tenant)
            ->deleteJson("/api/v1/tenants/{$tenant->uuid}/members/{$owner->uuid}")
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_invite_or_update_members(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $this->memberOf($tenant);
        $clerk = $this->memberOf($tenant, 'tenant-read-only');

        $this->as($clerk, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/invitations", [
            'email' => 'new@example.test',
            'role' => 'tenant-read-only',
        ])->assertStatus(403);

        $this->as($clerk, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/roles")
            ->assertOk()
            ->assertJsonFragment(['slug' => 'branch-manager']);

        $this->as($clerk, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/members/{$clerk->uuid}", [
            'roles' => ['branch-manager'],
        ])->assertStatus(403);
    }
}
