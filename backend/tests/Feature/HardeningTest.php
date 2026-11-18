<?php

namespace Tests\Feature;

use App\Models\Marketing\AutomationWorkflow;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_retention_purges_aged_marketing_data_and_keeps_recent_rows(): void
    {
        $workflow = AutomationWorkflow::create([
            'name' => 'Retention probe',
            'trigger' => 'lead.created',
            'status' => 'draft',
            'conditions' => [],
        ]);

        $insertAnalytics = fn (string $session, mixed $occurredAt) => DB::table('analytics_events')->insert([
            'event_name' => 'page_view',
            'occurred_at' => $occurredAt,
            'session_id' => $session,
            'consent_state' => json_encode(['analytics' => true]),
            'created_at' => $occurredAt,
        ]);
        $insertAnalytics('session-old', now()->subDays(500));
        $insertAnalytics('session-new', now()->subDay());

        DB::table('conversion_events')->insert([
            [
                'event_name' => 'quote_request',
                'occurred_at' => now()->subDays(800),
                'consent_state' => json_encode(['analytics' => true]),
                'created_at' => now()->subDays(800),
            ],
            [
                'event_name' => 'quote_request',
                'occurred_at' => now()->subDay(),
                'consent_state' => json_encode(['analytics' => true]),
                'created_at' => now()->subDay(),
            ],
        ]);

        foreach ([['session-old', now()->subDays(200)], ['session-new', now()->subDay()]] as [$session, $timestamp]) {
            DB::table('tracking_preferences')->insert([
                'session_id' => $session,
                'analytics' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $oldRun = DB::table('automation_runs')->insertGetId([
            'uuid' => (string) Str::ulid(),
            'workflow_id' => $workflow->id,
            'trigger' => 'lead.created',
            'event_key' => str_repeat('a', 64),
            'status' => 'completed',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);
        DB::table('automation_run_steps')->insert([
            'run_id' => $oldRun,
            'position' => 1,
            'type' => 'add_activity',
            'status' => 'executed',
            'created_at' => now()->subDays(400),
            'updated_at' => now()->subDays(400),
        ]);
        $recentRun = DB::table('automation_runs')->insertGetId([
            'uuid' => (string) Str::ulid(),
            'workflow_id' => $workflow->id,
            'trigger' => 'lead.created',
            'event_key' => str_repeat('b', 64),
            'status' => 'completed',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->artisan('retention:purge', ['--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('analytics_events', ['session_id' => 'session-old']);
        $this->assertDatabaseCount('automation_runs', 2);

        $this->artisan('retention:purge')->assertSuccessful();

        $this->assertDatabaseMissing('analytics_events', ['session_id' => 'session-old']);
        $this->assertDatabaseHas('analytics_events', ['session_id' => 'session-new']);
        $this->assertDatabaseCount('conversion_events', 1);
        $this->assertDatabaseMissing('tracking_preferences', ['session_id' => 'session-old']);
        $this->assertDatabaseHas('tracking_preferences', ['session_id' => 'session-new']);
        $this->assertDatabaseMissing('automation_runs', ['id' => $oldRun]);
        $this->assertDatabaseHas('automation_runs', ['id' => $recentRun]);
        $this->assertDatabaseMissing('automation_run_steps', ['run_id' => $oldRun]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.purged']);
    }

    public function test_health_check_flags_unsafe_production_configuration(): void
    {
        $this->app['env'] = 'production';
        config([
            'app.debug' => true,
            'app.url' => 'http://brathamatu.test',
            'session.secure' => false,
        ]);

        $this->artisan('health:check')
            ->expectsOutputToContain('APP_DEBUG must be false')
            ->assertFailed();

        config([
            'app.debug' => false,
            'app.url' => 'https://brathamatu.test',
            'session.secure' => true,
        ]);

        $this->artisan('health:check')
            ->expectsOutputToContain('"configuration": "ok"')
            ->assertSuccessful();
    }

    public function test_media_uploads_reject_non_allowlisted_and_disguised_files(): void
    {
        Storage::fake('local');

        $author = User::factory()->create();
        $author->assignRole('content-manager');

        $this->asUser($author)->post(
            '/api/v1/platform/marketing/media/assets',
            ['file' => UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            )],
            ['Accept' => 'application/json'],
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['file']]]);

        $this->asUser($author)->post(
            '/api/v1/platform/marketing/media/assets',
            ['file' => UploadedFile::fake()->createWithContent('invoice.jpg', 'not really an image')],
            ['Accept' => 'application/json'],
        )->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertDatabaseCount('media_assets', 0);
    }

    private function asUser(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }
}
