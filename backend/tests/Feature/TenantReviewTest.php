<?php

namespace Tests\Feature;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\DisputeMessage;
use App\Models\JobAssignment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QaReview;
use App\Models\ReconciliationReport;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\StocktakingDispute;
use App\Models\Tenant;
use App\Models\TenantResultReview;
use App\Models\TenantResultReviewItem;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantReviewTest extends TestCase
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

    private function supervisor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        return $user;
    }

    private function tenantOwner(): User
    {
        $user = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
        $user->assignRole('business-owner', $this->tenant->id);

        return $user;
    }

    private function catalogue(): array
    {
        $unit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ea', 'name' => 'Each',
            'dimension' => 'count', 'factor_to_base' => 1, 'is_base' => true, 'status' => 'active',
        ]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Sugar 1kg', 'status' => 'active', 'tracking_mode' => 'quantity']);
        $variant = ProductVariant::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'sku' => 'SUG-1KG', 'base_unit_id' => $unit->id, 'status' => 'active']);

        return ['unit' => $unit, 'variant' => $variant];
    }

    /** Get a job all the way through counting, QA review, and QA pass → awaiting_tenant_review. */
    private function reviewedJob(): array
    {
        $worker = User::factory()->create();
        $worker->assignRole('verified-stock-taker');
        $profile = StockTakerProfile::create([
            'user_id' => $worker->id, 'legal_name' => 'Worker', 'phone' => '+254700000001',
            'city' => 'Nairobi', 'county' => 'Nairobi', 'level' => 'verified', 'status' => 'active',
            'verification_status' => 'verified', 'training_completed_at' => now(), 'experience_years' => 2,
        ]);
        $job = StocktakeJob::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'STJ-REV-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Review test job', 'status' => 'scheduled', 'priority' => 'normal',
            'count_method' => 'quantity', 'required_team_size' => 1, 'product_scope' => 'all',
            'scope' => ['label' => 'Store'], 'preferred_start_at' => now()->addHour(),
        ]);
        $assignment = JobAssignment::create([
            'job_id' => $job->id, 'tenant_id' => $this->tenant->id, 'profile_id' => $profile->id,
            'role' => 'counter', 'status' => 'accepted', 'compensation_amount' => 700, 'currency' => 'KES',
            'offer_expires_at' => now()->addDay(), 'offered_at' => now(), 'responded_at' => now(), 'accepted_at' => now(),
        ]);

        // Check in
        $mgr = $this->supervisor();
        $jobUuid = $job->uuid;
        $template = $this->as($mgr)->postJson('/api/v1/platform/procedure-templates', [
            'name' => 'Review SOP', 'description' => 'Test.', 'steps' => [['title' => 'Count']],
            'checklists' => [['name' => 'Pre', 'kind' => 'pre_start', 'required' => true,
                'items' => [['label' => 'Ready', 'response_type' => 'boolean', 'required' => true]]]],
        ])->assertCreated()->json('data.template');

        $this->as($mgr)->postJson("/api/v1/platform/stocktake-jobs/{$jobUuid}/procedure", ['template_id' => $template['id']])->assertCreated();
        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")->assertOk();
        $items = $this->as($worker)->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")->assertOk()->json('data.checklists.0.items');
        foreach ($items as $item) {
            $this->as($worker)->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$item['id']}", ['value' => 'yes'])->assertOk();
        }
        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])->assertOk();

        // Count + submit
        $catalogue = $this->catalogue();
        $session = $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-rev'])
            ->assertCreated()->json('data');
        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
            'idempotency_key' => 'rev-entry-1', 'variant_id' => $catalogue['variant']->uuid,
            'raw_quantity' => 42, 'raw_unit_id' => $catalogue['unit']->uuid,
        ])->assertOk();
        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/submit")->assertOk();

        // QA review → pass → awaiting_tenant_review
        $this->as($mgr)->postJson("/api/v1/platform/stocktake-jobs/{$jobUuid}/qa/review")->assertOk();
        $this->as($mgr)->postJson("/api/v1/platform/stocktake-jobs/{$jobUuid}/qa/complete", [
            'decision' => 'pass', 'summary' => 'All good.',
        ])->assertOk();

        $this->assertSame('awaiting_tenant_review', $job->fresh()->status);

        return ['job' => $job->fresh(), 'catalogue' => $catalogue, 'mgr' => $mgr];
    }

    public function test_tenant_can_view_review_after_qa_passes(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        // Publish results first (the QA pass transitions to awaiting_tenant_review)
        // The service auto-creates the review when accessed
        $review = $this->as($owner)
            ->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")
            ->assertOk()
            ->json('data');

        $this->assertSame('in_progress', $review['review']['status']);
        $this->assertNotEmpty($review['items']);
        $this->assertSame(1, $review['review']['total_items']);
        // Blind count — no expected quantity
        $this->assertNull($review['items'][0]['expected_quantity']);
    }

    public function test_tenant_accepting_results_transitions_to_completion_confirmed(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        // First view to auto-publish
        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/accept", [
                'notes' => 'Results look correct.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertSame('completion_confirmed', $job->fresh()->status);
    }

    public function test_tenant_can_request_correction(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/correction", [
                'notes' => 'Aisle 3 was not fully counted.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'requested_correction');

        $this->assertSame('tenant_correction_requested', $job->fresh()->status);
    }

    public function test_tenant_can_request_recount(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/recount", [
                'reason' => 'Multiple items appear incorrect.',
            ])
            ->assertOk();

        $this->assertSame('recount_required', $job->fresh()->status);
    }

    public function test_tenant_can_raise_dispute_with_message(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $dispute = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/dispute", [
                'category' => 'accuracy',
                'priority' => 'high',
                'reason' => 'Counted quantities do not match our records.',
                'message' => 'We believe several products in Aisle 1 were miscounted.',
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('open', $dispute['status']);
        $this->assertSame('accuracy', $dispute['category']);
        $this->assertSame('disputed', $job->fresh()->status);
        $this->assertSame(1, StocktakingDispute::where('job_id', $job->id)->count());
        $this->assertSame(1, DisputeMessage::where('dispute_id', StocktakingDispute::where('job_id', $job->id)->value('id'))->count());
    }

    public function test_tenant_can_flag_individual_item(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $itemId = TenantResultReviewItem::whereHas('review', fn ($q) => $q->where('job_id', $job->id))->value('uuid');

        $this->as($owner)
            ->putJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/items/{$itemId}/flag", [
                'comment' => 'This quantity seems too high.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'flagged');

        $this->assertSame('flagged', TenantResultReviewItem::where('uuid', $itemId)->value('status'));
    }

    public function test_reconciliation_report_can_be_generated(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        $recon = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/reconciliation")
            ->assertCreated()
            ->json('data');

        $this->assertSame('published', $recon['status']);
        $this->assertSame(1, $recon['total_items']);
        // Blind count — all items are 'unknown' type (no expected quantity)
        $this->assertSame(1, $recon['items_unknown']);
        $this->assertSame(0, $recon['items_matched']);
    }

    public function test_platform_can_resolve_dispute_and_transition_back(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $owner = $this->tenantOwner();
        // super-admin has all permissions including stocktake.dispute.resolve
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $this->as($owner)->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review")->assertOk();

        // Tenant raises dispute
        $dispute = $this->as($owner)
            ->postJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/review/dispute", [
                'category' => 'accuracy', 'reason' => 'Counts are wrong.',
            ])->assertCreated()->json('data');

        $this->assertSame('disputed', $job->fresh()->status);

        // Platform adds a message
        $this->as($admin)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/disputes/{$dispute['id']}/messages", [
                'message' => 'We are reviewing your dispute.',
            ])
            ->assertCreated();

        // Platform decides — overturn (favour tenant)
        $this->as($admin)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/disputes/{$dispute['id']}/decide", [
                'decision' => 'overturn',
                'reasoning' => 'Investigation confirms counting errors. A recount will be ordered.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame('awaiting_tenant_review', $job->fresh()->status);
    }

    public function test_isolation_foreign_tenant_cannot_view_review(): void
    {
        ['job' => $job] = $this->reviewedJob();
        $otherTenant = Tenant::create(['name' => 'Other', 'status' => 'active']);
        $otherOwner = User::factory()->create();
        $otherTenant->memberships()->create(['user_id' => $otherOwner->id, 'status' => 'active', 'joined_at' => now()]);
        $otherOwner->assignRole('business-owner', $otherTenant->id);

        $this->as($otherOwner)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/stocktake-jobs/{$job->uuid}/review")
            ->assertNotFound();
    }
}
