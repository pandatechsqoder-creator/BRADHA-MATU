<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_checks_queue_storage_and_migrations(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.cache', 'ok')
            ->assertJsonPath('data.checks.queue', 'ok')
            ->assertJsonPath('data.checks.storage', 'ok')
            ->assertJsonPath('data.checks.migrations', 'ok')
            ->assertJsonPath('data.diagnostics.migrations_pending', 0);
    }

    public function test_api_responses_carry_security_headers(): void
    {
        $this->getJson('/api/v1/health')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    }

    public function test_health_check_command_exits_zero_when_healthy(): void
    {
        $this->artisan('health:check')
            ->expectsOutputToContain('"status": "ok"')
            ->assertSuccessful();
    }

    public function test_retention_purge_respects_windows_and_dry_run(): void
    {
        $user = User::factory()->create();

        $oldRead = $this->notification($user, now()->subDays(200), now()->subDays(150));
        $oldUnread = $this->notification($user, now()->subDays(200), null);
        $recent = $this->notification($user, now()->subDay(), null);

        DB::table('report_runs')->insert([
            'uuid' => (string) Str::ulid(),
            'report_type' => 'inventory_valuation',
            'format' => 'json',
            'row_count' => 1,
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);

        $expiredToken = Str::random(40);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'expired',
            'token' => hash('sha256', $expiredToken),
            'abilities' => '["*"]',
            'expires_at' => now()->subDays(30),
            'created_at' => now()->subDays(60),
            'updated_at' => now()->subDays(60),
        ]);

        $this->artisan('retention:purge', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('notifications', ['id' => $oldRead]);
        $this->assertDatabaseCount('report_runs', 1);

        $this->artisan('retention:purge')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $oldRead]);
        $this->assertDatabaseMissing('notifications', ['id' => $oldUnread]);
        $this->assertDatabaseHas('notifications', ['id' => $recent]);
        $this->assertDatabaseCount('report_runs', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.purged']);
    }

    private function notification(User $user, mixed $createdAt, mixed $readAt): int
    {
        return DB::table('notifications')->insertGetId([
            'uuid' => (string) Str::ulid(),
            'user_id' => $user->id,
            'type' => 'support',
            'title' => 'Retention probe',
            'read_at' => $readAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
