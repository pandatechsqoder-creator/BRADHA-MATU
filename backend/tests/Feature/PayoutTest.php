<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\PayoutBatch;
use App\Models\StockTakerEarning;
use App\Models\StockTakerPayout;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutTest extends TestCase
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

    private function platformUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /** Set up a job in completion_confirmed with an accrued earning. */
    private function completedJob(): array
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
            'reference' => 'STJ-PAY-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Payout test job', 'status' => 'completion_confirmed', 'priority' => 'normal',
            'count_method' => 'quantity', 'required_team_size' => 1, 'product_scope' => 'all',
            'scope' => ['label' => 'Store'],
        ]);
        $assignment = JobAssignment::create([
            'job_id' => $job->id, 'tenant_id' => $this->tenant->id, 'profile_id' => $profile->id,
            'role' => 'counter', 'status' => 'accepted', 'compensation_amount' => 700, 'currency' => 'KES',
            'offer_expires_at' => now()->addDay(), 'offered_at' => now(), 'responded_at' => now(), 'accepted_at' => now(),
        ]);
        $earning = StockTakerEarning::create([
            'uuid' => (string) \Illuminate\Support\Str::ulid(),
            'reference' => 'EARN-'.strtoupper(bin2hex(random_bytes(3))),
            'profile_id' => $profile->id,
            'assignment_id' => $assignment->id,
            'tenant_id' => $this->tenant->id,
            'amount' => 700,
            'currency' => 'KES',
            'status' => 'accrued',
            'earned_at' => now(),
        ]);

        return ['job' => $job, 'worker' => $worker, 'profile' => $profile, 'assignment' => $assignment, 'earning' => $earning];
    }

    public function test_finance_officer_can_prepare_a_payout_batch(): void
    {
        ['job' => $job] = $this->completedJob();
        $officer = $this->platformUser('finance-officer');

        $batch = $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('pending_approval', $batch['status']);
        $this->assertEquals(700.0, $batch['total_amount']);
        $this->assertSame(1, $batch['payout_count']);
        $this->assertSame('payout_pending_authorisation', $job->fresh()->status);
        $this->assertCount(1, $batch['payouts']);
        $this->assertSame('mpesa', $batch['payouts'][0]['method']);
    }

    public function test_maker_checker_prevents_self_approval(): void
    {
        ['job' => $job] = $this->completedJob();
        $officer = $this->platformUser('finance-manager'); // finance-manager has both prepare + approve

        $batch = $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertCreated()
            ->json('data');

        // Same user tries to approve their own batch
        $this->as($officer)
            ->postJson("/api/v1/platform/payouts/{$batch['id']}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.details.approver.0', 'The approver must be different from the preparer (maker-checker rule).');
    }

    public function test_finance_manager_can_approve_and_process_batch(): void
    {
        ['job' => $job, 'earning' => $earning] = $this->completedJob();
        $officer = $this->platformUser('finance-officer');
        $manager = $this->platformUser('finance-manager');

        $batch = $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertCreated()->json('data');

        // Approve
        $approved = $this->as($manager)
            ->postJson("/api/v1/platform/payouts/{$batch['id']}/approve", ['notes' => 'Looks good.'])
            ->assertOk()
            ->json('data');

        $this->assertSame('approved', $approved['status']);

        // Process (sandbox)
        $processed = $this->as($manager)
            ->postJson("/api/v1/platform/payouts/{$batch['id']}/process")
            ->assertOk()
            ->json('data');

        $this->assertSame('completed', $processed['status']);
        $this->assertSame('completed', $processed['payouts'][0]['status']);
        $this->assertSame('payout_completed', $job->fresh()->status);
        $this->assertSame('paid', StockTakerEarning::whereKey($earning->id)->value('status'));
    }

    public function test_job_can_be_closed_after_payout_completes(): void
    {
        ['job' => $job, 'earning' => $earning] = $this->completedJob();
        $officer = $this->platformUser('finance-officer');
        $manager = $this->platformUser('finance-manager');

        $batch = $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertCreated()->json('data');

        $this->as($manager)->postJson("/api/v1/platform/payouts/{$batch['id']}/approve")->assertOk();
        $this->as($manager)->postJson("/api/v1/platform/payouts/{$batch['id']}/process")->assertOk();

        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertSame('closed', $job->fresh()->status);
    }

    public function test_pending_batches_list_is_visible_to_finance_roles(): void
    {
        ['job' => $job] = $this->completedJob();
        $officer = $this->platformUser('finance-officer');
        $manager = $this->platformUser('finance-manager');

        $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertCreated();

        $list = $this->as($manager)->getJson('/api/v1/platform/payouts')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('pending_approval', $list[0]['status']);
    }

    public function test_payout_cannot_be_prepared_before_completion_confirmed(): void
    {
        ['job' => $job] = $this->completedJob();
        $job->update(['status' => 'in_progress']);
        $officer = $this->platformUser('finance-officer');

        $this->as($officer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/payouts", ['method' => 'mpesa'])
            ->assertStatus(422);
    }
}
