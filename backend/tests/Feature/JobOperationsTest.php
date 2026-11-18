<?php

namespace Tests\Feature;

use App\Models\JobAssignment;
use App\Models\JobAttendance;
use App\Models\StocktakeJob;
use App\Models\StockTakerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Operations\JobOperationsService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SopSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class JobOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, SopSeeder::class]);
        $this->tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
    }

    private function platformUser(string $role = 'workforce-manager'): User
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
     * @return array{user: User, profile: StockTakerProfile, job: StocktakeJob, assignment: JobAssignment}
     */
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
            'reference' => 'STJ-OPS-'.strtoupper(bin2hex(random_bytes(3))),
            'title' => 'Quarter close count',
            'status' => 'scheduled',
            'priority' => 'normal',
            'count_method' => 'quantity',
            'preferred_start_at' => now()->addHour(),
            'required_team_size' => 1,
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

    /**
     * @return array<string, mixed>
     */
    private function createTemplate(User $manager): array
    {
        return $this->as($manager)
            ->postJson('/api/v1/platform/procedure-templates', [
                'name' => 'Test retail SOP',
                'description' => 'Short SOP for the feature test.',
                'steps' => [
                    ['title' => 'Read the scope', 'instructions' => 'Confirm the locations and zones.', 'evidence_required' => false],
                    ['title' => 'Count quantities only', 'instructions' => 'Never edit expected quantities.'],
                ],
                'checklists' => [
                    [
                        'name' => 'Pre-count readiness',
                        'kind' => 'pre_start',
                        'required' => true,
                        'items' => [
                            ['label' => 'Manager present', 'response_type' => 'boolean', 'required' => true],
                            ['label' => 'Zones allocated', 'response_type' => 'boolean', 'required' => true],
                        ],
                    ],
                ],
            ])
            ->assertCreated()
            ->json('data.template');
    }

    public function test_worker_cannot_check_in_without_acknowledgement_and_required_checklist(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();
        $manager = $this->platformUser();

        $template = $this->createTemplate($manager);
        $this->assertSame(1, $template['current_version']['version']);

        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure", [
                'template_id' => $template['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.procedure.title', 'Test retail SOP')
            ->assertJsonPath('data.operations.job.status', 'scheduled');

        $operations = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->json('data');

        $this->assertFalse($operations['readiness']['sop_acknowledged']);
        $this->assertFalse($operations['readiness']['checked_in']);
        $this->assertFalse($operations['readiness']['can_start_count']);
        $this->assertContains('Acknowledge the job SOP.', $operations['readiness']['blockers']);
        $this->assertContains('Check in at the job site.', $operations['readiness']['blockers']);
        $this->assertNull($operations['procedure']['acknowledged_at']);
        $this->assertStringNotContainsString('compensation', json_encode($operations['procedure']));
        $this->assertStringNotContainsString('quote_total', json_encode($operations));
        $this->assertStringNotContainsString('margin', json_encode($operations));

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])
            ->assertStatus(422);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.procedure.acknowledged', true)
            ->assertJsonPath('data.readiness.stage', 'awaiting_checklist');

        $items = $operations['checklists'][0]['items'];
        $this->assertCount(2, $items);

        $afterFirst = $this->as($workerUser)
            ->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$items[0]['id']}", ['value' => 'yes'])
            ->assertOk()
            ->assertJsonPath('data.readiness.checklist_answered', 1)
            ->json('data');

        $this->assertFalse($afterFirst['readiness']['checklist_complete']);
        $this->assertContains('Complete 1 required checklist item(s).', $afterFirst['readiness']['blockers']);

        $this->as($workerUser)
            ->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$items[1]['id']}", ['value' => 'yes'])
            ->assertOk()
            ->assertJsonPath('data.readiness.checklist_complete', true)
            ->assertJsonPath('data.readiness.stage', 'awaiting_check_in');

        $checkedIn = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", [
                'site_label' => 'Nairobi Main Store',
                'latitude' => -1.2921,
                'longitude' => 36.8219,
                'method' => 'gps',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignment.job.status', 'in_progress')
            ->assertJsonPath('data.readiness.checked_in', true)
            ->assertJsonPath('data.readiness.can_start_count', true)
            ->assertJsonPath('data.readiness.blockers', [])
            ->json('data');

        $this->assertSame(100, $checkedIn['readiness']['percent']);
        $this->assertSame('in_progress', $job->fresh()->status);

        $attendance = JobAttendance::where('assignment_id', $assignment->id)->firstOrFail();
        $this->assertSame('present', $attendance->status);

        $readiness = app(JobOperationsService::class)->assertCanStartCount($assignment->fresh(), $workerUser);
        $this->assertTrue($readiness['can_start_count']);

        $checkedOut = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-out", ['notes' => 'Handed over to supervisor.'])
            ->assertOk()
            ->assertJsonPath('data.attendance.status', 'checked_out')
            ->json('data');

        $this->assertNotNull($checkedOut['checkout']['checked_out_at']);
        $this->assertSame(75, $checkedOut['readiness']['percent']);
    }

    public function test_check_in_is_blocked_until_the_required_checklist_is_complete(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();
        $manager = $this->platformUser();
        $template = $this->createTemplate($manager);

        $this->as($manager)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure", [
            'template_id' => $template['id'],
        ])->assertCreated();

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")
            ->assertOk();

        $service = app(JobOperationsService::class);

        try {
            $service->assertCanStartCount($assignment->fresh(), $workerUser);
            $this->fail('Counting should be blocked before check-in and checklist completion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('count', $exception->errors());
        }

        $response = $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])
            ->assertStatus(422)
            ->json('error');

        $this->assertStringContainsString('required checklist', json_encode($response));
        $this->assertSame('scheduled', $job->fresh()->status);
    }

    public function test_operations_are_tenant_isolated_and_do_not_expose_compensation(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();

        $community = User::factory()->create();
        $this->tenant->memberships()->create(['user_id' => $community->id, 'status' => 'active', 'joined_at' => now()]);
        $community->assignRole('business-owner', $this->tenant->id);

        $this->as($community)
            ->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/operations")
            ->assertOk()
            ->assertJsonPath('data.job.id', $job->uuid)
            ->assertJsonMissingPath('data.team.0.compensation');

        $content = $this->as($community)
            ->getJson("/api/v1/tenants/{$this->tenant->uuid}/stocktake-jobs/{$job->uuid}/operations")
            ->getContent();
        $this->assertStringNotContainsString('compensation', $content);
        $this->assertStringNotContainsString('quote_total', $content);

        $otherTenant = Tenant::create(['name' => 'Other Retail', 'status' => 'active']);

        // Nested binding is scoped through Tenant::jobs(), so a foreign job id is a 404, never a 403
        // that would reveal the job exists in another tenant.
        $this->as($community)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/stocktake-jobs/{$job->uuid}/operations")
            ->assertNotFound();

        ['user' => $otherWorker] = $this->scheduledAssignment(['reference' => 'STJ-OPS-OTHER']);

        $this->as($otherWorker)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertNotFound();

        $manager = $this->platformUser();

        $this->as($community)
            ->getJson('/api/v1/platform/operations')
            ->assertForbidden();

        $this->as($workerUser)
            ->getJson('/api/v1/platform/operations')
            ->assertForbidden();

        $this->as($manager)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertForbidden();

        $this->as($manager)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/operations")
            ->assertOk()
            ->assertJsonPath('data.summary.team_accepted', 1)
            ->assertJsonPath('data.procedure', null);

        $dashboard = $this->as($manager)
            ->getJson('/api/v1/platform/operations')
            ->assertOk()
            ->json('data');

        $this->assertGreaterThanOrEqual(1, $dashboard['summary']['active_jobs']);

        $row = collect($dashboard['jobs'])->firstWhere('job.id', $job->uuid);
        $this->assertNotNull($row);
        $this->assertSame('A job SOP has not been assigned.', $row['next_action']);
        $this->assertSame(1, $row['team']['accepted']);
    }

    public function test_publishing_a_new_version_keeps_the_existing_job_snapshot(): void
    {
        ['job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment();
        $manager = $this->platformUser();
        $template = $this->createTemplate($manager);

        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure", [
                'template_id' => $template['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.procedure.version', 1)
            ->assertJsonPath('data.procedure.summary', null);

        $published = $this->as($manager)
            ->postJson("/api/v1/platform/procedure-templates/{$template['id']}/versions", [
                'summary' => 'Version two guidance.',
                'steps' => [
                    ['title' => 'Revised scope review', 'instructions' => 'Confirm the revised zones.'],
                    ['title' => 'Count quantities only', 'instructions' => 'Never edit expected quantities.'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.template.current_version.version', 2)
            ->assertJsonPath('data.template.current_version.summary', 'Version two guidance.')
            ->json('data.template');

        $this->assertCount(2, $published['versions']);
        $this->assertSame('superseded', collect($published['versions'])->firstWhere('version', 1)['status']);
        $this->assertSame('published', collect($published['versions'])->firstWhere('version', 2)['status']);

        $jobProcedure = $this->as($manager)
            ->getJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure")
            ->assertOk()
            ->json('data.procedure');

        $this->assertSame(1, $jobProcedure['version']);
        $this->assertNull($jobProcedure['summary']);
        $this->assertSame('Read the scope', $jobProcedure['steps'][0]['title']);

        $versionTwo = collect($published['versions'])->firstWhere('version', 2);

        $this->as($manager)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure", [
                'template_id' => $template['id'],
                'version_id' => $versionTwo['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.procedure.version', 2)
            ->assertJsonPath('data.procedure.summary', 'Version two guidance.')
            ->assertJsonPath('data.procedure.steps.0.title', 'Revised scope review');

        $this->assertSame(2, $assignment->job->procedures()->count());
        $this->assertSame(1, $assignment->job->procedures()->where('status', 'active')->count());

        $workerReadiness = $this->as($assignment->profile->user)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->json('data.procedure');

        $this->assertSame(2, $workerReadiness['version']);
        $this->assertSame('Version two guidance.', $workerReadiness['summary']);
    }

    public function test_can_check_in_stays_false_until_the_job_is_scheduled_for_field_work(): void
    {
        ['user' => $workerUser, 'job' => $job, 'assignment' => $assignment] = $this->scheduledAssignment([
            'status' => 'awaiting_worker_acceptance',
        ]);
        $manager = $this->platformUser();
        $template = $this->createTemplate($manager);

        $this->as($manager)->postJson("/api/v1/platform/stocktake-jobs/{$job->uuid}/procedure", [
            'template_id' => $template['id'],
        ])->assertCreated();

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/procedure/acknowledge")
            ->assertOk();

        $operations = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->json('data');

        foreach ($operations['checklists'][0]['items'] as $item) {
            $this->as($workerUser)
                ->putJson("/api/v1/workforce/assignments/{$assignment->uuid}/checklists/{$item['id']}", ['value' => 'yes'])
                ->assertOk();
        }

        $readiness = $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->json('data.readiness');

        $this->assertTrue($readiness['checklist_complete']);
        $this->assertFalse($readiness['can_check_in']);
        $this->assertContains(
            'The job is not ready for field work (Awaiting worker acceptance).',
            $readiness['blockers'],
        );

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])
            ->assertStatus(422);

        $job->update(['status' => 'scheduled']);

        $this->as($workerUser)
            ->getJson("/api/v1/workforce/assignments/{$assignment->uuid}/operations")
            ->assertOk()
            ->assertJsonPath('data.readiness.can_check_in', true);

        $this->as($workerUser)
            ->postJson("/api/v1/workforce/assignments/{$assignment->uuid}/check-in", ['method' => 'manual'])
            ->assertOk()
            ->assertJsonPath('data.readiness.checked_in', true)
            ->assertJsonPath('data.assignment.job.status', 'in_progress');
    }
}
