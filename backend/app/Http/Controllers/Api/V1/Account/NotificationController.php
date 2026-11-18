<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'notifications' => $notifications->listFor($user, [
                'filter' => $request->query('filter'),
                'type' => $request->query('type'),
                'limit' => $request->query('limit'),
            ]),
            'unread_count' => $notifications->unreadCount($user),
        ]);
    }

    public function markRead(Request $request, Notification $notification, NotificationService $notifications): JsonResponse
    {
        return ApiResponse::success($notifications->markRead($request->user(), $notification));
    }

    public function markAllRead(Request $request, NotificationService $notifications): JsonResponse
    {
        $count = $notifications->markAllRead($request->user());

        return ApiResponse::success(['marked' => $count]);
    }

    public function preferences(Request $request, NotificationService $notifications): JsonResponse
    {
        return ApiResponse::success($notifications->preferences($request->user()));
    }

    public function updatePreferences(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'in_app_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'digest_frequency' => ['sometimes', Rule::in(NotificationService::DIGEST_FREQUENCIES)],
            'event_preferences' => ['sometimes', 'array'],
            'event_preferences.*' => ['boolean'],
        ]);

        return ApiResponse::success($notifications->updatePreferences($request->user(), $data));
    }
}
