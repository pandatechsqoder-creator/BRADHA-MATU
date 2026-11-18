<?php

namespace App\Services\Workforce;

use App\Models\JobAssignment;
use App\Models\JobAssignmentEvent;
use App\Models\StockTakerEarning;
use App\Models\StockTakerProfile;
use App\Models\StocktakeJob;
use App\Models\User;
use App\Services\Stocktake\StocktakeWorkflow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AssignmentService
{
    private const ACTIVE_STATUSES = ['offered', 'accepted'];

    private const ASSIGNABLE_JOB_STATUSES = [
        'approved_for_execution', 'awaiting_assignment', 'assigned', 'awaiting_worker_acceptance',
    ];

    private const ELIGIBLE_LEVELS = ['verified', 'professional', 'expert', 'senior_supervisor'];

    private const MAX_ACTIVE_ASSIGNMENTS = 3;

    public function __construct(
        private readonly StocktakeWorkflow $workflow,
        private readonly WorkforceProfileService $profiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function recommendations(StocktakeJob $job): array
    {
        $job->loadMissing(['latestQuote']);
        $profiles = StockTakerProfile::query()
            ->with(['user', 'skills', 'serviceAreas', 'availabilityWindows', 'assignments.job'])
            ->where('status', 'active')
            ->where('verification_status', 'verified')
            ->whereIn('level', self::ELIGIBLE_LEVELS)
            ->whereNotNull('training_completed_at')
            ->get();

        $recommendations = $profiles->map(function (StockTakerProfile $profile) use ($job): array {
            $evaluation = $this->evaluate($profile, $job);

            return [
                'profile' => $this->profiles->summary($profile),
                'eligible' => $evaluation['eligible'],
                'score' => $evaluation['score'],
                'reasons' => $evaluation['reasons'],
                'blockers' => $evaluation['blockers'],
                'active_assignments' => $evaluation['active_assignments'],
                'proposed_compensation' => $this->proposedCompensation($job),
                'already_assigned' => $this->activeOnJob($profile, $job->id) !== null,
            ];
        })->sortByDesc(fn (array $row) => ($row['eligible'] ? 1000 : 0) + $row['score'])->values()->all();

        return [
            'job' => [
                'id' => $job->uuid,
                'reference' => $job->reference,
                'status' => $job->status,
                'required_team_size' => $job->required_team_size,
                'required_skills' => $job->required_skills ?? [],
                'scope_label' => $job->scope['label'] ?? null,
                'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
            ],
            'required_team_size' => $job->required_team_size,
            'filled_slots' => $this->teamSize($job),
            'proposed_compensation' => $this->proposedCompensation($job),
            'recommendations' => $recommendations,
        ];
    }

    public function offer(StocktakeJob $job, array $data, User $actor): JobAssignment
    {
        return DB::transaction(function () use ($job, $data, $actor): JobAssignment {
            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($job->id)->lockForUpdate()->firstOrFail();

            if (! in_array($job->status, self::ASSIGNABLE_JOB_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'job' => ['Assignments can only be made after execution approval and before the count starts.'],
                ]);
            }

            $profile = StockTakerProfile::query()
                ->with(['user', 'skills', 'serviceAreas', 'availabilityWindows', 'assignments.job'])
                ->where('uuid', $data['profile_id'])
                ->firstOrFail();

            if ($this->activeOnJob($profile, $job->id) !== null) {
                throw ValidationException::withMessages([
                    'profile' => ['This stock taker already has an active assignment for this job.'],
                ]);
            }

            $evaluation = $this->evaluate($profile, $job);
            $overrideReason = $data['override_reason'] ?? null;

            if (! $evaluation['eligible'] && ! $overrideReason) {
                throw ValidationException::withMessages([
                    'profile' => array_merge(
                        ['This stock taker is not eligible for the job.'],
                        $evaluation['blockers'],
                    ),
                ]);
            }

            if ($this->teamSize($job) >= $job->required_team_size) {
                throw ValidationException::withMessages([
                    'job' => ['All required team slots are already filled.'],
                ]);
            }

            $assignment = JobAssignment::create([
                'job_id' => $job->id,
                'tenant_id' => $job->tenant_id,
                'profile_id' => $profile->id,
                'role' => $data['role'] ?? 'counter',
                'status' => 'offered',
                'compensation_amount' => $data['compensation_amount'] ?? $this->proposedCompensation($job),
                'currency' => $job->currency ?: 'KES',
                'offer_expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? 48)),
                'offered_by' => $actor->id,
                'offered_at' => now(),
                'metadata' => [
                    'recommendation_score' => $evaluation['score'],
                    'recommendation_reasons' => $evaluation['reasons'],
                    'blockers' => $evaluation['blockers'],
                    'override_reason' => $overrideReason,
                    'override_by' => $overrideReason ? $actor->id : null,
                ],
            ]);

            $this->event($assignment, null, 'offered', $actor, 'platform', $overrideReason);

            $job = $this->advanceJobForOffer($job, $actor);

            return $assignment->fresh(['job.tenant', 'profile.user']);
        });
    }

    public function accept(JobAssignment $assignment, User $worker): JobAssignment
    {
        return DB::transaction(function () use ($assignment, $worker): JobAssignment {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);

            if ($assignment->status !== 'offered') {
                throw ValidationException::withMessages(['assignment' => ['This offer is no longer open.']]);
            }

            if ($assignment->offer_expires_at !== null && $assignment->offer_expires_at->isPast()) {
                $assignment->update(['status' => 'expired', 'responded_at' => now()]);
                $this->event($assignment, 'offered', 'expired', $worker, 'worker', 'Offer expired before acceptance.');

                throw ValidationException::withMessages(['assignment' => ['This offer has expired.']]);
            }

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->lockForUpdate()->firstOrFail();

            if (! in_array($job->status, ['assigned', 'awaiting_worker_acceptance'], true)) {
                throw ValidationException::withMessages(['job' => ['The job is not awaiting worker acceptance.']]);
            }

            $assignment->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'accepted_at' => now(),
            ]);
            $this->event($assignment, 'offered', 'accepted', $worker, 'worker');

            $this->accrueEarning($assignment, $job);

            if ($this->acceptedCount($job) >= $job->required_team_size
                && $job->status === 'awaiting_worker_acceptance') {
                $this->workflow->transition($job, 'scheduled', $worker, 'Required team accepted the assignment.');
            }

            return $assignment->fresh(['job.tenant', 'profile.user']);
        });
    }

    public function decline(JobAssignment $assignment, User $worker, string $reason): JobAssignment
    {
        return DB::transaction(function () use ($assignment, $worker, $reason): JobAssignment {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertOwnedBy($assignment, $worker);

            if ($assignment->status !== 'offered') {
                throw ValidationException::withMessages(['assignment' => ['Only open offers can be declined.']]);
            }

            $assignment->update([
                'status' => 'declined',
                'responded_at' => now(),
                'declined_reason' => $reason,
            ]);
            $this->event($assignment, 'offered', 'declined', $worker, 'worker', $reason);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
            $remaining = JobAssignment::where('job_id', $job->id)
                ->whereIn('status', self::ACTIVE_STATUSES)
                ->count();

            if ($remaining === 0 && $job->status === 'awaiting_worker_acceptance') {
                $this->workflow->transition($job, 'awaiting_assignment', $worker, 'Worker declined the assignment.');
            }

            return $assignment->fresh(['job.tenant', 'profile.user']);
        });
    }

    public function reassign(JobAssignment $assignment, array $data, User $actor): JobAssignment
    {
        return DB::transaction(function () use ($assignment, $data, $actor): JobAssignment {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($assignment->status, self::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['assignment' => ['Only open or accepted assignments can be reassigned.']]);
            }

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
            $profile = StockTakerProfile::query()
                ->with(['user', 'skills', 'serviceAreas', 'availabilityWindows', 'assignments.job'])
                ->where('uuid', $data['profile_id'])
                ->firstOrFail();

            if ($profile->id === $assignment->profile_id) {
                throw ValidationException::withMessages(['profile' => ['Choose a different stock taker to reassign to.']]);
            }

            $evaluation = $this->evaluate($profile, $job);
            if (! $evaluation['eligible'] && empty($data['override_reason'])) {
                throw ValidationException::withMessages([
                    'profile' => array_merge(['This stock taker is not eligible for the job.'], $evaluation['blockers']),
                ]);
            }

            $previousEarning = StockTakerEarning::where('assignment_id', $assignment->id)
                ->where('status', 'accrued')
                ->first();
            $previousEarning?->update(['status' => 'reversed']);

            $reason = $data['reason'] ?? 'Assignment reassigned by workforce operations.';
            $previousStatus = $assignment->status;
            $assignment->update([
                'status' => 'reassigned',
                'responded_at' => now(),
                'declined_reason' => $reason,
            ]);
            $this->event($assignment, $previousStatus, 'reassigned', $actor, 'platform', $reason);

            $replacement = JobAssignment::create([
                'job_id' => $job->id,
                'tenant_id' => $job->tenant_id,
                'profile_id' => $profile->id,
                'role' => $data['role'] ?? $assignment->role,
                'status' => 'offered',
                'compensation_amount' => $data['compensation_amount'] ?? $assignment->compensation_amount,
                'currency' => $assignment->currency,
                'offer_expires_at' => now()->addHours((int) ($data['expires_in_hours'] ?? 48)),
                'offered_by' => $actor->id,
                'offered_at' => now(),
                'replaced_assignment_id' => $assignment->id,
                'metadata' => [
                    'recommendation_score' => $evaluation['score'],
                    'recommendation_reasons' => $evaluation['reasons'],
                    'blockers' => $evaluation['blockers'],
                    'override_reason' => $data['override_reason'] ?? null,
                ],
            ]);
            $this->event($replacement, null, 'offered', $actor, 'platform', $reason);

            return $replacement->fresh(['job.tenant', 'profile.user']);
        });
    }

    public function withdraw(JobAssignment $assignment, User $actor, ?string $reason = null): JobAssignment
    {
        return DB::transaction(function () use ($assignment, $actor, $reason): JobAssignment {
            $assignment = JobAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($assignment->status, self::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages(['assignment' => ['Only open or accepted assignments can be withdrawn.']]);
            }

            StockTakerEarning::where('assignment_id', $assignment->id)
                ->where('status', 'accrued')
                ->update(['status' => 'reversed']);

            $previousStatus = $assignment->status;
            $assignment->update([
                'status' => 'withdrawn',
                'responded_at' => now(),
                'declined_reason' => $reason,
            ]);
            $this->event($assignment, $previousStatus, 'withdrawn', $actor, 'platform', $reason);

            $job = StocktakeJob::withoutGlobalScope('tenant')->whereKey($assignment->job_id)->firstOrFail();
            $remaining = JobAssignment::where('job_id', $job->id)->whereIn('status', self::ACTIVE_STATUSES)->count();

            if ($remaining === 0 && $job->status === 'awaiting_worker_acceptance') {
                $this->workflow->transition($job, 'awaiting_assignment', $actor, $reason ?? 'Assignment withdrawn.');
            }

            return $assignment->fresh(['job.tenant', 'profile.user']);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function workerAssignments(StockTakerProfile $profile): array
    {
        $assignments = JobAssignment::query()
            ->with(['job.tenant', 'events'])
            ->where('profile_id', $profile->id)
            ->orderByRaw("case when status = 'offered' then 0 when status = 'accepted' then 1 else 2 end")
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $assignments->map(fn (JobAssignment $assignment) => $this->workerPayload($assignment))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function workerPayload(JobAssignment $assignment): array
    {
        $assignment->loadMissing(['job.tenant', 'events', 'earnings']);
        $job = $assignment->job;
        $scope = $job?->scope ?? [];

        return [
            'id' => $assignment->uuid,
            'status' => $assignment->status,
            'role' => $assignment->role,
            'compensation' => [
                'amount' => (float) $assignment->compensation_amount,
                'currency' => $assignment->currency,
            ],
            'offer_expires_at' => $assignment->offer_expires_at?->toIso8601String(),
            'offered_at' => $assignment->offered_at?->toIso8601String(),
            'responded_at' => $assignment->responded_at?->toIso8601String(),
            'accepted_at' => $assignment->accepted_at?->toIso8601String(),
            'earning' => $assignment->earnings->sortByDesc('id')->first()?->only(['status', 'amount', 'currency', 'reference']),
            'job' => $job ? [
                'id' => $job->uuid,
                'reference' => $job->reference,
                'title' => $job->title,
                'status' => $job->status,
                'priority' => $job->priority,
                'count_method' => $job->count_method,
                'preferred_start_at' => $job->preferred_start_at?->toIso8601String(),
                'deadline_at' => $job->deadline_at?->toIso8601String(),
                'required_team_size' => $job->required_team_size,
                'scope_label' => $scope['label'] ?? null,
                'location_count' => count((array) ($scope['locations'] ?? [])),
                'tenant' => $job->tenant?->name,
            ] : null,
            'events' => $assignment->events->map(fn (JobAssignmentEvent $event) => [
                'status' => $event->to_status,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platformPayload(JobAssignment $assignment): array
    {
        $assignment->loadMissing(['job.tenant', 'profile.user', 'events', 'earnings']);

        return [
            'id' => $assignment->uuid,
            'status' => $assignment->status,
            'role' => $assignment->role,
            'compensation' => [
                'amount' => (float) $assignment->compensation_amount,
                'currency' => $assignment->currency,
            ],
            'offer_expires_at' => $assignment->offer_expires_at?->toIso8601String(),
            'offered_at' => $assignment->offered_at?->toIso8601String(),
            'responded_at' => $assignment->responded_at?->toIso8601String(),
            'accepted_at' => $assignment->accepted_at?->toIso8601String(),
            'declined_reason' => $assignment->declined_reason,
            'profile' => [
                'id' => $assignment->profile?->uuid,
                'name' => $assignment->profile?->user?->name,
                'email' => $assignment->profile?->user?->email,
                'level' => $assignment->profile?->level,
                'phone' => $assignment->profile?->phone,
                'city' => $assignment->profile?->city,
            ],
            'job' => $assignment->job ? [
                'id' => $assignment->job->uuid,
                'reference' => $assignment->job->reference,
                'title' => $assignment->job->title,
                'status' => $assignment->job->status,
                'tenant' => $assignment->job->tenant?->name,
                'preferred_start_at' => $assignment->job->preferred_start_at?->toIso8601String(),
            ] : null,
            'metadata' => $assignment->metadata,
            'earnings' => $assignment->earnings->map(fn (StockTakerEarning $earning) => [
                'reference' => $earning->reference,
                'amount' => (float) $earning->amount,
                'status' => $earning->status,
            ])->values()->all(),
            'events' => $assignment->events->map(fn (JobAssignmentEvent $event) => [
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'actor_type' => $event->actor_type,
                'reason' => $event->reason,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function workerEarnings(StockTakerProfile $profile): array
    {
        $earnings = StockTakerEarning::query()
            ->with(['assignment.job'])
            ->where('profile_id', $profile->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $totals = $earnings->groupBy('status')->map(fn ($rows) => round((float) $rows->sum('amount'), 2));

        return [
            'data' => $earnings->map(fn (StockTakerEarning $earning) => [
                'id' => $earning->uuid,
                'reference' => $earning->reference,
                'amount' => (float) $earning->amount,
                'currency' => $earning->currency,
                'status' => $earning->status,
                'description' => $earning->description,
                'earned_at' => $earning->earned_at?->toIso8601String(),
                'job' => $earning->assignment?->job ? [
                    'reference' => $earning->assignment->job->reference,
                    'title' => $earning->assignment->job->title,
                ] : null,
            ])->values()->all(),
            'totals' => (object) $totals->all(),
            'currency' => 'KES',
        ];
    }

    /**
     * @return array{eligible: bool, score: float, reasons: array<int, string>, blockers: array<int, string>, active_assignments: int}
     */
    public function evaluate(StockTakerProfile $profile, StocktakeJob $job): array
    {
        $blockers = [];
        $reasons = [];

        if (! $profile->isEligible()) {
            $blockers[] = 'Profile is not verified, trained and active.';
        } else {
            $reasons[] = 'Verified '.str_replace('_', ' ', $profile->level).' stock taker.';
        }

        $activeAssignments = $profile->assignments
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->filter(fn (JobAssignment $assignment) => $assignment->job_id !== $job->id)
            ->count();

        if ($activeAssignments >= self::MAX_ACTIVE_ASSIGNMENTS) {
            $blockers[] = 'Already has '.$activeAssignments.' active assignments.';
        } else {
            $reasons[] = 'Current workload: '.$activeAssignments.' active assignment(s).';
        }

        $requiredSkills = collect($job->required_skills ?? [])
            ->filter(fn ($skill) => is_string($skill) && trim($skill) !== '')
            ->values();
        $workerSkills = $profile->skills->pluck('skill')->map(fn ($skill) => $this->normalizeSkill($skill))->filter()->unique();
        $normalizedRequired = $requiredSkills->map(fn ($skill) => $this->normalizeSkill($skill));
        $matchedSkills = $normalizedRequired->intersect($workerSkills)->values();
        $missingSkills = $normalizedRequired->diff($workerSkills)->values();

        if ($normalizedRequired->isNotEmpty()) {
            if ($missingSkills->isNotEmpty()) {
                $blockers[] = 'Missing required skills: '.$missingSkills->implode(', ').'.';
            } else {
                $reasons[] = 'Matches required skills: '.$matchedSkills->implode(', ').'.';
            }
        }

        $areas = $profile->serviceAreas;
        $locationTexts = $this->profiles->jobLocationTexts($job);
        $haystack = Str::lower(implode(' ', $locationTexts));
        $servesLocation = $areas->contains(function ($area) use ($haystack): bool {
            foreach ([$area->area, $area->city, $area->county] as $value) {
                if (is_string($value) && $value !== '' && Str::contains($haystack, Str::lower($value))) {
                    return true;
                }
            }

            return false;
        });

        if ($areas->isEmpty()) {
            $blockers[] = 'No service areas configured.';
        } elseif (! $servesLocation) {
            $blockers[] = 'Does not serve the job location ('.($job->scope['label'] ?? 'site').').';
        } else {
            $reasons[] = 'Serves the job area ('.$areas->pluck('area')->take(2)->implode(', ').').';
        }

        $availableAtPreferred = null;
        if ($job->preferred_start_at !== null) {
            $start = $job->preferred_start_at->copy()->setTimezone('Africa/Nairobi');
            $time = $start->format('H:i:s');
            $window = $profile->availabilityWindows
                ->where('active', true)
                ->first(fn ($slot) => $slot->weekday === $start->dayOfWeek
                    && $slot->start_time <= $time
                    && $slot->end_time >= $time);

            $availableAtPreferred = $window !== null;
            if ($window === null) {
                $blockers[] = 'Not available at the preferred start time ('.$start->format('D H:i').' EAT).';
            } else {
                $reasons[] = 'Available '.$start->format('D H:i').' EAT.';
            }
        } else {
            $reasons[] = 'Availability windows on file.';
        }

        if ($job->preferred_start_at !== null) {
            $sameDay = $profile->assignments
                ->where('status', 'accepted')
                ->contains(fn (JobAssignment $assignment) => $assignment->job_id !== $job->id
                    && $assignment->job?->preferred_start_at?->isSameDay($job->preferred_start_at));
            if ($sameDay) {
                $blockers[] = 'Already accepted another job at the same time.';
            }
        }

        $score = 40.0;
        $score += min(20.0, $matchedSkills->count() * 10.0);
        $score += $servesLocation ? 15.0 : 0.0;
        if ($availableAtPreferred === true) {
            $score += 10.0;
        }
        $score += min(10.0, (int) $profile->experience_years * 2);
        $score += (array_search($profile->level, StockTakerProfile::LEVELS, true) ?: 0) * 3;
        $score += min(5.0, (float) ($profile->reliability_score ?? 0) / 20);
        $score += max(0, (self::MAX_ACTIVE_ASSIGNMENTS - $activeAssignments)) * 2;

        return [
            'eligible' => $blockers === [],
            'score' => round(max(0.0, min(100.0, $score)), 1),
            'reasons' => $reasons,
            'blockers' => $blockers,
            'active_assignments' => $activeAssignments,
        ];
    }

    public function proposedCompensation(StocktakeJob $job): float
    {
        $quote = $job->latestQuote;
        if ($quote === null) {
            return 0.0;
        }

        $budget = (float) $quote->worker_compensation_budget;
        $team = max(1, (int) $job->required_team_size);

        return round($budget / $team, 2);
    }

    private function advanceJobForOffer(StocktakeJob $job, User $actor): StocktakeJob
    {
        if ($job->status === 'approved_for_execution') {
            $job = $this->workflow->transition($job, 'awaiting_assignment', $actor, 'Execution approved; preparing assignment.');
        }
        if ($job->status === 'awaiting_assignment' || $job->status === 'assigned') {
            $job = $this->workflow->transition($job, 'awaiting_worker_acceptance', $actor, 'Assignment offer sent to stock taker.');
        }

        return $job;
    }

    private function activeOnJob(StockTakerProfile $profile, int $jobId): ?JobAssignment
    {
        return JobAssignment::where('job_id', $jobId)
            ->where('profile_id', $profile->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->first();
    }

    private function teamSize(StocktakeJob $job): int
    {
        return JobAssignment::where('job_id', $job->id)->whereIn('status', self::ACTIVE_STATUSES)->count();
    }

    private function acceptedCount(StocktakeJob $job): int
    {
        return JobAssignment::where('job_id', $job->id)->where('status', 'accepted')->count();
    }

    private function assertOwnedBy(JobAssignment $assignment, User $worker): void
    {
        abort_unless($assignment->profile?->user_id === $worker->id, 403, 'This assignment belongs to another stock taker.');
    }

    private function accrueEarning(JobAssignment $assignment, StocktakeJob $job): void
    {
        if (StockTakerEarning::where('assignment_id', $assignment->id)->exists()) {
            return;
        }

        StockTakerEarning::create([
            'profile_id' => $assignment->profile_id,
            'assignment_id' => $assignment->id,
            'tenant_id' => $job->tenant_id,
            'reference' => 'EARN-'.now()->format('Ymd').'-'.Str::upper(Str::random(6)),
            'amount' => $assignment->compensation_amount,
            'currency' => $assignment->currency,
            'status' => 'accrued',
            'description' => 'Assignment accepted — '.$job->reference,
            'earned_at' => now(),
        ]);
    }

    private function event(JobAssignment $assignment, ?string $from, string $to, User $actor, string $actorType, ?string $reason = null): void
    {
        JobAssignmentEvent::create([
            'assignment_id' => $assignment->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor->id,
            'actor_type' => $actorType,
            'reason' => $reason,
        ]);
    }

    private function normalizeSkill(?string $skill): string
    {
        return Str::of((string) $skill)->lower()->replace(['_', '-'], ' ')->squish()->toString();
    }

    /**
     * @param  array<int, string>  $blockers
     */
    private function hasBlockingAvailability(array $blockers): bool
    {
        return collect($blockers)->contains(fn (string $blocker) => Str::startsWith($blocker, 'Not available'));
    }
}
