<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_receives_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Amina Odhiambo',
            'email' => 'amina@example.co.ke',
            'password' => 'stocktake1',
            'password_confirmation' => 'stocktake1',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => ['user' => ['id', 'name', 'email', 'email_verified_at', 'created_at'], 'token'],
                'meta' => ['request_id', 'timestamp'],
            ]);

        $this->assertDatabaseHas('users', ['email' => 'amina@example.co.ke']);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $response->json('data.user.id'));
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.co.ke']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'email' => 'taken@example.co.ke',
            'password' => 'stocktake1',
            'password_confirmation' => 'stocktake1',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.email.0', 'The email has already been taken.');
    }

    public function test_registration_rejects_weak_password(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Weak Password',
            'email' => 'weak@example.co.ke',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['password']]]);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = User::factory()->create(['password' => 'stocktake1']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'stocktake1',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.id', $user->uuid)
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'stocktake1']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422)
            ->assertJsonPath('error.details.email.0', 'These credentials do not match our records.');
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->uuid)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_platform_admin_receives_all_permissions_in_current_user_payload(): void
    {
        Permission::firstOrCreate(['slug' => 'cms.view']);
        Permission::firstOrCreate(['slug' => 'audit.view']);
        Role::firstOrCreate(['slug' => 'super-admin'], ['name' => 'Platform Super Admin', 'scope' => 'platform']);

        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $token = $user->createToken('test')->plainTextToken;

        $permissions = $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->json('data.permissions');

        $this->assertContains('cms.view', $permissions);
        $this->assertContains('audit.view', $permissions);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Simulate a fresh request: the auth guard caches the resolved user per lifecycle.
        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('999|invalidtoken')->getJson('/api/v1/me')
            ->assertStatus(401);
    }
}
