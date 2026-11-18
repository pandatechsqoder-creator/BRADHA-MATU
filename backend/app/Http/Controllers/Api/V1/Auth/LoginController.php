<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, AuditLogger $audit): JsonResponse
    {
        $user = User::where('email', $request->string('email')->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            $audit->log('auth.login_failed', null, null, null, [
                'email' => $request->string('email')->toString(),
            ]);

            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken($request->string('device_name')->toString() ?: 'web');
        $audit->log('auth.login', $user, actorId: $user->id);

        return ApiResponse::success([
            'user' => new UserResource($user),
            'token' => $token->plainTextToken,
        ]);
    }
}
