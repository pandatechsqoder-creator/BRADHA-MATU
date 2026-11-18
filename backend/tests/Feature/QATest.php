<?php

namespace Tests\Feature;

use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\Discrepancy;
use App\Models\JobAssignment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\QaFinding;
use App\Models\QaReview;
use App\Models\RecountRequest;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\VerificationTask;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QATest extends TestCase
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

    private function catalogue(): array
    {
        $unit = UnitOfMeasure::create([
            'tenant_id' => $this->tenant->id, 'code' => 'ea', 'name' => 'Each',
            'dimension' => 'count', 'factor_to_base' => 1, 'is_base' => true, 'status' => 'active',
        ]);
        $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Beans 1kg', 'status' => 'active', 'tracking_mode' => 'quantity']);
        $variant = ProductVariant::create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'sku' => 'BEANS-1KG', 'base_unit_id' => $unit->id, 'status' => 'active']);

        return ['unit' => $unit, 'variant' => $variant];
    }

    /** Set up a job that has been counted and submitted (status = count_submitted). */
    private function submittedJob(): array
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
            'reference' => 'STJ-QA-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'QA test job', 'status' => 'scheduled', 'priority' => 'normal',
            'count_method' => 'quantity', 'required_team_size' => 1, 'product_scope' => 'all',
            'scope' => ['label' => 'Store'], 'preferred_start_at' => now()->addHour(),
        ]);
        $assignment = JobAssignment::create([
            'job_id' => $job->id, 'tenant_id' => $this->tenant->id, 'profile_id' => $profile->id,
            'role' => 'counter', 'status' => 'accepted', 'compensation_amount' => 700, 'currency' => 'KES',
            'offer_expires_at' => now()->addDay(), 'offered_at' => now(), 'responded_at' => now(), 'accepted_at' => now(),
        ]);

        // Check in (apply SOP, acknowledge, checklist, check-in)
        $mgr = $this->supervisor();
        $jobUuid = $job->uuid;
        $template = $this->as($mgr)->postJson('/api/v1/platform/procedure-templates', [
            'name' => 'QA SOP', 'description' => 'Test.', 'steps' => [['title' => 'Count']],
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

        // Start session, add entries, submit
        $catalogue = $this->catalogue();
        $session = $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions", ['idempotency_key' => 'sess-qa'])
            ->assertCreated()->json('data');

        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
            'idempotency_key' => 'qa-entry-1', 'variant_id' => $catalogue['variant']->uuid,
            'raw_quantity' => 50, 'raw_unit_id' => $catalogue['unit']->uuid,
        ])->assertOk();

        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/entries", [
            'idempotency_key' => 'qa-entry-2', 'variant_id' => $catalogue['variant']->uuid,
            'raw_quantity' => 30, 'raw_unit_id' => $catalogue['unit']->uuid,
        ])->assertOk();

        $this->as($worker)->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/count/sessions/{$session['id']}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        return ['job' => $job->fresh(), 'worker' => $worker, 'session' => $session, 'catalogue' => $catalogue, 'mgr' => $mgr];
    }

    public function test_qa_review_starts_and_transitions_to_quality_review(): void
    {
        ['job' => $job] = $this->submittedJob();
        $sup = $this->supervisor();

        $review = $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")
            ->assertOk()
            ->json('data');

        $this->assertSame('in_progress', $review['review']['status']);
        $this->assertSame('quality_review', $job->fresh()->status);
        $this->assertNotEmpty($review['entries']);
        $this->assertCount(2, $review['entries']);
        // Worker accuracy payload is present
        $this->assertArrayHasKey('worker_accuracy', $review);
        $this->assertSame(2, $review['worker_accuracy']['total_entries']);
    }

    public function test_finding_creates_discrepancy_and_tracks_classification(): void
    {
        ['job' => $job, 'catalogue' => $catalogue] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/findings", [
                'variant_id' => $catalogue['variant']->uuid,
                'type' => 'excess',
                'counted_quantity' => 50,
                'verified_quantity' => 48,
                'severity' => 'moderate',
                'classification' => 'worker_error',
                'notes' => 'Worker overcounted by 2.',
            ])
            ->assertCreated();

        $this->assertSame(1, QaFinding::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, Discrepancy::where('job_id', $job->id)->count());
        $this->assertSame('worker_error', Discrepancy::where('job_id', $job->id)->value('type'));
        $this->assertEquals(-2, (float) Discrepancy::where('job_id', $job->id)->value('variance'));
    }

    public function test_completing_review_with_pass_moves_to_awaiting_tenant_review(): void
    {
        ['job' => $job] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/complete", [
                'decision' => 'pass',
                'summary' => 'All entries verified. No discrepancies.',
            ])
            ->assertOk();

        $this->assertSame('awaiting_tenant_review', $job->fresh()->status);
        $this->assertSame('passed', QaReview::where('job_id', $job->id)->value('status'));
    }

    public function test_completing_review_with_recount_creates_request_and_transitions(): void
    {
        ['job' => $job] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/complete", [
                'decision' => 'recount',
                'summary' => 'Multiple discrepancies found.',
                'recount_reason' => '3 out of 10 entries had significant variance.',
                'cost_responsibility' => 'worker_error',
            ])
            ->assertOk();

        $this->assertSame('recount_required', $job->fresh()->status);
        $this->assertSame(1, RecountRequest::where('job_id', $job->id)->count());
        $this->assertSame('worker_error', RecountRequest::where('job_id', $job->id)->value('cost_responsibility'));
    }

    public function test_verification_task_samples_entries_and_records_matches_and_mismatches(): void
    {
        ['job' => $job] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        $task = $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/verification-tasks", [
                'sample_size' => 2,
            ])
            ->assertCreated()
            ->json('data');

        $this->assertSame('pending', $task['status']);
        $this->assertSame(2, $task['sample_size']);
        $this->assertCount(2, $task['samples']);

        // Record first sample as a match
        $sample1 = $task['samples'][0];
        $this->as($sup)
            ->putJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/verification-tasks/{$task['id']}/samples/{$sample1['id']}", [
                'verified_quantity' => $sample1['original_quantity'],
            ])
            ->assertOk()
            ->assertJsonPath('data.result', 'match');

        // Record second sample as a mismatch
        $sample2 = $task['samples'][1];
        $orig2 = (float) $sample2['original_quantity'];
        $this->as($sup)
            ->putJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/verification-tasks/{$task['id']}/samples/{$sample2['id']}", [
                'verified_quantity' => $orig2 + 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.result', 'mismatch');

        // Complete the task
        $completed = $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/verification-tasks/{$task['id']}/complete")
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $completed['status']);
        $this->assertSame(1, $completed['items_matched']);
        $this->assertSame(1, $completed['items_mismatched']);
    }

    public function test_approving_recount_reopens_job_for_counting(): void
    {
        ['job' => $job] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/complete", [
            'decision' => 'recount', 'recount_reason' => 'Discrepancies found.', 'cost_responsibility' => 'worker_error',
        ])->assertOk();

        $this->assertSame('recount_required', $job->fresh()->status);
        $recountId = RecountRequest::where('job_id', $job->id)->value('uuid');

        $this->as($sup)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/recount-requests/{$recountId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('in_progress', $job->fresh()->status);
    }

    public function test_resolving_discrepancy_classifies_type(): void
    {
        ['job' => $job, 'catalogue' => $catalogue] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();
        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/findings", [
            'variant_id' => $catalogue['variant']->uuid, 'type' => 'excess',
            'counted_quantity' => 50, 'verified_quantity' => 45,
            'classification' => 'unknown', 'notes' => 'Variance of 5.',
        ])->assertCreated();

        $discrepancyId = Discrepancy::where('job_id', $job->id)->value('uuid');

        $this->as($sup)
            ->postJson("/api/v1/platform/discrepancies/{$discrepancyId}/resolve", [
                'type' => 'expected_variance',
                'resolution' => 'Investigated: stock movement after cut-off explains the variance. Not a worker error.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertSame('expected_variance', Discrepancy::where('uuid', $discrepancyId)->value('type'));
        $this->assertSame('resolved', Discrepancy::where('uuid', $discrepancyId)->value('status'));
    }

    public function test_worker_accuracy_distinguishes_worker_error_from_expected_variance(): void
    {
        ['job' => $job, 'catalogue' => $catalogue] = $this->submittedJob();
        $sup = $this->supervisor();

        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk();

        // Worker error
        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/findings", [
            'variant_id' => $catalogue['variant']->uuid, 'type' => 'excess',
            'counted_quantity' => 50, 'verified_quantity' => 48,
            'classification' => 'worker_error',
        ])->assertCreated();

        // Expected variance (not worker error)
        $this->as($sup)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/findings", [
            'variant_id' => $catalogue['variant']->uuid, 'type' => 'missing',
            'counted_quantity' => 30, 'verified_quantity' => 32,
            'classification' => 'expected_variance',
        ])->assertCreated();

        $review = $this->as($sup)->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")->assertOk()->json('data');

        $this->assertSame(2, $review['worker_accuracy']['total_entries']);
        $this->assertSame(2, $review['worker_accuracy']['total_discrepancies']);
        $this->assertSame(1, $review['worker_accuracy']['worker_errors']);
        $this->assertSame(1, $review['worker_accuracy']['expected_variance']);
        // 1 worker error out of 2 entries → 50% accuracy
        $this->assertEquals(50.0, $review['worker_accuracy']['accuracy_rate']);
        $this->assertStringContainsString('not treated as worker error', $review['worker_accuracy']['note']);
    }

    public function test_non_supervisor_cannot_access_qa_endpoints(): void
    {
        ['job' => $job, 'worker' => $worker] = $this->submittedJob();

        $this->as($worker)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/qa/review")
            ->assertForbidden();
    }
}
