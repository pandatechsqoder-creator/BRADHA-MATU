<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\MarketingConsent;
use App\Models\Marketing\MarketingLead;
use App\Models\User;
use App\Services\Marketing\LeadService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LeadController extends Controller
{
    public function __construct(private readonly LeadService $leads) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(MarketingLead::STATUSES)],
            'type' => ['nullable', Rule::in(MarketingLead::TYPES)],
            'source' => ['nullable', 'string', 'max:40'],
            'assignee' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:190'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::success($this->leads->list($filters, $request->user()));
    }

    public function show(MarketingLead $lead): JsonResponse
    {
        return ApiResponse::success(['lead' => $this->leads->present($lead)]);
    }

    public function assignees(): JsonResponse
    {
        $users = User::query()
            ->whereHas('roles', fn ($query) => $query->whereNull('user_roles.tenant_id'))
            ->orderBy('name')
            ->get(['id', 'uuid', 'name', 'email'])
            ->map(fn (User $user) => [
                'id' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ])
            ->all();

        return ApiResponse::success(['assignees' => $users]);
    }

    public function update(MarketingLead $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(MarketingLead::STATUSES)],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        if (isset($data['status'])) {
            $this->leads->updateStatus($lead, $data['status'], $request->user());
        }
        if (array_key_exists('message', $data)) {
            $lead->forceFill(['message' => $data['message']])->save();
        }

        return ApiResponse::success(['lead' => $this->leads->present($lead->fresh())]);
    }

    public function status(MarketingLead $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(MarketingLead::STATUSES)],
        ]);

        $this->leads->updateStatus($lead, $data['status'], $request->user());

        return ApiResponse::success(['lead' => $this->leads->present($lead->fresh())]);
    }

    public function assign(MarketingLead $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'assigned_to' => ['required', 'string', 'exists:users,uuid'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $assignee = User::query()->where('uuid', $data['assigned_to'])->firstOrFail();
        $this->leads->assign($lead, $assignee, $request->user(), $data['reason'] ?? null);

        return ApiResponse::success(['lead' => $this->leads->present($lead->fresh())]);
    }

    public function activities(MarketingLead $lead): JsonResponse
    {
        $lead->load('activities.author:id,uuid,name');

        return ApiResponse::success([
            'activities' => $lead->activities->map(fn ($activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'body' => $activity->body,
                'metadata' => $activity->metadata ?? [],
                'author' => $activity->author ? ['id' => $activity->author->uuid, 'name' => $activity->author->name] : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function addActivity(MarketingLead $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(LeadService::ACTIVITY_TYPES)],
            'body' => ['required', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $activity = $this->leads->addActivity($lead, $data, $request->user());

        return ApiResponse::success([
            'activity' => [
                'id' => $activity->id,
                'type' => $activity->type,
                'body' => $activity->body,
                'created_at' => $activity->created_at?->toIso8601String(),
            ],
            'lead' => $this->leads->present($lead->fresh()),
        ], 201);
    }

    public function consents(MarketingLead $lead): JsonResponse
    {
        return ApiResponse::success(['lead' => $this->leads->present($lead)]);
    }

    public function recordConsent(MarketingLead $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(MarketingConsent::CHANNELS)],
            'status' => ['required', Rule::in(['granted', 'withdrawn'])],
        ]);

        $this->leads->recordConsent($lead, $data['channel'], $data['status'], $request->user());

        return ApiResponse::success(['lead' => $this->leads->present($lead->fresh())]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(MarketingLead::STATUSES)],
            'type' => ['nullable', Rule::in(MarketingLead::TYPES)],
            'source' => ['nullable', 'string', 'max:40'],
            'assignee' => ['nullable', 'string', 'max:40'],
            'campaign' => ['nullable', 'string', 'max:190'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return $this->leads->export($filters, $request->user());
    }
}
