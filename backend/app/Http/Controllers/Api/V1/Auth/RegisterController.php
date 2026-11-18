<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\EmailVerification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final class RegisterController extends Controller
{
    public function __invoke(
        RegisterRequest $request,
        EmailVerification $verification,
        AuditLogger $audit,
    ): JsonResponse {
        $user = User::create($request->validated());

        $token = $user->createToken($request->string('device_name')->toString() ?: 'web');

        $code = $verification->issue($user);
        $audit->log('auth.registered', $user, actorId: $user->id);

        return ApiResponse::success(array_filter([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
            'verification_test_code' => $verification->testMode() ? $code : null,
        ], fn ($value) => $value !== null), 201);
    }
}
