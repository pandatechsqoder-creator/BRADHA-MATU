<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->withCount(['members', 'branches']);

        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        $paginator = $query->latest()->paginate(20)->withQueryString();

        return ApiResponse::success(
            TenantResource::collection($paginator->items())->resolve(),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function overview(): JsonResponse
    {
        return ApiResponse::success([
            'tenants' => [
                'total' => Tenant::count(),
                'active' => Tenant::where('status', 'active')->count(),
                'pending' => Tenant::where('status', 'pending')->count(),
                'suspended' => Tenant::where('status', 'suspended')->count(),
            ],
            'users' => [
                'total' => User::count(),
                'verified' => User::whereNotNull('email_verified_at')->count(),
            ],
            'memberships' => [
                'active' => \App\Models\TenantMembership::where('status', 'active')->count(),
            ],
            'audit_events_7d' => \App\Models\AuditLog::where('created_at', '>=', now()->subDays(7))->count(),
        ]);
    }
}
