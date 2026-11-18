<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\StockTakerProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Workforce\AssignmentService;
use App\Services\Workforce\WorkforceProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkforceController extends Controller
{
    public function index(Request $request, WorkforceProfileService $profiles): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'suspended', 'inactive'])],
            'level' => ['nullable', Rule::in(StockTakerProfile::LEVELS)],
            'verification_status' => ['nullable', Rule::in(['unverified', 'pending', 'verified', 'rejected'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StockTakerProfile::query()
            ->with(['user', 'skills', 'serviceAreas', 'availabilityWindows'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['level'] ?? null, fn ($query, $level) => $query->where('level', $level))
            ->when($filters['verification_status'] ?? null, fn ($query, $state) => $query->where('verification_status', $state))
            ->when($filters['search'] ?? null, function ($query, $search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('legal_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id');

        $page = $query->paginate(25);
        $counts = StockTakerProfile::query()
            ->selectRaw('verification_status, count(*) as aggregate')
            ->groupBy('verification_status')
            ->pluck('aggregate', 'verification_status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return ApiResponse::success(
            collect($page->items())->map(fn (StockTakerProfile $profile) => $profiles->summary($profile))->values(),
            200,
            [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'counts' => $counts,
                'pending_verification' => (int) ($counts['pending'] ?? 0) + (int) ($counts['unverified'] ?? 0),
            ],
        );
    }

    public function show(
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AssignmentService $assignments,
    ): JsonResponse {
        $profile->loadMissing(['assignments.job.tenant']);

        return ApiResponse::success([
            'profile' => $profiles->payload($profile, true),
            'assignments' => $profile->assignments
                ->take(20)
                ->map(fn ($assignment) => $assignments->platformPayload($assignment))
                ->values()
                ->all(),
        ]);
    }

    public function update(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'legal_name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'max:40'],
            'transport' => ['nullable', 'string', 'max:120'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'level' => ['sometimes', Rule::in(StockTakerProfile::LEVELS)],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'inactive'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $before = ['level' => $profile->level, 'status' => $profile->status];
        $levelChanging = isset($data['level']) && $data['level'] !== $profile->level;
        if ($levelChanging && empty($data['reason'])) {
            throw ValidationException::withMessages([
                'reason' => ['A reason is required when changing the progression level.'],
            ]);
        }

        $profile = $profiles->updateProfile($profile, $data);
        if (isset($data['level'])) {
            $profile = $profiles->setLevel($profile, $data['level']);
        }
        if (isset($data['status'])) {
            $profile->status = $data['status'];
            $profile->save();
        }

        $audit->log('workforce.profile.managed', $profile, $data['reason'] ?? null, $before, [
            'level' => $profile->level,
            'status' => $profile->status,
        ]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }

    public function verify(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'check_type' => ['required', Rule::in(['identity', 'phone', 'background', 'reference', 'training'])],
            'decision' => ['required', Rule::in(['pending', 'verified', 'rejected'])],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $before = ['verification_status' => $profile->verification_status, 'level' => $profile->level];
        $profile = $profiles->recordVerification($profile, $data, $request->user());
        $audit->log('workforce.profile.verification_recorded', $profile, $data['notes'] ?? null, $before, [
            'check_type' => $data['check_type'],
            'decision' => $data['decision'],
            'verification_status' => $profile->verification_status,
            'level' => $profile->level,
        ]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }

    public function skills(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'skills' => ['present', 'array', 'max:30'],
            'skills.*.skill' => ['required', 'string', 'max:80'],
            'skills.*.level' => ['nullable', Rule::in(['basic', 'intermediate', 'advanced'])],
            'skills.*.years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        $profile = $profiles->replaceSkills($profile, $data['skills']);
        $audit->log('workforce.profile.skills_updated', $profile, null, null, ['count' => count($data['skills'])]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }

    public function serviceAreas(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'areas' => ['present', 'array', 'max:20'],
            'areas.*.area' => ['required', 'string', 'max:120'],
            'areas.*.city' => ['nullable', 'string', 'max:120'],
            'areas.*.county' => ['nullable', 'string', 'max:120'],
            'areas.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $profile = $profiles->replaceServiceAreas($profile, $data['areas']);
        $audit->log('workforce.profile.service_areas_updated', $profile, null, null, ['count' => count($data['areas'])]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }

    public function availability(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'windows' => ['present', 'array', 'max:21'],
            'windows.*.weekday' => ['required', 'integer', 'between:0,6'],
            'windows.*.start_time' => ['required', 'date_format:H:i'],
            'windows.*.end_time' => ['required', 'date_format:H:i'],
            'windows.*.timezone' => ['nullable', 'string', 'max:60'],
            'windows.*.active' => ['nullable', 'boolean'],
        ]);

        $profile = $profiles->replaceAvailability($profile, $data['windows']);
        $audit->log('workforce.profile.availability_updated', $profile, null, null, ['count' => count($data['windows'])]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }

    public function training(
        Request $request,
        StockTakerProfile $profile,
        WorkforceProfileService $profiles,
        AuditLogger $audit,
    ): JsonResponse {
        $data = $request->validate([
            'module' => ['required', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['completed', 'failed'])],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $before = ['level' => $profile->level, 'training_completed_at' => $profile->training_completed_at?->toIso8601String()];
        $profile = $profiles->recordTraining($profile, $data, $request->user());
        $audit->log('workforce.profile.training_recorded', $profile, null, $before, [
            'module' => $data['module'],
            'level' => $profile->level,
        ]);

        return ApiResponse::success(['profile' => $profiles->payload($profile, true)]);
    }
}
