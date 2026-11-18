<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosStocktakeTest extends TestCase
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
     * @return array{tenant: Tenant, owner: User, products: array<int, array<string, mixed>>}
     */
    private function tenantWithProducts(int $count = 5): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);
        $products = [];

        for ($i = 1; $i <= $count; $i++) {
            $products[] = $this->as($owner, $tenant)
                ->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
                    'name' => "Product {$i}",
                    'sku' => "SKU-{$i}",
                    'base_unit' => 'piece',
                    'cost_price' => 100,
                ])
                ->assertCreated()
                ->json('data');
        }

        return ['tenant' => $tenant, 'owner' => $owner, 'products' => $products];
    }

    public function test_catalogue_readiness_blocks_when_too_few_products(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(2);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/check-catalogue-readiness")
            ->assertOk()
            ->assertJsonPath('data.catalogue_readiness', false)
            ->assertJsonPath('data.product_count', 2)
            ->assertJsonPath('data.can_commission', false)
            ->assertJsonPath('data.blocks.0', 'insufficient_products');
    }

    public function test_catalogue_readiness_passes_with_minimum_products(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(5);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/check-catalogue-readiness")
            ->assertOk()
            ->assertJsonPath('data.catalogue_readiness', true)
            ->assertJsonPath('data.can_commission', true);
    }

    public function test_commission_authorize_and_decline_flow(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(5);

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", [
                'title' => 'POS cycle count',
                'mode' => 'proposed',
                'frequency' => 'on_demand',
                'pos_reference' => 'POS-REF-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'commissioned_pending_authorisation')
            ->assertJsonPath('data.pos_commissioned', true)
            ->assertJsonPath('data.mode', 'proposed')
            ->json('data');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/jobs")
            ->assertOk()
            ->assertJsonPath('data.0.reference', $job['reference']);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/{$job['job_id']}/authorize")
            ->assertOk()
            ->assertJsonPath('data.status', 'commissioned_authorised');

        $second = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", [
                'title' => 'POS cycle count 2',
            ])
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/{$second['job_id']}/decline", [
                'reason' => 'Catalogue cleanup pending',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancellation_reason', 'Catalogue cleanup pending');
    }

    public function test_commission_is_blocked_until_catalogue_is_ready(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(2);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", [
                'title' => 'POS cycle count',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.details.catalogue_readiness.0', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_commission_is_blocked_while_an_open_commission_exists(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(5);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", ['title' => 'First'])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", ['title' => 'Second'])
            ->assertStatus(422)
            ->assertJsonPath('error.details.job.0', fn ($v) => is_string($v) && $v !== '');
    }

    public function test_fetch_eligibility_returns_operational_context(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(5);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/fetch-eligibility")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'catalogue_readiness',
                    'product_count',
                    'minimum_required',
                    'has_active_shift',
                    'existing_commissioned_jobs',
                    'prepaid_eligible',
                    'can_commission',
                    'blocks',
                ],
            ]);
    }

    public function test_generate_quote_from_pos_sale(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'products' => $products] = $this->tenantWithProducts(1);
        $variantId = $products[0]['variants'][0]['id'];

        $terminal = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals", ['name' => 'Till 1'])
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/terminals/{$terminal['id']}/shifts/open", [
                'opening_float' => 1000,
            ])
            ->assertCreated();

        $sale = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/sales", [
                'terminal_id' => $terminal['id'],
                'payment_method' => 'cash',
                'cash_tendered' => 500,
                'items' => [[
                    'variant_id' => $variantId,
                    'product_name' => 'Product 1',
                    'quantity' => 2,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/generate-quote/{$sale['id']}")
            ->assertOk()
            ->assertJsonPath('data.subtotal', 200)
            ->assertJsonPath('data.currency', 'KES')
            ->assertJsonStructure([
                'data' => [
                    'quote_number',
                    'quote_version',
                    'subtotal',
                    'tax_amount',
                    'discount_amount',
                    'total',
                    'currency',
                    'valid_until',
                    'issued_at',
                    'items',
                ],
            ]);
    }

    public function test_other_tenants_cannot_authorize_a_job(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->tenantWithProducts(5);

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/pos/stocktake/commission", ['title' => 'Count'])
            ->json('data');

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);

        $this->as($otherOwner, $otherTenant)
            ->postJson("/api/v1/tenants/{$otherTenant->uuid}/pos/stocktake/{$job['job_id']}/authorize")
            ->assertStatus(404);
    }
}
