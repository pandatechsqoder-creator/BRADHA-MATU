<?php

namespace App\Http\Controllers\Api\V1\Workforce;

use App\Http\Controllers\Controller;
use App\Models\StockTakerProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Workforce\WorkforceProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProfileController extends Controller
{
    public function show(Request $request, WorkforceProfileService $profiles): JsonResponse
    {
        $profile = $profiles->forUser($request->user());

        return ApiResponse::success([
            'profile' => $profile ? $profiles->payload($profile) : null,
        ]);
    }

    public function store(Request $request, WorkforceProfileService $profiles, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate($this->rules());
        $profile = $profiles->apply($request->user(), $data);
        $audit->log('workforce.profile.submitted', $profile, null, null, ['level' => $profile->level]);

        return ApiResponse::success(['profile' => $profiles->payload($profile)], 201);
    }

    public function update(Request $request, WorkforceProfileService $profiles, AuditLogger $audit): JsonResponse
    {
        $profile = $this->requireProfile($request, $profiles);
        $data = $request->validate($this->rules(partial: true));
        $profile = $profiles->updateProfile($profile, $data);
        $audit->log('workforce.profile.updated', $profile, null, null, ['level' => $profile->level]);

        return ApiResponse::success(['profile' => $profiles->payload($profile)]);
    }

    public function skills(Request $request, WorkforceProfileService $profiles, AuditLogger $audit): JsonResponse
    {
        $profile = $this->requireProfile($request, $profiles);
        $data = $request->validate([
            'skills' => ['present', 'array', 'max:30'],
            'skills.*.skill' => ['required', 'string', 'max:80'],
            'skills.*.level' => ['nullable', Rule::in(['basic', 'intermediate', 'advanced'])],
            'skills.*.years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
        ]);

        $profile = $profiles->replaceSkills($profile, $data['skills']);
        $audit->log('workforce.profile.skills_updated', $profile, null, null, ['count' => count($data['skills'])]);

        return ApiResponse::success(['profile' => $profiles->payload($profile)]);
    }

    public function serviceAreas(Request $request, WorkforceProfileService $profiles, AuditLogger $audit): JsonResponse
    {
        $profile = $this->requireProfile($request, $profiles);
        $data = $request->validate([
            'areas' => ['present', 'array', 'max:20'],
            'areas.*.area' => ['required', 'string', 'max:120'],
            'areas.*.city' => ['nullable', 'string', 'max:120'],
            'areas.*.county' => ['nullable', 'string', 'max:120'],
            'areas.*.is_primary' => ['nullable', 'boolean'],
        ]);

        $profile = $profiles->replaceServiceAreas($profile, $data['areas']);
        $audit->log('workforce.profile.service_areas_updated', $profile, null, null, ['count' => count($data['areas'])]);

        return ApiResponse::success(['profile' => $profiles->payload($profile)]);
    }

    public function availability(Request $request, WorkforceProfileService $profiles, AuditLogger $audit): JsonResponse
    {
        $profile = $this->requireProfile($request, $profiles);
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

        return ApiResponse::success(['profile' => $profiles->payload($profile)]);
    }

    private function requireProfile(Request $request, WorkforceProfileService $profiles): StockTakerProfile
    {
        $profile = $profiles->forUser($request->user());
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');

        return $profile;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'legal_name' => [$required, 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:120'],
            'county' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'languages' => ['nullable', 'array', 'max:10'],
            'languages.*' => ['string', 'max:40'],
            'transport' => ['nullable', 'string', 'max:120'],
            'experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'terms_accepted' => ['nullable', 'boolean'],
        ];
    }
}
