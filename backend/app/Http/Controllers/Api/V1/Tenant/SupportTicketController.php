<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Services\Support\SupportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SupportTicketController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, SupportService $support): JsonResponse
    {
        $this->assertCan($request, $tenant, 'support.request');

        return ApiResponse::success($support->listForTenant($tenant, [
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'search' => $request->query('search'),
        ]));
    }

    public function store(Request $request, Tenant $tenant, SupportService $support): JsonResponse
    {
        $this->assertCan($request, $tenant, 'support.request');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'category' => ['sometimes', Rule::in(SupportService::CATEGORIES)],
            'priority' => ['sometimes', Rule::in(SupportService::PRIORITIES)],
        ]);

        return ApiResponse::success(
            $support->create($tenant, $request->user(), $data),
            201,
        );
    }

    public function show(Request $request, Tenant $tenant, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $this->assertCan($request, $tenant, 'support.request');
        abort_unless($ticket->tenant_id === $tenant->id, 404);

        return ApiResponse::success($support->show($ticket));
    }

    public function reply(Request $request, Tenant $tenant, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $this->assertCan($request, $tenant, 'support.request');
        abort_unless($ticket->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        return ApiResponse::success(
            $support->reply($ticket, $request->user(), $data['body'], false, false),
        );
    }

    public function update(Request $request, Tenant $tenant, SupportTicket $ticket, SupportService $support): JsonResponse
    {
        $this->assertCan($request, $tenant, 'support.request');
        abort_unless($ticket->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'closed'])],
        ]);

        return ApiResponse::success(
            $support->update($ticket, $data, $request->user(), false),
        );
    }
}
