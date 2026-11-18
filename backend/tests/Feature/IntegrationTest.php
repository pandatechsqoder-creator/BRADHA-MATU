<?php

namespace Tests\Feature;

use App\Models\IntegrationConnection;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationTest extends TestCase
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

    private function as(User $user, Tenant $tenant): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->uuid);
    }

    /**
     * @return array{tenant: Tenant, owner: User, connection: array}
     */
    private function tenantWithConnection(): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $connection = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations", [
                'name' => 'Legacy POS',
                'provider' => 'erpnext',
                'type' => 'csv',
                'credentials' => ['token' => 'super-secret-token'],
                'webhook_secret' => 'super-secret-webhook',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Legacy POS')
            ->assertJsonPath('data.has_credentials', true)
            ->assertJsonPath('data.has_webhook_secret', true)
            ->json('data');

        return ['tenant' => $tenant, 'owner' => $owner, 'connection' => $connection];
    }

    public function test_connections_are_listed_without_leaking_secrets(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithConnection();

        $response = $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.provider', 'erpnext');

        $this->assertStringNotContainsString('super-secret-token', $response->getContent());
        $this->assertStringNotContainsString('super-secret-webhook', $response->getContent());
    }

    public function test_csv_import_upserts_catalogue_and_stock_movements(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'connection' => $connection] = $this->tenantWithConnection();

        $this->as($owner, $tenant)
            ->putJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/mappings/products", [
                'field_map' => ['name' => 'item_name', 'sku' => 'item_code', 'cost_price' => 'buying_price'],
            ])
            ->assertOk()
            ->assertJsonPath('data.field_map.sku', 'item_code');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'direction' => 'import',
                'rows' => [
                    ['item_name' => 'Imported Rice', 'item_code' => 'IMP-001', 'buying_price' => 120, 'unit' => 'kg', 'barcode' => '9988776655443'],
                    ['item_name' => 'Imported Soap', 'item_code' => 'IMP-002', 'buying_price' => 45],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.records_upserted', 2);

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'Imported Rice')
            ->firstOrFail();
        $this->assertSame('erpnext', $product->source_system);
        $this->assertSame(120.0, (float) $product->cost_price);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'movements',
                'direction' => 'import',
                'rows' => [
                    ['sku' => 'IMP-001', 'quantity' => 12, 'reference' => 'EXT-OPEN-1', 'unit_cost' => 100],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.records_upserted', 1);

        $movement = InventoryMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('reference', 'EXT-OPEN-1')
            ->firstOrFail();
        $this->assertSame('external_sync', $movement->type);
        $this->assertSame('integration:erpnext', $movement->source);
        $this->assertNotNull($movement->stock_location_id);

        $balance = InventoryBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();
        $this->assertSame(12.0, (float) $balance->quantity);
    }

    public function test_name_conflicts_are_recorded_and_resolvable(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'connection' => $connection] = $this->tenantWithConnection();

        $rows = [['name' => 'Imported Rice', 'sku' => 'IMP-001']];
        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'rows' => $rows,
            ])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'rows' => [['name' => 'Renamed Rice', 'sku' => 'IMP-001']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.conflict_count', 1);

        $conflict = $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/conflicts?resolution=pending")
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'name_mismatch')
            ->json('data.0');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/conflicts/{$conflict['id']}/resolve", [
                'resolution' => 'accept_external',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'accept_external');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'rows' => [['name' => 'Renamed Rice', 'sku' => 'IMP-001']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('products', [
            'tenant_id' => $tenant->id,
            'name' => 'Renamed Rice',
        ]);
    }

    public function test_webhooks_require_a_valid_signature_and_process_events(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'connection' => $connection] = $this->tenantWithConnection();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'rows' => [['name' => 'Webhook Item', 'sku' => 'WH-001']],
            ])
            ->assertCreated();

        $uuid = IntegrationConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('uuid');

        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        $payload = ['event' => 'stock.movement', 'data' => ['sku' => 'WH-001', 'quantity' => 4, 'reference' => 'WH-MOVE-1']];
        $body = json_encode($payload);

        $this->call('POST', "/api/v1/integrations/webhooks/{$uuid}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-BRATHAMATU-SIGNATURE' => 'deadbeef',
        ], $body)->assertStatus(401);

        $this->call('POST', "/api/v1/integrations/webhooks/{$uuid}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-BRATHAMATU-SIGNATURE' => hash_hmac('sha256', $body, 'super-secret-webhook'),
        ], $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'processed');

        $connectionId = IntegrationConnection::withoutGlobalScopes()->where('uuid', $uuid)->value('id');
        $this->assertDatabaseHas('integration_webhook_events', [
            'connection_id' => $connectionId,
            'event' => 'stock.movement',
            'signature_valid' => true,
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'reference' => 'WH-MOVE-1',
            'type' => 'external_sync',
        ]);
    }

    public function test_integrations_are_isolated_per_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'connection' => $connection] = $this->tenantWithConnection();

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);

        $this->as($otherOwner, $otherTenant)
            ->postJson("/api/v1/tenants/{$otherTenant->uuid}/integrations/{$connection['id']}/sync", [
                'entity' => 'products',
                'rows' => [['name' => 'Sneaky', 'sku' => 'SNK-1']],
            ])
            ->assertStatus(404);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
