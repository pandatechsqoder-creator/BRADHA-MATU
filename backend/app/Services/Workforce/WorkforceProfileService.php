<?php

namespace App\Services\Workforce;

use App\Models\Branch;
use App\Models\StockTakerAvailability;
use App\Models\StockTakerProfile;
use App\Models\StockTakerServiceArea;
use App\Models\StockTakerSkill;
use App\Models\StockTakerTrainingCompletion;
use App\Models\StockTakerVerification;
use App\Models\StocktakeJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkforceProfileService
{
    public function __construct(private readonly WorkforceProgressionService $progression) {}

    public function forUser(User $user): ?StockTakerProfile
    {
        return StockTakerProfile::where('user_id', $user->id)->first();
    }

    /**
     * Applicants can create or refresh their own profile. Sensitive review fields
     * (level, verification status) are never accepted from the worker payload.
     */
    public function apply(User $user, array $data): StockTakerProfile
    {
        return DB::transaction(function () use ($user, $data): StockTakerProfile {
            $profile = StockTakerProfile::firstOrNew(['user_id' => $user->id]);
            $profile->fill([
                'legal_name' => $data['legal_name'] ?? $profile->legal_name ?? $user->name,
                'phone' => $data['phone'] ?? $profile->phone,
                'city' => $data['city'] ?? $profile->city,
                'county' => $data['county'] ?? $profile->county,
                'country' => $data['country'] ?? $profile->country ?? 'Kenya',
                'languages' => $data['languages'] ?? $profile->languages,
                'transport' => $data['transport'] ?? $profile->transport,
                'experience_years' => $data['experience_years'] ?? $profile->experience_years ?? 0,
                'bio' => $data['bio'] ?? $profile->bio,
            ]);

            if ($profile->status === null) {
                $profile->status = 'active';
            }
            if ($profile->level === null) {
                $profile->level = 'applicant';
            }
            if ($profile->verification_status === null) {
                $profile->verification_status = 'unverified';
            }
            if (($data['terms_accepted'] ?? false) && $profile->terms_accepted_at === null) {
                $profile->terms_accepted_at = now();
            }

            $profile->save();

            return $profile->fresh();
        });
    }

    public function updateProfile(StockTakerProfile $profile, array $data): StockTakerProfile
    {
        $allowed = [
            'legal_name', 'phone', 'city', 'county', 'country', 'languages', 'transport',
            'experience_years', 'bio',
        ];

        $profile->fill(collect($data)->only($allowed)->all());
        $profile->save();

        return $profile->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $skills
     */
    public function replaceSkills(StockTakerProfile $profile, array $skills): StockTakerProfile
    {
        DB::transaction(function () use ($profile, $skills): void {
            StockTakerSkill::where('profile_id', $profile->id)->delete();

            foreach ($skills as $skill) {
                StockTakerSkill::create([
                    'profile_id' => $profile->id,
                    'skill' => trim((string) $skill['skill']),
                    'level' => $skill['level'] ?? 'intermediate',
                    'years_experience' => $skill['years_experience'] ?? null,
                ]);
            }
        });

        return $profile->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $areas
     */
    public function replaceServiceAreas(StockTakerProfile $profile, array $areas): StockTakerProfile
    {
        DB::transaction(function () use ($profile, $areas): void {
            StockTakerServiceArea::where('profile_id', $profile->id)->delete();

            foreach ($areas as $index => $area) {
                StockTakerServiceArea::create([
                    'profile_id' => $profile->id,
                    'area' => trim((string) $area['area']),
                    'city' => $area['city'] ?? null,
                    'county' => $area['county'] ?? null,
                    'is_primary' => (bool) ($area['is_primary'] ?? $index === 0),
                ]);
            }
        });

        return $profile->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $windows
     */
    public function replaceAvailability(StockTakerProfile $profile, array $windows): StockTakerProfile
    {
        foreach ($windows as $window) {
            if (($window['end_time'] ?? '') <= ($window['start_time'] ?? '')) {
                throw ValidationException::withMessages([
                    'availability' => ['Availability end time must be after the start time.'],
                ]);
            }
        }

        DB::transaction(function () use ($profile, $windows): void {
            StockTakerAvailability::where('profile_id', $profile->id)->delete();

            foreach ($windows as $window) {
                StockTakerAvailability::create([
                    'profile_id' => $profile->id,
                    'weekday' => (int) $window['weekday'],
                    'start_time' => $window['start_time'],
                    'end_time' => $window['end_time'],
                    'timezone' => $window['timezone'] ?? 'Africa/Nairobi',
                    'active' => (bool) ($window['active'] ?? true),
                ]);
            }
        });

        return $profile->fresh();
    }

    public function recordTraining(StockTakerProfile $profile, array $data, User $actor): StockTakerProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor): StockTakerProfile {
            StockTakerTrainingCompletion::updateOrCreate(
                ['profile_id' => $profile->id, 'module' => $data['module']],
                [
                    'status' => $data['status'] ?? 'completed',
                    'score' => $data['score'] ?? null,
                    'completed_at' => ($data['status'] ?? 'completed') === 'completed' ? now() : null,
                    'recorded_by' => $actor->id,
                    'notes' => $data['notes'] ?? null,
                ],
            );

            $coreComplete = $profile->trainings()
                ->whereIn('module', WorkforceProgressionService::REQUIRED_TRAINING)
                ->where('status', 'completed')
                ->exists();
            $profile->training_completed_at = $coreComplete ? now() : null;
            $profile->save();

            $this->progression->evaluate($profile);

            return $profile->fresh();
        });
    }

    public function recordVerification(StockTakerProfile $profile, array $data, User $actor): StockTakerProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor): StockTakerProfile {
            $status = $data['decision'] ?? 'verified';

            StockTakerVerification::create([
                'profile_id' => $profile->id,
                'check_type' => $data['check_type'],
                'status' => $status,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'verified_by' => $actor->id,
                'verified_at' => $status === 'pending' ? null : now(),
            ]);

            if ($data['check_type'] === 'phone') {
                $profile->phone_verified_at = $status === 'verified' ? now() : null;
            }
            if ($data['check_type'] === 'identity') {
                $profile->identity_verified_at = $status === 'verified' ? now() : null;
            }

            $profile->verification_status = $this->deriveVerificationStatus($profile);
            $profile->save();

            $this->progression->evaluate($profile);

            return $profile->fresh();
        });
    }

    public function setLevel(StockTakerProfile $profile, string $level): StockTakerProfile
    {
        $profile->level = $level;
        $profile->save();

        return $profile->fresh();
    }

    public function payload(StockTakerProfile $profile, bool $includeRestricted = false): array
    {
        $profile->loadMissing([
            'user', 'skills', 'serviceAreas', 'availabilityWindows', 'trainings', 'verifications', 'assignments', 'earnings',
        ]);

        $activeAssignments = $profile->assignments
            ->whereIn('status', ['offered', 'accepted'])
            ->count();
        $completedAssignments = $profile->assignments->where('status', 'completed')->count();
        $earningsByStatus = $profile->earnings->groupBy('status')->map(
            fn ($rows) => round((float) $rows->sum('amount'), 2)
        );

        return [
            'id' => $profile->uuid,
            'user' => [
                'id' => $profile->user?->uuid,
                'name' => $profile->user?->name,
                'email' => $profile->user?->email,
            ],
            'legal_name' => $profile->legal_name,
            'phone' => $profile->phone,
            'city' => $profile->city,
            'county' => $profile->county,
            'country' => $profile->country,
            'languages' => $profile->languages ?? [],
            'transport' => $profile->transport,
            'experience_years' => $profile->experience_years,
            'bio' => $profile->bio,
            'level' => $profile->level,
            'level_label' => $this->levelLabel($profile->level),
            'status' => $profile->status,
            'verification_status' => $profile->verification_status,
            'training_completed' => $this->progression->trainingComplete($profile),
            'terms_accepted_at' => $profile->terms_accepted_at?->toIso8601String(),
            'phone_verified_at' => $profile->phone_verified_at?->toIso8601String(),
            'identity_verified_at' => $profile->identity_verified_at?->toIso8601String(),
            'training_completed_at' => $profile->training_completed_at?->toIso8601String(),
            'completed_jobs_count' => $profile->completed_jobs_count,
            'verified_accuracy' => $profile->verified_accuracy,
            'reliability_score' => $profile->reliability_score,
            'skills' => $profile->skills->map(fn (StockTakerSkill $skill) => [
                'id' => $skill->uuid,
                'skill' => $skill->skill,
                'level' => $skill->level,
                'years_experience' => $skill->years_experience,
                'verified' => $skill->verified_at !== null,
            ])->values()->all(),
            'service_areas' => $profile->serviceAreas->map(fn (StockTakerServiceArea $area) => [
                'id' => $area->uuid,
                'area' => $area->area,
                'city' => $area->city,
                'county' => $area->county,
                'is_primary' => $area->is_primary,
            ])->values()->all(),
            'availability' => $profile->availabilityWindows->map(fn (StockTakerAvailability $window) => [
                'id' => $window->uuid,
                'weekday' => $window->weekday,
                'weekday_label' => $this->weekdayLabel($window->weekday),
                'start_time' => substr((string) $window->start_time, 0, 5),
                'end_time' => substr((string) $window->end_time, 0, 5),
                'timezone' => $window->timezone,
                'active' => $window->active,
            ])->values()->all(),
            'trainings' => $profile->trainings->map(fn (StockTakerTrainingCompletion $training) => [
                'module' => $training->module,
                'status' => $training->status,
                'score' => $training->score,
                'completed_at' => $training->completed_at?->toIso8601String(),
            ])->values()->all(),
            'verification_checks' => $profile->verifications->map(function (StockTakerVerification $check) use ($includeRestricted) {
                $row = [
                    'check_type' => $check->check_type,
                    'status' => $check->status,
                    'verified_at' => $check->verified_at?->toIso8601String(),
                ];
                if ($includeRestricted) {
                    $row['reference'] = $check->reference;
                    $row['notes'] = $check->notes;
                    $row['verified_by'] = $check->verifiedBy?->name;
                }

                return $row;
            })->values()->all(),
            'stats' => [
                'active_assignments' => $activeAssignments,
                'completed_assignments' => $completedAssignments,
                'earnings' => (object) $earningsByStatus->all(),
            ],
        ];
    }

    public function summary(StockTakerProfile $profile): array
    {
        $profile->loadMissing(['user', 'skills', 'serviceAreas', 'availabilityWindows']);

        return [
            'id' => $profile->uuid,
            'user' => [
                'id' => $profile->user?->uuid,
                'name' => $profile->user?->name,
                'email' => $profile->user?->email,
            ],
            'legal_name' => $profile->legal_name,
            'city' => $profile->city,
            'county' => $profile->county,
            'level' => $profile->level,
            'level_label' => $this->levelLabel($profile->level),
            'status' => $profile->status,
            'verification_status' => $profile->verification_status,
            'training_completed' => $this->progression->trainingComplete($profile),
            'experience_years' => $profile->experience_years,
            'skills' => $profile->skills->pluck('skill')->values()->all(),
            'service_areas' => $profile->serviceAreas->pluck('area')->values()->all(),
            'availability_windows' => $profile->availabilityWindows->count(),
            'completed_jobs_count' => $profile->completed_jobs_count,
            'verified_accuracy' => $profile->verified_accuracy,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }

    private function deriveVerificationStatus(StockTakerProfile $profile): string
    {
        $latest = $profile->verifications()
            ->get()
            ->groupBy('check_type')
            ->map(fn ($checks) => $checks->first());

        $identity = $latest->get('identity')?->status;
        $phone = $latest->get('phone')?->status;

        if ($identity === 'verified' && $phone === 'verified') {
            return 'verified';
        }
        if ($identity === 'rejected' || $phone === 'rejected') {
            return 'rejected';
        }
        if ($identity !== null || $phone !== null) {
            return 'pending';
        }

        return 'unverified';
    }

    /**
     * Used by assignment matching to resolve job locations to searchable text.
     *
     * @return array<int, string>
     */
    public function jobLocationTexts(StocktakeJob $job): array
    {
        $texts = [];
        $scope = $job->scope ?? [];

        foreach ((array) ($scope['branches'] ?? []) as $branch) {
            $model = Branch::withoutGlobalScope('tenant')->where('uuid', $branch['id'] ?? null)->first();
            $texts = array_merge($texts, array_filter([
                $branch['name'] ?? null,
                $branch['code'] ?? null,
                $model?->city,
                $model?->address,
                $model?->name,
            ]));
        }

        foreach ((array) ($scope['locations'] ?? []) as $location) {
            $texts = array_merge($texts, array_filter([
                $location['name'] ?? null,
                $location['branch'] ?? null,
            ]));
        }

        $texts[] = $scope['label'] ?? null;

        return array_values(array_unique(array_filter(array_map(
            fn ($text) => is_string($text) ? trim($text) : null,
            $texts,
        ))));
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'senior_supervisor' => 'Senior / Supervisor',
            'applicant' => 'Applicant',
            default => ucfirst($level),
        };
    }

    private function weekdayLabel(int $weekday): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$weekday] ?? (string) $weekday;
    }
}
