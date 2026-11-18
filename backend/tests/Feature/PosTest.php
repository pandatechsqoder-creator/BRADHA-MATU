<?php

namespace Tests\Feature;

use App\Models\InventoryBalance;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosTest extends TestCase
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
     * @return array{tenant: Tenant, owner: User, variant: string}
     */
    private function tenantWithProduct(): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $product = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
                'name' => 'Sugar 1kg',
                'sku' => 'SUG-1KG',
                'brand' => 'Bratha Foods',
                'category' => 'Dry Goods',
                'base_unit' => 'piece',
                'cost_price' => 100,
            ])
            ->assertCreated()
            ->json('data');
        $variantId = $product['variants'][0]['id'];
        $variant = ProductVariant::where('uuid', $variantId)->firstOrFail();
        $variant->update(['attributes' => ['retail_price' => 120]]);
        ProductBarcode::create([
            'tenant_id' => $tenant->id,
            'variant_id' => $variant->id,
            'barcode' => '6001234500011',
            'type' => 'ean13',
            'is_primary' => true,
        ]);

        return ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variantId];
    }

    public function test_product_index_can_include_variants_for_pos_pickers(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProduct();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/products?include=variants")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sugar 1kg')
            ->assertJsonPath('data.0.variants.0.id', fn ($value) => is_string($value) && $value !== '');
    }

    public function test_pos_product_search_filters_and_paginates_with_tenant_and_terminal_scope(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProduct();
        $branch = \App\Models\Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Westlands',
            'status' => 'active',
        ]);
        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", [
                'name' => 'Westlands Till',
                'branch_id' => $branch->uuid,
            ])
            ->assertCreated()
            ->json('data');
        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
                'name' => 'Sugar Cubes',
                'sku' => 'SUG-CUBES',
                'brand' => 'Other Brand',
                'category' => 'Dry Goods',
                'base_unit' => 'piece',
            ])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/products?terminal_id={$terminal['id']}&search=Sugar&per_page=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2)
            ->assertJsonPath('data.terminal_id', $terminal['id'])
            ->assertJsonPath('data.branch_id', $branch->uuid);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/products?terminal_id={$terminal['id']}&search=6001234500011&brand=Bratha&category=Dry%20Goods")
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.variants.0.selling_price', 120)
            ->assertJsonPath('data.products.0.variants.0.barcodes.0.barcode', '6001234500011');

        $otherTenant = Tenant::create(['name' => 'Other Retail', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);
        $otherTerminal = $this->as($otherOwner, $otherTenant)
            ->postJson("/api/v1/tenants/{$otherTenant->uuid}/pos/terminals", ['name' => 'Other Till'])
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/products?terminal_id={$otherTerminal['id']}")
            ->assertStatus(422)
            ->assertJsonPath('error.details.terminal_id.0', 'Select a POS terminal belonging to this business.');
    }

    public function test_sale_uses_catalogue_price_and_keeps_financial_snapshots_after_price_changes(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variantId] = $this->tenantWithProduct();
        StockLocation::create(['tenant_id' => $tenant->id, 'name' => 'Shop floor']);
        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->assertCreated()
            ->json('data');
        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", [])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'items' => [[
                    'variant_id' => $variantId,
                    'quantity' => 2,
                    'unit_price' => 1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.items.0', 'The items.0.unit_price field is prohibited.');

        $sale = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'items' => [[
                    'variant_id' => $variantId,
                    'quantity' => 2,
                    'tax_amount' => 5,
                    'discount_amount' => 10,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', 120)
            ->assertJsonPath('data.items.0.line_total', 240)
            ->assertJsonPath('data.items.0.tax_amount', 5)
            ->assertJsonPath('data.items.0.discount_amount', 10)
            ->assertJsonPath('data.total_amount', 235)
            ->json('data');

        ProductVariant::where('uuid', $variantId)->firstOrFail()
            ->update(['attributes' => ['retail_price' => 300]]);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/sales/{$sale['id']}")
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 120)
            ->assertJsonPath('data.items.0.line_total', 240)
            ->assertJsonPath('data.items.0.tax_amount', 5)
            ->assertJsonPath('data.items.0.discount_amount', 10)
            ->assertJsonPath('data.total_amount', 235);
    }

    public function test_sale_fails_explicitly_when_catalogue_selling_price_is_not_configured(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variantId] = $this->tenantWithProduct();
        ProductVariant::where('uuid', $variantId)->firstOrFail()->update(['attributes' => []]);
        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->assertCreated()
            ->json('data');
        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", [])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'items' => [['variant_id' => $variantId, 'quantity' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath(
                'error.details.items.0',
                'No selling price is configured for Sugar 1kg. Add a retail_price, selling_price, unit_price or price to the catalogue variant before selling.',
            );
    }

    public function test_terminals_can_be_created_and_listed(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProduct();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", [
                'name' => 'Till 1',
                'device_id' => 'POS-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Till 1')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.branch_id', null);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Till 1');
    }

    public function test_shift_sale_receipt_close_and_reconcile_flow(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variant] = $this->tenantWithProduct();

        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->json('data');

        StockLocation::create([
            'tenant_id' => $tenant->id,
            'name' => 'Shop floor',
        ]);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'payment_method' => 'cash',
                'items' => [[
                    'variant_id' => $variant,
                    'quantity' => 1,
                ]],
            ])
            ->assertStatus(422)

            ->assertJsonPath('error.details.shift.0', fn ($v) => is_string($v) && $v !== '');

        $shift = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", [
                'opening_float' => 500,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data');

        $sale = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'payment_method' => 'cash',
                'cash_tendered' => 200,
                'items' => [[
                    'variant_id' => $variant,
                    'quantity' => 2,
                    'tax_amount' => 0,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 240)
            ->assertJsonPath('data.total_amount', 240)
            ->assertJsonPath('data.cash_change', 0)
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertNotNull($sale['receipt_number']);
        $this->assertNotEmpty($sale['items']);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'sale',
            'source' => 'brathamatu_pos',
        ]);

        $balance = InventoryBalance::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($balance);
        $this->assertSame(-2.0, (float) $balance->quantity);
        $this->assertNotNull($balance->stock_location_id);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/sales")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $sale['reference']);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/sales/{$sale['id']}")
            ->assertOk()
            ->assertJsonPath('data.receipt.receipt_number', $sale['receipt_number']);

        $closed = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/shifts/{$shift['id']}/close", [
                'actual_cash' => 740,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.expected_cash', 740)
            ->assertJsonPath('data.cash_variance', 0)
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/shifts/{$closed['id']}/reconciliation")
            ->assertOk()
            ->assertJsonPath('data.cash_sales', 240)
            ->assertJsonPath('data.transaction_count', 1);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/shifts/{$closed['id']}/reconcile")
            ->assertOk()
            ->assertJsonPath('data.status', 'reconciled');
    }

    public function test_supplier_and_purchase_recording(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variant] = $this->tenantWithProduct();

        $branch = \App\Models\Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Westlands Branch',
            'status' => 'active',
        ]);

        $supplier = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/suppliers", [
                'name' => 'Nairobi Wholesalers',
                'phone' => '254712345678',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Nairobi Wholesalers')
            ->json('data');

        $purchase = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/purchases", [
                'supplier_id' => $supplier['id'],
                'branch_id' => $branch->uuid,
                'status' => 'received',
                'items' => [[
                    'variant_id' => $variant,
                    'quantity' => 10,
                    'unit_cost' => 90,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal', 900)
            ->assertJsonPath('data.total_amount', 900)
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/purchases")
            ->assertOk()
            ->assertJsonPath('data.0.reference', $purchase['reference'])
            ->assertJsonPath('data.0.supplier', 'Nairobi Wholesalers');

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'purchase',
            'source' => 'brathamatu_pos',
            'reference' => $purchase['reference'],
        ]);

        $movement = \App\Models\InventoryMovement::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('reference', $purchase['reference'])
            ->firstOrFail();
        $this->assertNotNull($movement->stock_location_id);

        $location = StockLocation::withoutGlobalScopes()->findOrFail($movement->stock_location_id);
        $this->assertSame($branch->id, $location->branch_id);

        $balance = InventoryBalance::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('stock_location_id', $location->id)
            ->firstOrFail();
        $this->assertSame(10.0, (float) $balance->quantity);
    }

    public function test_customers_can_be_created_and_listed(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProduct();

        $customer = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/customers", [
                'name' => 'Jane Wanjiku',
                'phone' => '254711000111',
                'loyalty_id' => 'LOY-001',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Jane Wanjiku')
            ->assertJsonPath('data.loyalty_id', 'LOY-001')
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/customers?search=Jane")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $customer['id']);
    }

    public function test_sale_return_restores_stock_and_adjusts_cash_reconciliation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variant] = $this->tenantWithProduct();

        StockLocation::create(['tenant_id' => $tenant->id, 'name' => 'Shop floor']);

        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->json('data');

        $shift = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", ['opening_float' => 500])
            ->assertCreated()
            ->json('data');

        $sale = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'payment_method' => 'cash',
                'cash_tendered' => 240,
                'items' => [[
                    'variant_id' => $variant,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $return = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/returns", [
                'sale_id' => $sale['id'],
                'refund_method' => 'cash',
                'reason' => 'Damaged packaging',
                'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', 120)
            ->assertJsonPath('data.refund_method', 'cash')
            ->json('data');

        $this->assertDatabaseHas('pos_receipts', [
            'tenant_id' => $tenant->id,
            'type' => 'refund',
            'amount' => 120,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'return',
            'source' => 'brathamatu_pos',
            'reference' => $return['reference'],
        ]);

        $this->assertSame('partially_returned', \App\Models\PosSale::where('uuid', $sale['id'])->value('status'));
        $this->assertSame(-1.0, (float) InventoryBalance::where('tenant_id', $tenant->id)->first()->quantity);

        $closed = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/shifts/{$shift['id']}/close", ['actual_cash' => 620])
            ->assertOk()
            ->assertJsonPath('data.expected_cash', 620)
            ->assertJsonPath('data.cash_variance', 0)
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/shifts/{$closed['id']}/reconciliation")
            ->assertOk()
            ->assertJsonPath('data.cash_refunds', 120)
            ->assertJsonPath('data.return_count', 1);
    }

    public function test_branch_transfer_moves_stock_between_locations(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variant] = $this->tenantWithProduct();

        $branchA = \App\Models\Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main', 'code' => 'BR-A']);
        $branchB = \App\Models\Branch::create(['tenant_id' => $tenant->id, 'name' => 'Westlands', 'code' => 'BR-B']);
        StockLocation::create(['tenant_id' => $tenant->id, 'branch_id' => $branchA->id, 'name' => 'Main store']);
        StockLocation::create(['tenant_id' => $tenant->id, 'branch_id' => $branchB->id, 'name' => 'Westlands store']);

        $supplier = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/suppliers", ['name' => 'Nairobi Wholesalers'])
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/purchases", [
                'supplier_id' => $supplier['id'],
                'branch_id' => $branchA->uuid,
                'status' => 'received',
                'items' => [[
                    'variant_id' => $variant,
                    'product_name' => 'Sugar 1kg',
                    'quantity' => 10,
                    'unit_cost' => 90,
                ]],
            ])
            ->assertCreated();

        $transfer = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/transfers", [
                'from_branch_id' => $branchA->uuid,
                'to_branch_id' => $branchB->uuid,
                'status' => 'received',
                'items' => [['variant_id' => $variant, 'quantity' => 4]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'received')
            ->json('data');

        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'transfer_out',
            'reference' => $transfer['reference'],
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'tenant_id' => $tenant->id,
            'type' => 'transfer_in',
            'reference' => $transfer['reference'],
        ]);

        $balances = InventoryBalance::where('tenant_id', $tenant->id)->pluck('quantity', 'branch_id');
        $this->assertSame(6.0, (float) $balances[$branchA->id]);
        $this->assertSame(4.0, (float) $balances[$branchB->id]);
    }

    public function test_pos_sales_are_isolated_per_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'variant' => $variant] = $this->tenantWithProduct();
        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);

        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", ['opening_float' => 0])
            ->assertCreated();

        $sale = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'payment_method' => 'cash',
                'items' => [[
                    'variant_id' => $variant,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->as($otherOwner, $otherTenant)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/pos/sales/{$sale['id']}")
            ->assertStatus(404);
    }
}
