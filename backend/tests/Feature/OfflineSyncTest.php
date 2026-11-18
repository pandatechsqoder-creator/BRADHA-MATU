<?php

namespace Tests\Feature;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\CountSyncBatch;
use App\Models\CountSyncConflict;
use App\Models\JobAssignment;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, SopSeeder::class]);
        $this->tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
    }

    private function as(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    private function platformUser(string $role = 'supervisor'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function catalogue(): array
    {
        $unit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ea', 'name' => 'Each',
            'dimension' => 'count', 'factor_to_base' => 1, 'is_base' => true, 'status' => 'active',
        ]);
        $product = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Rice 2kg', 'status' => 'active', 'tracking_mode' => 'quantity',
        ]);
        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'sku' => 'RICE-2KG',
            'base_unit_id' => $unit->id, 'status' => 'active',
        ]);

        return ['unit' => $unit, 'product' => $product, 'variant' => $variant];
    }

    private function checkIn(User $workerUser, JobAssignment $assignment, User $manager): void
    {
        $jobUuid = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->value('uuid');
        $template = $this->as($manager)
            ->postJson('/api/v1/platform/procedure-templates', [
                'name' => 'Sync SOP', 'description' => 'Test.',
                'steps' => [['title' => 'Count quantities']],
                'checklists' => [[
                    'name' => 'Pre-count', 'kind' => 'pre_start', 'required' => true,
                    'items' => [['label' => 'Ready', 'response_type' => 'boolean', 'required' => true]],
                ]],
            ])
            ->assertCreated()->json('data.template');

        $this->as($manager)->postJson("/api/v1/platform/stocktake-jobs/{$jobUuid}/procedure", ['template_id' => $template['id']])->assertCreated();
        $this->as($workerUser)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")->assertOk();
        $items = $this->as($workerUser)->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")->assertOk()->json('data.checklists.0.items');
        foreach ($items as $item) {
            $this->as($workerUser)->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$item['id']}", ['value' => 'yes'])->assertOk();
        }
        $this->as($workerUser)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])->assertOk();
    }

    /** @return array{user: User, profile: StockTakerProfile, job: StocktakeJob, assignment: JobAssignment} */
    private function scheduledAssignment(): array
    {
        $user = User::factory()->create();
        $user->assignRole('verified-stock-taker');
        $profile = StockTakerProfile::create([
            'user_id' => $user->id, 'legal_name' => 'Worker', 'phone' => '+254700000001',
            'city' => 'Nairobi', 'county' => 'Nairobi', 'level' => 'verified', 'status' => 'active',
            'verification_status' => 'verified', 'training_completed_at' => now(), 'experience_years' => 2,
        ]);
        $job = StocktakeJob::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'STJ-SYNC-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Sync test job', 'status' => 'scheduled', 'priority' => 'normal',
            'count_method' => 'quantity', 'required_team_size' => 1, 'product_scope' => 'all',
            'scope' => ['label' => 'Store'], 'preferred_start_at' => now()->addHour(),
        ]);
        $assignment = JobAssignment::create([
            'job_id' => $job->id, 'tenant_id' => $this->tenant->id, 'profile_id' => $profile->id,
            'role' => 'counter', 'status' => 'accepted', 'compensation_amount' => 700, 'currency' => 'KES',
            'offer_expires_at' => now()->addDay(), 'offered_at' => now(), 'responded_at' => now(), 'accepted_at' => now(),
        ]);

        return ['user' => $user, 'profile' => $profile, 'job' => $job, 'assignment' => $assignment];
    }

    public function test_job_package_download_requires_ownership_and_returns_scope(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();
        $this->catalogue();

        $pkg = $this->as($worker)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/package")
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('scope', $pkg);
        $this->assertArrayHasKey('expires_at', $pkg);
        $this->assertNotEmpty($pkg['scope']);
        $this->assertArrayHasKey('approved_units', $pkg['scope'][0]);
        // Package must NOT contain financial data
        $this->assertStringNotContainsString('expected_quantity', json_encode($pkg));
        $this->assertStringNotContainsString('cost_price', json_encode($pkg));
        $this->assertStringNotContainsString('compensation', json_encode($pkg));
        $this->assertStringNotContainsString('margin', json_encode($pkg));
        $this->assertStringNotContainsString('valuation', json_encode($pkg));

        // Another worker cannot download the package for a different assignment
        ['user' => $other] = $this->scheduledAssignment();
        $this->as($other)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/package")
            ->assertNotFound();
    }

    public function test_batch_sync_creates_entries_idempotently_and_reports_per_item_status(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($worker, $assignment, $manager);

        $session = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-offline'])
            ->assertCreated()->json('data');

        $items = [
            [
                'idempotency_key' => 'offline-entry-1',
                'type' => 'entry',
                'variant_id' => $catalogue['variant']->uuid,
                'raw_quantity' => 50,
                'raw_unit_id' => $catalogue['unit']->uuid,
                'captured_at' => now()->toISOString(),
            ],
            [
                'idempotency_key' => 'offline-ex-1',
                'type' => 'exception',
                'type_detail' => 'mismatched_barcode',
                'description' => 'Barcode does not match shelf label.',
                'barcode' => '999999',
            ],
            [
                'idempotency_key' => 'offline-unk-1',
                'type' => 'unknown_product',
                'description' => 'Unknown item on shelf 3.',
                'barcode' => '888888',
            ],
        ];

        $result = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/sync", [
                'items' => $items,
                'device_session_id' => 'dev-abc',
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(3, $result['summary']['total']);
        $this->assertSame(3, $result['summary']['synced']);
        $this->assertSame(0, $result['summary']['rejected']);
        $this->assertSame(0, $result['summary']['conflict']);

        $entryResult = collect($result['items'])->firstWhere('idempotency_key', 'offline-entry-1');
        $this->assertSame('synced', $entryResult['status']);
        $this->assertTrue($entryResult['created']);
        $this->assertSame(1, CountEntry::where('idempotency_key', 'offline-entry-1')->count());
        $this->assertSame(1, CountSyncBatch::where('session_id', CountSession::where('uuid', $session['id'])->value('id'))->count());

        // Re-syncing the same batch is idempotent
        $second = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/sync", [
                'items' => [
                    ['idempotency_key' => 'offline-entry-1', 'type' => 'entry',
                     'variant_id' => $catalogue['variant']->uuid, 'raw_quantity' => 50,
                     'raw_unit_id' => $catalogue['unit']->uuid],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('synced', $second['items'][0]['status']);
        $this->assertFalse($second['items'][0]['created']);
        // No duplicate entry created
        $this->assertSame(1, CountEntry::where('idempotency_key', 'offline-entry-1')->count());
    }

    public function test_conflict_is_raised_when_offline_entry_differs_from_a_submitted_entry(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($worker, $assignment, $manager);

        $session = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-conflict'])
            ->assertCreated()->json('data');

        // Create an entry and submit it
        $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
                'idempotency_key' => 'conflict-entry-1',
                'variant_id' => $catalogue['variant']->uuid,
                'raw_quantity' => 100,
                'raw_unit_id' => $catalogue['unit']->uuid,
            ])->assertOk();

        $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/submit")
            ->assertOk();

        // Now try to sync the same key with a different quantity on the submitted session
        $result = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/sync", [
                'items' => [[
                    'idempotency_key' => 'conflict-entry-1',
                    'type' => 'entry',
                    'variant_id' => $catalogue['variant']->uuid,
                    'raw_quantity' => 999,  // differs from server
                    'raw_unit_id' => $catalogue['unit']->uuid,
                ]],
            ])
            ->assertOk()
            ->json('data');

        // Submitted session rejects new writes but acknowledges the key idempotently
        $this->assertSame('synced', $result['items'][0]['status']);
        // The original submitted value is preserved — server wins
        $this->assertSame('100.000000', (string) CountEntry::where('idempotency_key', 'conflict-entry-1')->value('raw_quantity'));
        $this->assertSame(1, CountSyncConflict::count());
        $this->assertSame(999, (int) CountSyncConflict::firstOrFail()->client_value['raw_quantity']);
    }

    public function test_batch_sync_rejects_out_of_scope_or_bad_unit_entries(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($worker, $assignment, $manager);

        $session = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-rej'])
            ->assertCreated()->json('data');

        $otherTenant = Tenant::create(['name' => 'Other', 'status' => 'active']);
        $otherUnit = UnitOfMeasure::create(['tenant_id' => $otherTenant->id, 'code' => 'ea', 'name' => 'Each', 'dimension' => 'count', 'factor_to_base' => 1, 'status' => 'active']);
        $otherProduct = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign', 'status' => 'active', 'tracking_mode' => 'quantity']);
        $otherVariant = ProductVariant::create(['tenant_id' => $otherTenant->id, 'product_id' => $otherProduct->id, 'sku' => 'F1', 'base_unit_id' => $otherUnit->id, 'status' => 'active']);

        $result = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/sync", [
                'items' => [
                    // Wrong tenant variant
                    ['idempotency_key' => 'bad-1', 'type' => 'entry', 'variant_id' => $otherVariant->uuid, 'raw_quantity' => 5, 'raw_unit_id' => $catalogue['unit']->uuid],
                    // Unapproved unit (using other-tenant's unit uuid)
                    ['idempotency_key' => 'bad-2', 'type' => 'entry', 'variant_id' => $catalogue['variant']->uuid, 'raw_quantity' => 5, 'raw_unit_id' => $otherUnit->uuid],
                ],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $result['summary']['rejected']);
    }

    public function test_heartbeat_records_device_session(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();

        $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/heartbeat", [
                'device_fingerprint' => 'fp-abc123',
                'platform' => 'Chrome/Windows',
                'offline_capable' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.acknowledged', true);
    }

    public function test_isolation_another_worker_cannot_sync_to_a_different_assignment(): void
    {
        ['user' => $worker, 'assignment' => $assignment] = $this->scheduledAssignment();
        ['user' => $other] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($worker, $assignment, $manager);

        $session = $this->as($worker)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-iso'])
            ->assertCreated()->json('data');

        $this->as($other)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/sync", [
                'items' => [[
                    'idempotency_key' => 'iso-1', 'type' => 'entry',
                    'variant_id' => $catalogue['variant']->uuid,
                    'raw_quantity' => 5, 'raw_unit_id' => $catalogue['unit']->uuid,
                ]],
            ])
            ->assertNotFound();
    }
}
