<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query()->with(['user', 'tenant']);

        if ($action = $request->string('action')->trim()->toString()) {
            $query->where('action', 'like', "%{$action}%");
        }

        if ($tenantUuid = $request->string('tenant')->trim()->toString()) {
            $tenant = \App\Models\Tenant::where('uuid', $tenantUuid)->first();
            $query->where('tenant_id', $tenant?->id ?? 0);
        }

        $paginator = $query->latest('id')->paginate(25)->withQueryString();

        return ApiResponse::success(
            collect($paginator->items())->map(fn (AuditLog $log) => [
                'id' => $log->uuid,
                'action' => $log->action,
                'actor' => $log->user?->name,
                'tenant_id' => $log->tenant_id,
                'tenant' => $log->tenant?->name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'reason' => $log->reason,
                'request_id' => $log->request_id,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }
}
