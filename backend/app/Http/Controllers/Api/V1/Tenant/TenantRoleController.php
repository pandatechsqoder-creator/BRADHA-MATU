<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantRoleController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $this->assertCan($request, $tenant, 'tenant.view');

        return ApiResponse::success(
            Role::where('scope', 'tenant')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'slug' => $role->slug,
                    'name' => $role->name,
                    'description' => $role->description,
                ])
                ->values()
        );
    }
}
