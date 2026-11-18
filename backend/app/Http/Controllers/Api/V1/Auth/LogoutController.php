<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LogoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): JsonResponse
    {
        $audit->log('auth.logout', $request->user());
        $request->user()->currentAccessToken()->delete();

        return ApiResponse::success(['logged_out' => true]);
    }
}
