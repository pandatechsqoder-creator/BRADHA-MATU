<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantRegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class RegistrationController extends Controller
{
    public function start(Request $request, TenantRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'display_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'industry' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],
            'county' => ['nullable', 'string', 'max:100'],
            'owner_name' => ['required', 'string', 'min:2', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['nullable', 'string', 'max:30'],
            'slug' => ['required', 'string', 'min:2', 'max:63'],
            'domain_accepted' => ['required', 'accepted'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()->mixedCase()->symbols()],
        ]);

        $result = $registrations->start(
            $data,
            $request->header('Idempotency-Key'),
            $request->ip(),
        );

        if (! empty($result['conflict'])) {
            return ApiResponse::error(
                'DOMAIN_CONFLICT',
                'The selected domain is no longer available.',
                409,
                ['suggestions' => $result['suggestions']],
            );
        }

        return ApiResponse::success($result, 201);
    }

    public function verify(Request $request, TenantRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate([
            'attempt_uuid' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        $result = $registrations->verify($data['attempt_uuid'], $data['code']);

        return ApiResponse::success($result);
    }

    public function resend(Request $request, TenantRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate([
            'attempt_uuid' => ['required', 'string', 'size:26'],
        ]);

        return ApiResponse::success($registrations->resend($data['attempt_uuid']));
    }
}
