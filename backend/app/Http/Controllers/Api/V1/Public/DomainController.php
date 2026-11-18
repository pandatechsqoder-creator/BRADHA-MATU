<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\DomainEngine;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DomainController extends Controller
{
    public function suggestions(Request $request, DomainEngine $domains): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'location' => ['nullable', 'string', 'min:2', 'max:100'],
        ]);

        return ApiResponse::success([
            'suggestions' => $domains->suggestions($data['business_name'], $data['location'] ?? null),
            'base_domain' => config('tenancy.base_domain'),
        ]);
    }

    public function check(Request $request, DomainEngine $domains): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => ['required', 'string', 'min:2', 'max:63'],
            'owner_email' => ['nullable', 'email', 'max:255'],
            'registration_attempt_uuid' => ['nullable', 'string', 'max:26'],
        ]);

        try {
            $result = $domains->reserve(
                $data['slug'],
                $data['business_name'],
                $data['owner_email'] ?? null,
                $data['registration_attempt_uuid'] ?? null,
                $request->ip(),
                (int) config('tenancy.domain_reservation_minutes', 30),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['slug' => [$exception->getMessage()]]);
        }

        if ($result['conflict']) {
            return ApiResponse::error(
                'DOMAIN_CONFLICT',
                'The selected domain is no longer available.',
                409,
                ['suggestions' => $result['suggestions']],
            );
        }

        return ApiResponse::success($result);
    }
}
