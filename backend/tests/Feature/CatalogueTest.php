<?php

namespace Tests\Feature;

use App\Jobs\ProcessCatalogueImport;
use App\Models\Catalogue;
use App\Models\CatalogueImport;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\CatalogueImportProcessor;
use App\Services\Catalogue\TabularFileReader;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class CatalogueTest extends TestCase
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

        return $this->withToken($user->createToken('test')->plainTextToken)->withHeader('X-Tenant-Id', $tenant->uuid);
    }

    public function test_owner_can_create_product_declare_catalogue_and_receive_catalogue_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $product = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
            'name' => 'Maize Flour 2kg',
            'sku' => 'MF-2KG',
            'barcode' => '6161100000012',
            'brand' => 'Jogoo',
            'category' => 'Dry goods',
            'base_unit' => 'piece',
            'packaging_unit' => 'carton',
            'packaging_factor' => 12,
            'cost_price' => 150,
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'MF-2KG')
            ->assertJsonPath('data.category.name', 'Dry goods')
            ->assertJsonPath('data.packaging_factor', 12)
            ->json('data');

        $this->assertDatabaseHas('unit_conversions', ['factor' => 12]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created']);

        $summary = $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue")
            ->assertOk()
            ->assertJsonPath('data.product_count', 1)
            ->assertJsonPath('data.current_version.version_number', 1)
            ->assertJsonPath('data.readiness.ready', false)
            ->json('data');

        $estimate = $this->as($owner, $tenant)->getJson('/api/v1/catalogue-pricing/estimate?product_count=100')
            ->assertOk()
            ->assertJsonPath('data.total', 100)
            ->json('data');
        $this->assertSame('KES', $estimate['currency']);

        $declaration = $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/catalogues/{$summary['id']}/declarations",
            [
                'declaration_text' => 'I confirm the catalogue products, units and barcodes are accurate.',
                'exceptions' => 'Two new flavours are pending supplier confirmation.',
                'confirm_accuracy' => true,
            ],
        )->assertCreated()
            ->assertJsonPath('data.invoice.total', 100)
            ->assertJsonPath('data.declaration.version_number', 1)
            ->json('data');

        $this->assertSame('issued', $declaration['invoice']['status']);
        $this->assertDatabaseHas('catalogue_declarations', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('catalogue_invoices', ['tenant_id' => $tenant->id, 'status' => 'issued']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.declared']);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue")
            ->assertOk()
            ->assertJsonPath('data.readiness.ready', true)
            ->assertJsonPath('data.confirmed', true);

        $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/catalogues/{$summary['id']}/declarations",
            [
                'declaration_text' => 'Declaring the same version twice should be rejected.',
                'confirm_accuracy' => true,
            ],
        )->assertStatus(422)->assertJsonPath('error.code', 'ALREADY_DECLARED');

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/products/{$product['id']}")
            ->assertOk()
            ->assertJsonPath('data.cost_price', 150)
            ->assertJsonCount(1, 'data.variants');
    }

    public function test_csv_import_is_queued_mapped_committed_and_reported(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $csv = implode("\n", [
            'Product Name,SKU,Barcode,Category,Unit,Carton Size,Cost Price',
            'Sugar 1kg,SUG-1,6162000000011,Groceries,piece,24,120',
            'Cooking Oil 3L,OIL-3,6162000000028,Groceries,piece,6,650',
            ',MISSING-NAME,6162000000035,Groceries,piece,,80',
            'Duplicate Sugar,SUG-1,6162000000042,Groceries,piece,,120',
        ]);

        $response = $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports",
            ['file' => UploadedFile::fake()->createWithContent('products.csv', $csv), 'partial_policy' => 'partial'],
            ['Accept' => 'application/json'],
        )->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total_rows', 0)
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.error_rows', 0)
            ->assertJsonPath('data.duplicate_rows', 0);

        Queue::assertPushed(ProcessCatalogueImport::class);

        $import = CatalogueImport::where('tenant_id', $tenant->id)->firstOrFail();
        app(CatalogueImportProcessor::class)->process($import);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.total_rows', 4)
            ->assertJsonPath('data.error_rows', 2)
            ->assertJsonPath('data.mapping.name', 'Product Name')
            ->assertJsonPath('data.mapping.sku', 'SKU')
            ->assertJsonPath('data.mapping.packaging_factor', 'Carton Size');

        $errors = $this->as($owner, $tenant)->get("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}/errors");
        $errors->assertOk();
        $this->assertStringContainsString('Product name is required', $errors->getContent());

        $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}/commit",
            ['duplicate_strategy' => 'skip'],
        )->assertCreated()
            ->assertJsonPath('data.version.product_count', 2);

        $this->assertDatabaseHas('products', ['name' => 'Sugar 1kg']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'SUG-1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.import.committed']);

        $catalogue = Catalogue::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotNull($catalogue->current_version_id);
        $this->assertNull($catalogue->confirmed_at);

        $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}/commit",
            ['duplicate_strategy' => 'skip'],
        )->assertStatus(422);
    }

    public function test_duplicate_rows_can_be_updated_on_a_second_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports",
            ['file' => UploadedFile::fake()->createWithContent('one.csv', "Name,SKU,Unit\nSoap Bar,SOAP-1,piece\n")],
            ['Accept' => 'application/json'],
        )->assertStatus(202);

        $import = CatalogueImport::latest('id')->firstOrFail();
        app(CatalogueImportProcessor::class)->process($import);
        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}/commit")
            ->assertCreated();

        $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports",
            ['file' => UploadedFile::fake()->createWithContent('two.csv', "Name,SKU,Unit\nSoap Bar XL,SOAP-1,piece\nTowel,TOW-1,piece\n")],
            ['Accept' => 'application/json'],
        )->assertStatus(202);

        $second = CatalogueImport::latest('id')->firstOrFail();
        app(CatalogueImportProcessor::class)->process($second);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$second->uuid}")
            ->assertOk()
            ->assertJsonPath('data.duplicate_rows', 1)
            ->assertJsonPath('data.valid_rows', 1);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$second->uuid}/commit", [
            'duplicate_strategy' => 'update',
        ])->assertCreated();

        $this->assertDatabaseHas('products', ['name' => 'Soap Bar XL']);
        $this->assertDatabaseHas('product_variants', ['sku' => 'TOW-1']);
    }

    public function test_all_or_nothing_commit_is_blocked_while_errors_exist(): void
    {
        Queue::fake();
        Storage::fake('local');

        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $this->as($owner, $tenant)->post(
            "/api/v1/tenants/{$tenant->uuid}/catalogue/imports",
            [
                'file' => UploadedFile::fake()->createWithContent('strict.csv', "Name,SKU\nGood,GOOD-1\n,BAD-1\n"),
                'partial_policy' => 'all_or_nothing',
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(202);

        $import = CatalogueImport::latest('id')->firstOrFail();
        app(CatalogueImportProcessor::class)->process($import);

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/catalogue/imports/{$import->uuid}/commit")
            ->assertStatus(422);

        $this->assertDatabaseMissing('products', ['name' => 'Good']);
    }

    public function test_xlsx_rows_are_read_with_inline_strings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'brathamatu-xlsx-').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $sheet = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>Product Name</t></is></c>
      <c r="B1" t="inlineStr"><is><t>SKU</t></is></c>
      <c r="C1" t="inlineStr"><is><t>Unit</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="inlineStr"><is><t>Salt 1kg</t></is></c>
      <c r="B2" t="inlineStr"><is><t>SALT-1</t></is></c>
      <c r="C2" t="inlineStr"><is><t>piece</t></is></c>
    </row>
  </sheetData>
</worksheet>
XML;
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $zip->close();

        $parsed = app(TabularFileReader::class)->read($path, 'xlsx');

        $this->assertSame(['Product Name', 'SKU', 'Unit'], $parsed['headers']);
        $this->assertSame('Salt 1kg', $parsed['rows'][0]['Product Name']);
        $this->assertSame('SALT-1', $parsed['rows'][0]['SKU']);

        @unlink($path);
    }

    public function test_platform_admin_can_configure_catalogue_pricing_policy(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/v1/platform/catalogue-pricing')
            ->assertOk()
            ->assertJsonPath('data.policy.pricing_model', 'banded')
            ->assertJsonPath('data.policy.max_charge', 2000);

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->patchJson('/api/v1/platform/catalogue-pricing', ['max_charge' => 2500])
            ->assertOk()
            ->assertJsonPath('data.max_charge', 2500)
            ->assertJsonCount(2, 'data.versions');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/v1/catalogue-pricing/estimate?product_count=6000')
            ->assertOk()
            ->assertJsonPath('data.total', 2500);

        $this->assertDatabaseHas('audit_logs', ['action' => 'catalogue.pricing.updated']);
    }

    public function test_catalogue_records_are_tenant_isolated_and_permission_guarded(): void
    {
        $tenantA = Tenant::create(['name' => 'Tenant A', 'status' => 'active']);
        $tenantB = Tenant::create(['name' => 'Tenant B', 'status' => 'active']);
        $ownerA = $this->memberOf($tenantA);
        $ownerB = $this->memberOf($tenantB);
        $readOnly = $this->memberOf($tenantA, 'tenant-read-only');

        $product = $this->as($ownerA, $tenantA)->postJson("/api/v1/tenants/{$tenantA->uuid}/products", [
            'name' => 'Private Product',
            'sku' => 'PRIV-1',
        ])->assertCreated()->json('data');

        $this->as($ownerB, $tenantB)
            ->getJson("/api/v1/tenants/{$tenantB->uuid}/products/{$product['id']}")
            ->assertNotFound();

        $this->as($ownerB, $tenantB)
            ->getJson("/api/v1/tenants/{$tenantB->uuid}/products")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->as($readOnly, $tenantA)
            ->postJson("/api/v1/tenants/{$tenantA->uuid}/products", ['name' => 'Nope', 'sku' => 'NOPE-1'])
            ->assertStatus(403);

        $this->as($readOnly, $tenantA)
            ->getJson("/api/v1/tenants/{$tenantA->uuid}/catalogue")
            ->assertOk()
            ->assertJsonPath('data.product_count', 1);

        $this->assertTrue(Product::where('tenant_id', $tenantA->id)->where('name', 'Private Product')->exists());
        $this->assertDatabaseMissing('products', ['tenant_id' => $tenantB->id, 'name' => 'Private Product']);
    }

    public function test_product_update_publishes_version_audits_and_returns_object_attributes(): void
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $product = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
            'name' => 'Sugar 1kg',
            'sku' => 'SUG-1KG',
            'brand' => 'Kabras',
            'category' => 'Sugar',
            'base_unit' => 'pack',
            'cost_price' => 160,
        ])->assertCreated()
            ->assertJsonPath('data.variants.0.attributes', [])
            ->json('data');

        $this->as($owner, $tenant)->patchJson("/api/v1/tenants/{$tenant->uuid}/products/{$product['id']}", [
            'name' => 'Sugar 1kg Refined',
            'attributes' => ['colour' => 'white'],
            'status' => 'active',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Sugar 1kg Refined')
            ->assertJsonPath('data.version_created', true)
            ->assertJsonPath('data.variants.0.attributes.colour', 'white');

        $this->assertDatabaseHas('audit_logs', ['action' => 'product.updated']);
        $this->assertDatabaseHas('products', ['tenant_id' => $tenant->id, 'name' => 'Sugar 1kg Refined']);

        $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue")
            ->assertOk()
            ->assertJsonPath('data.current_version.version_number', 2);
    }
}
