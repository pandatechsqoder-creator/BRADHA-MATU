<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\Support\SupportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SupportController extends Controller
{
    public function index(Request $request, SupportService $support): JsonResponse
    {
        return ApiResponse::success($support->listForPlatform([
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'search' => $request->query('search'),
        ]));
    }

    public function show(string $ticket, SupportService $support): JsonResponse
    {
        return ApiResponse::success($support->show($this->resolve($ticket)));
    }

    public function reply(Request $request, string $ticket, SupportService $support): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success($support->reply(
            $this->resolve($ticket),
            $request->user(),
            $data['body'],
            (bool) ($data['internal'] ?? false),
            true,
        ));
    }

    public function update(Request $request, string $ticket, SupportService $support): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(SupportService::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportService::PRIORITIES)],
            'assigned_to' => ['sometimes', 'nullable', 'string'],
        ]);

        return ApiResponse::success(
            $support->update($this->resolve($ticket), $data, $request->user(), true),
        );
    }

    private function resolve(string $uuid): SupportTicket
    {
        return SupportTicket::query()->withoutGlobalScopes()->where('uuid', $uuid)->firstOrFail();
    }
}
