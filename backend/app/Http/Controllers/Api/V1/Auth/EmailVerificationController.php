<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\EmailVerification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EmailVerificationController extends Controller
{
    public function send(Request $request, EmailVerification $verification, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        if ($user->email_verified_at !== null) {
            return ApiResponse::success(['already_verified' => true]);
        }

        $code = $verification->issue($user);
        $audit->log('auth.verification_sent', $user);

        return ApiResponse::success(array_filter([
            'sent' => true,
            'channel' => 'email',
            'test_code' => $verification->testMode() ? $code : null,
        ], fn ($value) => $value !== null));
    }

    public function confirm(Request $request, EmailVerification $verification, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        if (! $verification->confirm($user, $data['code'])) {
            return ApiResponse::error('INVALID_CODE', 'That verification code is not valid.', 422);
        }

        $audit->log('auth.email_verified', $user);

        return ApiResponse::success(new UserResource($user->refresh()));
    }
}
