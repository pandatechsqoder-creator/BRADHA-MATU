<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\InventoryCutoff;
use App\Models\InventoryMovement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class InventoryTest extends TestCase
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

        return $this->withToken($user->createToken('test')->plainTextToken)->withHeader('X-Tenant-Id', $tenant->uuid);
    }

    /**
     * @return array{tenant: Tenant, owner: User, branch: string, location: string}
     */
    private function organisation(): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $branch = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/branches", [
            'name' => 'Nairobi HQ',
            'code' => 'NRB',
        ])->assertCreated()->json('data');

        $location = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/stock-locations", [
            'name' => 'Main Store',
            'code' => 'MAIN',
            'branch_id' => $branch['id'],
        ])->assertCreated()->json('data');

        return ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch['id'], 'location' => $location['id']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function product(User $user, Tenant $tenant, array $overrides = []): array
    {
        return $this->as($user, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
            'name' => 'Sugar 1kg',
            'sku' => 'SUG-1KG',
            'base_unit' => 'piece',
            'cost_price' => 120,
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_opening_sales_and_adjustments_update_balances_history_and_audit(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'reference' => 'OPEN-1',
            'occurred_at' => '2026-11-01 08:00:00',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 100, 'unit_cost' => 120]],
        ])->assertCreated()
            ->assertJsonPath('data.type', 'opening')
            ->assertJsonPath('data.lines.0.quantity', 100)
            ->assertJsonPath('data.lines.0.sku', 'SUG-1KG');

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'reference' => 'POS-1',
            'occurred_at' => '2026-11-02 09:00:00',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 10]],
        ])->assertCreated()->assertJsonPath('data.lines.0.quantity', -10);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'adjustment',
            'reference' => 'ADJ-1',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => -5]],
        ])->assertCreated()->assertJsonPath('data.lines.0.quantity', -5);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 85)
            ->assertJsonPath('data.0.value', 10200)
            ->assertJsonPath('data.0.sku', 'SUG-1KG')
            ->assertJsonPath('data.0.stock_location.name', 'Main Store');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory")
            ->assertOk()
            ->assertJsonPath('data.can_view_cost', true)
            ->assertJsonPath('data.stats.total_quantity', 85)
            ->assertJsonPath('data.stats.stock_value', 10200)
            ->assertJsonPath('data.stats.balance_rows', 1)
            ->assertJsonPath('data.stats.movements_30_days', 3)
            ->assertJsonCount(3, 'data.recent_movements');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements?type=sale")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lines.0.quantity', -10);

        $this->assertDatabaseHas('inventory_movements', ['tenant_id' => $tenant->id, 'type' => 'opening', 'line_count' => 1]);
        $this->assertDatabaseHas('inventory_balances', ['tenant_id' => $tenant->id, 'quantity' => 85]);
        $this->assertSame(3, AuditLog::query()->where('action', 'inventory.movement.created')->count());

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 0]],
        ])->assertStatus(422);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'lines' => [['sku' => 'MISSING', 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_workers_without_catalogue_update_cannot_see_cost_values(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant);
        $controller = $this->memberOf($tenant, 'stock-controller');

        $this->as($controller, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 12, 'unit_cost' => 120]],
        ])->assertCreated()
            ->assertJsonPath('data.lines.0.unit_cost', null);

        $this->as($controller, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory")
            ->assertOk()
            ->assertJsonPath('data.can_view_cost', false)
            ->assertJsonPath('data.stats.stock_value', null);

        $this->as($controller, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 12)
            ->assertJsonPath('data.0.value', null);

        $readOnly = $this->memberOf($tenant, 'tenant-read-only');
        $this->as($readOnly, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory")->assertForbidden();
        $this->as($readOnly, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_balance_search_supports_product_fields_letters_and_tenant_scoped_location_filters(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant, [
            'name' => 'Sunrise Tea',
            'sku' => 'SUN-TEA',
            'barcode' => '123456789',
            'brand' => 'Valley Foods',
            'category' => 'Beverages',
        ]);
        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'SUN-TEA', 'quantity' => 8]],
        ])->assertCreated();

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances?search=sun&search_by=name&letter=S")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_name', 'Sunrise Tea');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances?search=123456&search_by=barcode")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances?brand=Valley&category=Beverages&branch={$branch}&stock_status=in_stock")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $otherTenant = Tenant::create(['name' => 'Other Shop', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);
        $otherBranch = $this->as($otherOwner, $otherTenant)->postJson("/api/v1/tenants/{$otherTenant->uuid}/branches", [
            'name' => 'Other branch',
        ])->assertCreated()->json('data');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances?branch={$otherBranch['id']}")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_batch_and_serial_tracking_updates_tracking_registers(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant, [
            'name' => 'Yoghurt 500ml',
            'sku' => 'YOG-500',
            'tracking_mode' => 'batch',
        ]);
        $this->product($owner, $tenant, [
            'name' => 'Phone X',
            'sku' => 'PHX-1',
            'tracking_mode' => 'serial',
        ]);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'YOG-500', 'quantity' => 20]],
        ])->assertStatus(422);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'YOG-500', 'quantity' => 20, 'batch_number' => 'B-2026-01', 'expires_on' => '2027-01-31']],
        ])->assertCreated();

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'lines' => [['sku' => 'YOG-500', 'quantity' => 5, 'batch_number' => 'B-2026-01']],
        ])->assertCreated();

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/batches")
            ->assertOk()
            ->assertJsonPath('data.0.batch_number', 'B-2026-01')
            ->assertJsonPath('data.0.quantity', 15)
            ->assertJsonPath('data.0.expires_on', '2027-01-31');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/batches?expiry=expired")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'PHX-1', 'quantity' => 1, 'serial_number' => 'IMEI-0001']],
        ])->assertCreated();

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/serials")
            ->assertOk()
            ->assertJsonPath('data.0.serial_number', 'IMEI-0001')
            ->assertJsonPath('data.0.status', 'in_stock');

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'lines' => [['sku' => 'PHX-1', 'quantity' => 1, 'serial_number' => 'IMEI-0001']],
        ])->assertCreated();

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/serials?status=sold")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.serial_number', 'IMEI-0001');
    }

    public function test_cutoff_links_movements_and_snapshot_excludes_post_cutoff_activity(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'reference' => 'OPEN-1',
            'occurred_at' => '2026-11-01 10:00:00',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 100]],
        ])->assertCreated();

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'reference' => 'POS-BEFORE',
            'occurred_at' => '2026-11-04 10:00:00',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 5]],
        ])->assertCreated();

        $cutoff = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/cutoffs", [
            'stock_location_id' => $location,
            'cut_off_at' => '2026-11-05 00:00:00',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data');

        $sale = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'sale',
            'reference' => 'POS-AFTER',
            'occurred_at' => '2026-11-06 09:00:00',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 10]],
        ])->assertCreated()
            ->assertJsonPath('data.cutoff_id', $cutoff['id'])
            ->json('data');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/cutoffs/{$cutoff['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.movement_count', 1);

        $snapshot = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/snapshots", [
            'cutoff_id' => $cutoff['id'],
            'job_id' => 'JOB-1001',
            'reference' => 'Pre-count snapshot',
        ])->assertCreated()
            ->assertJsonPath('data.cutoff_id', $cutoff['id'])
            ->assertJsonPath('data.job_id', 'JOB-1001')
            ->assertJsonPath('data.item_count', 1)
            ->assertJsonPath('data.total_quantity', 95)
            ->assertJsonPath('data.items.0.expected_quantity', 95)
            ->json('data');

        $this->assertSame(64, strlen($snapshot['checksum']));
        $this->assertNotNull($snapshot['catalogue_version']);
        $this->assertSame('Main Store', $snapshot['stock_location']['name']);
        $this->assertSame('Nairobi HQ', $snapshot['branch']['name']);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/snapshots", [
            'cutoff_id' => $cutoff['id'],
            'reference' => 'Duplicate snapshot',
        ])->assertCreated()->assertJsonPath('data.id', $snapshot['id']);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/snapshots/{$snapshot['id']}/items")
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'SUG-1KG')
            ->assertJsonPath('data.0.expected_quantity', 95);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/cutoffs/{$cutoff['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.movement_count', 1);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/cutoffs", [
            'stock_location_id' => $location,
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_cutoff_movements', ['cutoff_id' => InventoryCutoff::where('uuid', $cutoff['id'])->value('id')]);
        $this->assertDatabaseHas('inventory_movements', ['id' => InventoryMovement::where('uuid', $sale['id'])->value('id'), 'cutoff_id' => InventoryCutoff::where('uuid', $cutoff['id'])->value('id')]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.snapshot.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.cutoff.closed']);
    }

    public function test_external_movement_import_groups_lines_dedupes_and_reports_bad_rows(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->organisation();
        $this->product($owner, $tenant);
        $this->product($owner, $tenant, ['name' => 'Cooking Oil 3L', 'sku' => 'OIL-3L', 'cost_price' => 650]);

        $csv = implode("\n", [
            'External ID,Type,Stock Location,Occurred At,SKU,Quantity,Batch Number,Expires On,Reference',
            'EXT-1,opening,Main Store,2026-11-01 08:00,SUG-1KG,50,,,OPEN-1',
            'EXT-1,opening,Main Store,2026-11-01 08:00,OIL-3L,10,,,OPEN-1',
            'EXT-2,sale,Main Store,2026-11-02 09:00,SUG-1KG,5,,,POS-1',
            'EXT-3,purchase,Unknown Store,2026-11-02 09:00,SUG-1KG,7,,,X',
            'EXT-4,sale,Main Store,2026-11-02 09:00,NOPE,1,,,Y',
        ]);

        $response = $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/inventory/movements/import",
            ['file' => UploadedFile::fake()->createWithContent('movements.csv', $csv)],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.created_movements', 2)
            ->assertJsonPath('data.skipped_movements', 0)
            ->assertJsonPath('data.error_rows', 2)
            ->json('data');

        $this->assertSame(2, count($response['errors']));

        $opening = collect($response['movements'])->firstWhere('external_id', 'EXT-1');
        $this->assertNotNull($opening);
        $this->assertSame(2, $opening['line_count']);
        $this->assertSame('import', $opening['source']);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/balances")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 45)
            ->assertJsonPath('data.1.quantity', 10);

        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.movements.imported']);

        $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/inventory/movements/import",
            ['file' => UploadedFile::fake()->createWithContent('movements.csv', $csv)],
            ['Accept' => 'application/json'],
        )->assertCreated()
            ->assertJsonPath('data.created_movements', 0)
            ->assertJsonPath('data.skipped_movements', 2)
            ->assertJsonPath('data.error_rows', 2);

        $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/inventory/movements/import",
            ['file' => UploadedFile::fake()->createWithContent('broken.csv', "Foo,Bar\n1,2")],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonPath('error.code', 'IMPORT_HEADERS_MISSING');
    }

    public function test_inventory_is_isolated_between_tenants(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'location' => $location] = $this->organisation();
        $this->product($owner, $tenant);

        $movement = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements", [
            'stock_location_id' => $location,
            'type' => 'opening',
            'lines' => [['sku' => 'SUG-1KG', 'quantity' => 3]],
        ])->assertCreated()->json('data');

        ['tenant' => $otherTenant, 'owner' => $otherOwner] = $this->organisation();

        $this->as($otherOwner, $otherTenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory")->assertForbidden();
        $this->as($otherOwner, $otherTenant)->getJson("/api/v1/tenants/{$tenant->uuid}/inventory/movements/{$movement['id']}")->assertNotFound();
        $this->as($otherOwner, $otherTenant)->getJson("/api/v1/tenants/{$otherTenant->uuid}/inventory/movements/{$movement['id']}")->assertNotFound();
    }
}
