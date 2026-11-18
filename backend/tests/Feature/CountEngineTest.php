<?php

namespace Tests\Feature;

use App\Models\CountEntry;
use App\Models\CountEntryRevision;
use App\Models\CountException;
use App\Models\CountSession;
use App\Models\CountSubmissionLock;
use App\Models\JobAssignment;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\UnknownProductReport;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, SopSeeder::class]);
        $this->tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
    }

    private function platformUser(string $role = 'supervisor'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    /** @return array{user: User, profile: StockTakerProfile, job: StocktakeJob, assignment: JobAssignment} */
    private function scheduledAssignment(array $jobOverrides = []): array
    {
        $user = User::factory()->create();
        $user->assignRole('verified-stock-taker');

        $profile = StockTakerProfile::create([
            'user_id' => $user->id,
            'legal_name' => 'Verified Worker',
            'phone' => '+254700000001',
            'city' => 'Nairobi',
            'county' => 'Nairobi',
            'level' => 'verified',
            'status' => 'active',
            'verification_status' => 'verified',
            'training_completed_at' => now(),
            'experience_years' => 2,
        ]);

        $job = StocktakeJob::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'STJ-COUNT-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Quarter close count',
            'status' => 'scheduled',
            'priority' => 'normal',
            'count_method' => 'quantity',
            'preferred_start_at' => now()->addHour(),
            'required_team_size' => 1,
            'product_scope' => 'all',
            'scope' => ['label' => 'Nairobi Main Store'],
            ...$jobOverrides,
        ]);

        $assignment = JobAssignment::create([
            'job_id' => $job->id,
            'tenant_id' => $this->tenant->id,
            'profile_id' => $profile->id,
            'role' => 'counter',
            'status' => 'accepted',
            'compensation_amount' => 700,
            'currency' => 'KES',
            'offer_expires_at' => now()->addDay(),
            'offered_at' => now(),
            'responded_at' => now(),
            'accepted_at' => now(),
        ]);

        return ['user' => $user, 'profile' => $profile, 'job' => $job, 'assignment' => $assignment];
    }

    /** @return array{unit: UnitOfMeasure, packUnit: UnitOfMeasure, product: Product, variant: ProductVariant} */
    private function catalogue(): array
    {
        $unit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ea', 'name' => 'Each',
            'dimension' => 'count', 'factor_to_base' => 1, 'is_base' => true, 'status' => 'active',
        ]);
        $packUnit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'case', 'name' => 'Case of 12',
            'dimension' => 'count', 'factor_to_base' => 12, 'is_base' => false, 'status' => 'active',
        ]);
        $product = Product::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Maize Meal 1kg', 'status' => 'active',
            'tracking_mode' => 'quantity',
        ]);
        $variant = ProductVariant::create([
            'tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'sku' => 'MM-1KG',
            'base_unit_id' => $unit->id, 'packaging_unit_id' => $packUnit->id,
            'packaging_factor' => 12, 'status' => 'active',
        ]);
        ProductBarcode::create([
            'tenant_id' => $this->tenant->id, 'variant_id' => $variant->id,
            'barcode' => '501234567890', 'type' => 'ean13', 'is_primary' => true,
        ]);

        return ['unit' => $unit, 'packUnit' => $packUnit, 'product' => $product, 'variant' => $variant];
    }

    private function createTemplate(User $manager): array
    {
        return $this->as($manager)
            ->postJson('/api/v1/platform/procedure-templates', [
                'name' => 'Count SOP',
                'description' => 'Quantity-only count.',
                'steps' => [['title' => 'Count quantities only', 'instructions' => 'Never edit expected quantities.']],
                'checklists' => [[
                    'name' => 'Pre-count', 'kind' => 'pre_start', 'required' => true,
                    'items' => [['label' => 'Zones allocated', 'response_type' => 'boolean', 'required' => true]],
                ]],
            ])
            ->assertCreated()
            ->json('data.template');
    }

    /** Run the full start-of-count gate so the job is in_progress and counting is allowed. */
    private function checkIn(User $workerUser, JobAssignment $assignment, User $manager): void
    {
        $jobUuid = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->value('uuid');
        $template = $this->createTemplate($manager);
        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$jobUuid}/procedure", ['template_id' => $template['id']])
            ->assertCreated();

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")
            ->assertOk();

        $items = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->json('data.checklists.0.items');
        foreach ($items as $item) {
            $this->as($workerUser)
                ->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$item['id']}", ['value' => 'yes'])
                ->assertOk();
        }

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual', 'site_label' => 'Nairobi Main Store'])
            ->assertOk()
            ->assertJsonPath('data.readiness.can_start_count', true);
    }

    public function test_counting_requires_check_in_before_a_session_can_start(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $this->catalogue();
        $manager = $this->platformUser();

        // Workspace is reachable and reports the gate without throwing.
        $workspace = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/count")
            ->assertOk()
            ->assertJsonPath('data.mode', 'blind')
            ->json('data');

        $this->assertFalse($workspace['readiness']['can_start_count']);

        // Starting before check-in is rejected.
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", [])
            ->assertStatus(422);

        $this->checkIn($workerUser, $assignment, $manager);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated()
            ->assertJsonPath('data.mode', 'blind')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_blind_count_scope_exposes_no_expected_quantity_or_valuation(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated();

        $content = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/count")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('MM-1KG', $content);
        $this->assertStringNotContainsString('expected_quantity', $content);
        $this->assertStringNotContainsString('cost_price', $content);
        $this->assertStringNotContainsString('valuation', $content);
    }

    public function test_entries_are_idempotent_and_reject_out_of_scope_or_unapproved_units(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $session = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated()
            ->json('data');

        $payload = [
            'idempotency_key' => 'entry-1',
            'variant_id' => $catalogue['variant']->uuid,
            'raw_quantity' => 24,
            'raw_unit_id' => $catalogue['unit']->uuid,
        ];

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", $payload)
            ->assertOk()
            ->assertJsonPath('data.normalized_quantity', '24.000000');

        // Same idempotency key updates the same row (no duplicate).
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", array_merge($payload, ['raw_quantity' => 30]))
            ->assertOk()
            ->assertJsonPath('data.raw_quantity', '30.000000');

        $this->assertSame(1, CountEntry::where('session_id', CountSession::where('uuid', $session['id'])->value('id'))->count());

        // Negative quantity is rejected.
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", array_merge($payload, ['idempotency_key' => 'entry-neg', 'raw_quantity' => -1]))
            ->assertStatus(422);

        // An unapproved unit is rejected.
        $otherUnit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'pallet', 'name' => 'Pallet',
            'dimension' => 'count', 'factor_to_base' => 600, 'status' => 'active',
        ]);
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", array_merge($payload, ['idempotency_key' => 'entry-badunit', 'raw_unit_id' => $otherUnit->uuid]))
            ->assertStatus(422);

        // A foreign-tenant variant is out of scope.
        $foreign = Tenant::create(['name' => 'Other Retail', 'status' => 'active']);
        $fUnit = UnitOfMeasure::create(['tenant_id' => $foreign->id, 'code' => 'ea', 'name' => 'Each', 'dimension' => 'count', 'factor_to_base' => 1, 'status' => 'active']);
        $fProduct = Product::create(['tenant_id' => $foreign->id, 'name' => 'Foreign', 'status' => 'active', 'tracking_mode' => 'quantity']);
        $fVariant = ProductVariant::create(['tenant_id' => $foreign->id, 'product_id' => $fProduct->id, 'sku' => 'FR-1', 'base_unit_id' => $fUnit->id, 'status' => 'active']);
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", array_merge($payload, ['idempotency_key' => 'entry-foreign', 'variant_id' => $fVariant->uuid]))
            ->assertStatus(422);
    }

    public function test_revisions_preserve_the_original_entry(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $session = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated()->json('data');

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
                'idempotency_key' => 'entry-1', 'variant_id' => $catalogue['variant']->uuid,
                'raw_quantity' => 24, 'raw_unit_id' => $catalogue['unit']->uuid,
            ])->assertOk();

        $entryUuid = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}")
            ->assertOk()
            ->json('data.entries.0.id');

        $this->as($workerUser)
            ->patchJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries/{$entryUuid}", [
                'raw_quantity' => 25, 'raw_unit_id' => $catalogue['unit']->uuid, 'reason' => 'Recounted after second pass.',
            ])
            ->assertOk()
            ->assertJsonPath('data.revision_count', 1)
            ->assertJsonPath('data.raw_quantity', '25.000000');

        $entryId = CountEntry::where('uuid', $entryUuid)->value('id');
        $this->assertSame(1, CountEntryRevision::where('entry_id', $entryId)->count());
        $this->assertSame('24.000000', (string) CountEntryRevision::where('entry_id', $entryId)->value('raw_quantity'));
    }

    public function test_exceptions_and_unknown_products_can_be_reported(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $session = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated()->json('data');

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/exceptions", [
                'barcode' => '501234567890', 'type' => 'mismatched_barcode',
                'description' => 'Barcode scans to a different product than labelled.', 'location' => 'Aisle 1',
            ])->assertCreated();

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/unknown-products", [
                'barcode' => '999888777666', 'name_guess' => 'Unknown detergent',
                'description' => 'Found on shelf, not in the catalogue.', 'location' => 'Aisle 2',
            ])->assertCreated();
    }

    public function test_exceptions_and_unknown_products_resolve_public_ids_to_internal_keys(): void
    {
        ['user' => $workerUser, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $session = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-ids'])
            ->assertCreated()->json('data');

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/exceptions", [
                'variant_id' => $catalogue['variant']->uuid,
                'observed_unit_id' => $catalogue['packUnit']->uuid,
                'type' => 'mismatched_barcode',
                'description' => 'Barcode scans to a different product than labelled.',
            ])->assertCreated();

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/unknown-products", [
                'name_guess' => 'Unknown detergent',
                'description' => 'Found on shelf, not in the catalogue.',
                'observed_unit_id' => $catalogue['unit']->uuid,
            ])->assertCreated();

        $exception = CountException::firstOrFail();
        $this->assertSame($catalogue['variant']->id, $exception->variant_id);
        $this->assertSame($catalogue['product']->id, $exception->product_id);
        $this->assertSame($catalogue['packUnit']->id, $exception->observed_unit_id);

        $report = UnknownProductReport::firstOrFail();
        $this->assertSame($catalogue['unit']->id, $report->observed_unit_id);
    }

    public function test_submit_locks_entries_and_moves_the_job_to_count_submitted(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();
        $catalogue = $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $session = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated()->json('data');

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
                'idempotency_key' => 'entry-1', 'variant_id' => $catalogue['variant']->uuid,
                'raw_quantity' => 24, 'raw_unit_id' => $catalogue['unit']->uuid,
            ])->assertOk();

        $resp = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/submit");
        $respBody = $resp->json();
        $this->assertSame(200, $resp->status(), json_encode($respBody));
        $resp->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.locked', true);
        $resp->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.locked', true);

        $sessionId = CountSession::where('uuid', $session['id'])->value('id');
        $this->assertSame(1, CountSubmissionLock::where('session_id', $sessionId)->count());
        $this->assertSame('submitted', CountEntry::where('session_id', $sessionId)->value('status'));
        $this->assertSame('count_submitted', $job->fresh()->status);

        // Resubmitting is idempotent.
        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/submit")
            ->assertOk();
    }

    public function test_platform_and_tenant_can_view_counts_and_isolation_holds(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();
        $this->catalogue();
        $manager = $this->platformUser();
        $this->checkIn($workerUser, $assignment, $manager);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-1'])
            ->assertCreated();

        // Platform view.
        $platform = $this->as($manager)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/counts")
            ->assertOk()
            ->assertJsonPath('data.summary.sessions', 1)
            ->getContent();
        $this->assertStringNotContainsString('compensation', $platform);
        $this->assertStringNotContainsString('margin', $platform);

        // Tenant view.
        $owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $owner->id, 'status' => 'active', 'joined_at' => now()]);
        $owner->assignRole('business-owner', $this->tenant->id);

        $tenant = $this->as($owner)
            ->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/counts")
            ->assertOk()
            ->assertJsonPath('data.summary.sessions', 1)
            ->getContent();
        $this->assertStringNotContainsString('compensation', $tenant);

        // A foreign tenant cannot see the job (404, no existence leak).
        $otherTenant = Tenant::create(['name' => 'Other Retail', 'status' => 'active']);
        $this->as($owner)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/stocktake-jobs/{$job->uuid}/counts")
            ->assertNotFound();

        // A worker cannot reach the platform counts endpoint.
        $this->as($workerUser)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/counts")
            ->assertForbidden();
    }
}
