<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\ReportRun;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportingSupportTest extends TestCase
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

    private function as(User $user, ?Tenant $tenant = null): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        $request = $this->withToken($user->createToken('test')->plainTextToken);

        return $tenant ? $request->withHeader('X-Tenant-Id', $tenant->uuid) : $request;
    }

    private function tenant(): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);

        return [$tenant, $this->memberOf($tenant)];
    }

    private function platformUser(string $role = 'support-manager'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_tenant_reports_list_generate_and_export(): void
    {
        [$tenant, $owner] = $this->tenant();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/reports")
            ->assertOk()
            ->assertJsonPath('data.available.0', 'inventory_valuation')
            ->assertJsonPath('data.can_export', true);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/reports/inventory_valuation")
            ->assertOk()
            ->assertJsonPath('data.report_type', 'inventory_valuation')
            ->assertJsonPath('data.scope', 'tenant')
            ->assertJsonStructure(['data' => ['columns', 'rows', 'summary', 'period', 'generated_at']]);

        $this->assertDatabaseHas('report_runs', [
            'tenant_id' => $tenant->id,
            'report_type' => 'inventory_valuation',
            'user_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.generated', 'tenant_id' => $tenant->id]);

        $this->as($owner, $tenant)
            ->get("/api/v1/tenants/{$tenant->uuid}/reports/inventory_valuation/export")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('SKU');

        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported', 'tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('report_runs', [
            'tenant_id' => $tenant->id,
            'report_type' => 'inventory_valuation',
            'format' => 'csv',
        ]);
    }

    public function test_unknown_report_type_and_invalid_period_are_rejected(): void
    {
        [$tenant, $owner] = $this->tenant();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/reports/not_a_report")
            ->assertStatus(422);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/reports/stock_movements?from=2026-05-10&to=2026-05-01")
            ->assertStatus(422);
    }

    public function test_reports_are_tenant_isolated(): void
    {
        [$tenant, $owner] = $this->tenant();
        [$otherTenant, $otherOwner] = $this->tenant();

        $this->as($owner, $tenant)
            ->get("/api/v1/tenants/{$otherTenant->uuid}/reports")
            ->assertStatus(403);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/reports/inventory_valuation")
            ->assertStatus(403);

        $this->as($otherOwner, $otherTenant)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/reports")
            ->assertOk();
    }

    public function test_analytics_overview_returns_kpis_series_and_ai_interface(): void
    {
        [$tenant, $owner] = $this->tenant();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/analytics/overview")
            ->assertOk()
            ->assertJsonPath('data.scope', 'tenant')
            ->assertJsonPath('data.ai.enabled', false)
            ->assertJsonStructure([
                'data' => [
                    'kpis',
                    'series' => [['key', 'points']],
                    'breakdowns',
                    'insights',
                    'ai' => ['interfaces'],
                ],
            ]);
    }

    public function test_support_ticket_lifecycle_between_tenant_and_platform(): void
    {
        [$tenant, $owner] = $this->tenant();
        $platform = $this->platformUser();

        $ticket = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/support-tickets", [
                'subject' => 'POS terminal offline',
                'description' => 'The main till cannot reach the POS API after the router reset.',
                'category' => 'pos',
                'priority' => 'urgent',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'urgent')
            ->assertJsonPath('data.messages_count', 1)
            ->json('data');

        $this->assertNotNull($ticket['sla_due_at']);
        $this->assertSame(1, Notification::where('user_id', $platform->id)->count());

        $queue = $this->as($platform, $tenant)
            ->getJson('/api/v1/platform/support-tickets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'POS terminal offline')
            ->json('data.0');

        $replied = $this->as($platform, $tenant)
            ->postJson("/api/v1/platform/support-tickets/{$queue['id']}/messages", [
                'body' => 'We are checking the terminal registration. Please restart the till.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->json('data');

        $this->assertNotNull($replied['first_response_at']);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/support-tickets/{$ticket['id']}/messages", [
                'body' => 'Restarted. The till is back online, thank you.',
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data.messages');

        $notifications = $this->as($owner, $tenant)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->json('data.notifications');

        $this->assertSame('support', $notifications[0]['type']);

        $marked = $this->as($owner, $tenant)
            ->postJson("/api/v1/me/notifications/{$notifications[0]['id']}/read")
            ->assertOk()
            ->json('data');

        $this->assertNotNull($marked['read_at']);

        $this->as($platform, $tenant)
            ->patchJson("/api/v1/platform/support-tickets/{$queue['id']}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertNotNull(SupportTicket::where('uuid', $ticket['id'])->first()->resolved_at);

        $this->as($owner, $tenant)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->as($owner, $tenant)
            ->postJson('/api/v1/me/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->as($owner, $tenant)
            ->getJson('/api/v1/me/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_tenant_can_close_and_reopen_own_ticket(): void
    {
        [$tenant, $owner] = $this->tenant();

        $ticket = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/support-tickets", [
                'subject' => 'Invoice copy',
                'description' => 'Please resend the latest invoice for our records.',
            ])
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->patchJson("/api/v1/tenants/{$tenant->uuid}/support-tickets/{$ticket['id']}", ['status' => 'closed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->as($owner, $tenant)
            ->patchJson("/api/v1/tenants/{$tenant->uuid}/support-tickets/{$ticket['id']}", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
    }

    public function test_notification_preferences_can_be_updated(): void
    {
        [$tenant, $owner] = $this->tenant();
        $platform = $this->platformUser();

        $this->as($platform, $tenant)
            ->putJson('/api/v1/me/notification-preferences', [
                'in_app_enabled' => false,
                'email_enabled' => false,
                'digest_frequency' => 'daily',
                'event_preferences' => ['support' => true, 'finance' => false],
            ])
            ->assertOk()
            ->assertJsonPath('data.in_app_enabled', false)
            ->assertJsonPath('data.digest_frequency', 'daily')
            ->assertJsonPath('data.event_preferences.support', true);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/support-tickets", [
                'subject' => 'Muted notifications',
                'description' => 'Creating this ticket should not raise an in-app notification for the muted agent.',
            ])
            ->assertCreated();

        $this->assertSame(0, Notification::where('user_id', $platform->id)->count());

        $this->as($owner, $tenant)
            ->getJson('/api/v1/me/notification-preferences')
            ->assertOk()
            ->assertJsonPath('data.in_app_enabled', true)
            ->assertJsonPath('data.digest_frequency', 'instant');
    }

    public function test_users_without_support_permission_are_denied(): void
    {
        [$tenant] = $this->tenant();
        $readOnly = $this->memberOf($tenant, 'tenant-read-only');

        $this->as($readOnly, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/support-tickets")
            ->assertStatus(403);

        $this->as($readOnly, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/support-tickets", [
                'subject' => 'Nope',
                'description' => 'Should not be allowed to open this ticket.',
            ])
            ->assertStatus(403);
    }

    public function test_platform_reports_generate_and_export(): void
    {
        [$tenant, $owner] = $this->tenant();
        $finance = $this->platformUser('finance-manager');

        $this->as($finance, $tenant)
            ->getJson('/api/v1/platform/reports')
            ->assertOk()
            ->assertJsonPath('data.available.0', 'tenant_activity');

        $this->as($finance, $tenant)
            ->getJson('/api/v1/platform/reports/tenant_activity')
            ->assertOk()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonPath('data.rows.0.tenant', 'Acme Retail');

        $this->as($finance, $tenant)
            ->get('/api/v1/platform/reports/tenant_activity/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Tenant');

        $this->assertSame(2, ReportRun::withoutGlobalScopes()->count());

        $this->as($finance, $tenant)
            ->getJson('/api/v1/platform/analytics/overview')
            ->assertOk()
            ->assertJsonPath('data.scope', 'platform')
            ->assertJsonStructure(['data' => ['kpis', 'series', 'insights', 'ai']]);

        $this->as($owner, $tenant)
            ->getJson('/api/v1/platform/reports')
            ->assertStatus(403);
    }

    public function test_platform_support_queue_requires_support_permission(): void
    {
        $operations = $this->platformUser('operations-manager');
        $support = $this->platformUser('support-agent');

        $this->as($operations)
            ->getJson('/api/v1/platform/support-tickets')
            ->assertStatus(403);

        $this->as($support)
            ->getJson('/api/v1/platform/support-tickets')
            ->assertOk();
    }

    public function test_report_generation_is_audited_with_request_context(): void
    {
        [$tenant, $owner] = $this->tenant();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/reports/stocktake_jobs?from=2026-01-01&to=2026-12-31")
            ->assertOk();

        $log = AuditLog::where('action', 'report.generated')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame('stocktake_jobs', $log->after['report_type']);
    }
}
