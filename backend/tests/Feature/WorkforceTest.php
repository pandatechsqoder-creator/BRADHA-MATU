<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
    }

    private function platformUser(string $role = 'workforce-manager'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function worker(string $role = 'verified-stock-taker'): User
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

    /**
     * @return array{user: User, profile: StockTakerProfile}
     */
    private function verifiedWorker(array $overrides = []): array
    {
        $user = $this->worker();

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
            'experience_years' => 3,
            ...$overrides,
        ]);

        $profile->skills()->create(['skill' => 'barcode_counting', 'level' => 'advanced']);
        $profile->serviceAreas()->create(['area' => 'Nairobi', 'city' => 'Nairobi', 'is_primary' => true]);
        $profile->availabilityWindows()->create([
            'weekday' => $this->preferredStart()->dayOfWeek,
            'start_time' => '08:00',
            'end_time' => '18:00',
            'timezone' => 'Africa/Nairobi',
            'active' => true,
        ]);

        return ['user' => $user, 'profile' => $profile];
    }

    private function preferredStart(): Carbon
    {
        return Carbon::now('Africa/Nairobi')->next(Carbon::MONDAY)->setTime(10, 0);
    }

    private function job(array $overrides = []): StocktakeJob
    {
        return StocktakeJob::create([
            'tenant_id' => $this->tenant->id,
            'reference' => 'STJ-TEST-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Quarter close count',
            'status' => 'approved_for_execution',
            'priority' => 'normal',
            'count_method' => 'quantity',
            'preferred_start_at' => $this->preferredStart()->copy()->utc(),
            'required_team_size' => 1,
            'required_skills' => ['barcode counting'],
            'scope' => ['label' => 'Nairobi Main Store'],
            ...$overrides,
        ]);
    }

    public function test_onboarding_ignores_restricted_fields_and_progression_requires_verified_training(): void
    {
        $applicant = $this->worker('applicant');

        $profile = $this->as($applicant)
            ->postJson('/api/v1/workforce/profile', [
                'legal_name' => 'New Applicant',
                'phone' => '+254711111111',
                'city' => 'Nairobi',
                'languages' => ['English', 'Swahili'],
                'terms_accepted' => true,
                'level' => 'expert',
                'verification_status' => 'verified',
            ])
            ->assertCreated()
            ->assertJsonPath('data.profile.level', 'applicant')
            ->assertJsonPath('data.profile.verification_status', 'unverified')
            ->json('data.profile');

        $this->assertNotNull($profile['terms_accepted_at']);

        $this->as($applicant)->putJson('/api/v1/workforce/profile/availability', [
            'windows' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '08:00']],
        ])->assertStatus(422);

        $manager = $this->platformUser();

        $this->as($manager)
            ->postJson("/api/v1/platform/stock-takers/{$profile['id']}/verification", [
                'check_type' => 'identity',
                'decision' => 'verified',
                'reference' => 'ID-REF-001',
            ])->assertOk();

        $this->as($manager)
            ->postJson("/api/v1/platform/stock-takers/{$profile['id']}/verification", [
                'check_type' => 'phone',
                'decision' => 'verified',
            ])->assertOk()
            ->assertJsonPath('data.profile.verification_status', 'verified');

        $this->as($manager)
            ->postJson("/api/v1/platform/stock-takers/{$profile['id']}/training", [
                'module' => 'core_induction',
                'status' => 'completed',
                'score' => 90,
            ])
            ->assertOk()
            ->assertJsonPath('data.profile.level', 'verified')
            ->assertJsonPath('data.profile.training_completed', true);

        $this->assertNotNull(StockTakerProfile::where('uuid', $profile['id'])->firstOrFail()->training_completed_at);
    }

    public function test_verified_worker_receives_eligible_offer_and_only_their_own_compensation(): void
    {
        ['user' => $workerUser, 'profile' => $profile] = $this->verifiedWorker();
        $manager = $this->platformUser();
        $job = $this->job();

        $recommendations = $this->as($manager)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignment-recommendations")
            ->assertOk()
            ->json('data');

        $row = collect($recommendations['recommendations'])->firstWhere('profile.id', $profile->uuid);
        $this->assertNotNull($row);
        $this->assertTrue($row['eligible']);
        $this->assertSame(1, $recommendations['required_team_size']);

        $offer = $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignments", [
                'profile_id' => $profile->uuid,
                'role' => 'counter',
                'compensation_amount' => 850,
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.status', 'awaiting_worker_acceptance')
            ->json('data.assignment');

        $this->assertSame('offered', $offer['status']);
        $this->assertSame(850.0, (float) $offer['compensation']['amount']);

        $list = $this->as($workerUser)
            ->getJson('/api/v1/workforce/assignments')
            ->assertOk()
            ->json('data.data');

        $this->assertCount(1, $list);
        $this->assertArrayNotHasKey('quote_total', $list[0]);
        $this->assertArrayNotHasKey('margin', $list[0]);

        $accepted = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$offer['id']}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');

        $this->assertSame('accrued', $accepted['earning']['status']);
        $this->assertSame(850.0, (float) $accepted['earning']['amount']);
        $this->assertSame('scheduled', $job->fresh()->status);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$offer['id']}/accept")
            ->assertStatus(422);

        $earnings = $this->as($workerUser)
            ->getJson('/api/v1/workforce/earnings')
            ->assertOk()
            ->json('data');

        $this->assertSame(850.0, (float) $earnings['totals']['accrued']);
    }

    public function test_ineligible_worker_is_blocked_unless_an_override_reason_is_recorded(): void
    {
        ['user' => $workerUser, 'profile' => $profile] = $this->verifiedWorker();
        $profile->skills()->delete();
        $manager = $this->platformUser();
        $job = $this->job();

        $recommendations = $this->as($manager)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignment-recommendations")
            ->assertOk()
            ->json('data');

        $row = collect($recommendations['recommendations'])->firstWhere('profile.id', $profile->uuid);
        $this->assertFalse($row['eligible']);
        $this->assertNotEmpty($row['blockers']);

        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignments", [
                'profile_id' => $profile->uuid,
                'compensation_amount' => 700,
            ])
            ->assertStatus(422);

        $offer = $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignments", [
                'profile_id' => $profile->uuid,
                'compensation_amount' => 700,
                'override_reason' => 'Short-notice cover approved by operations.',
            ])
            ->assertCreated()
            ->json('data.assignment');

        $this->assertTrue((bool) $offer['metadata']['override_by']);
        $this->assertSame('Short-notice cover approved by operations.', $offer['metadata']['override_reason']);

        $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$offer['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'offered');
    }

    public function test_worker_cannot_read_another_workers_assignment_and_tenants_cannot_use_workforce_apis(): void
    {
        ['profile' => $profile] = $this->verifiedWorker();
        ['user' => $otherWorker] = $this->verifiedWorker(['legal_name' => 'Other Worker']);
        $manager = $this->platformUser();
        $job = $this->job();

        $offer = $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignments", [
                'profile_id' => $profile->uuid,
                'compensation_amount' => 900,
            ])
            ->assertCreated()
            ->json('data.assignment');

        $this->as($otherWorker)
            ->getJson("/api/v1/workforce/assignments/{$offer['id']}")
            ->assertNotFound();

        $owner = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $owner->id, 'status' => 'active', 'joined_at' => now()]);
        $owner->assignRole('business-owner', $this->tenant->id);

        $this->as($owner)
            ->getJson('/api/v1/workforce/assignments')
            ->assertForbidden();

        $this->as($owner)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/assignment-recommendations")
            ->assertForbidden();

        $this->as($manager)
            ->postJson("/api/v1/platform/assignments/{$offer['id']}/withdraw", [
                'reason' => 'Cover arranged through the agency.',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignment.status', 'withdrawn')
            ->assertJsonPath('data.job.status', 'awaiting_assignment');

        $this->assertSame('withdrawn', JobAssignment::where('uuid', $offer['id'])->firstOrFail()->status);
    }
}
