<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketing\AnalyticsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicAnalyticsController extends Controller
{
    public function consent(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'analytics' => ['required', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'policy_version' => ['nullable', 'string', 'max:32'],
        ]);

        $preference = $analytics->recordConsent(
            $data['session_id'],
            (bool) $data['analytics'],
            (bool) ($data['marketing'] ?? false),
            $data['policy_version'] ?? null,
        );

        return ApiResponse::success([
            'necessary' => true,
            'analytics' => $preference->analytics,
            'marketing' => $preference->marketing,
            'policy_version' => $preference->policy_version,
        ]);
    }

    public function events(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'events' => ['required', 'array', 'min:1', 'max:25'],
            'events.*.name' => ['required', 'string', Rule::in(AnalyticsService::EVENT_NAMES)],
            'events.*.url' => ['nullable', 'string', 'max:2048'],
            'events.*.path' => ['nullable', 'string', 'max:512'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.device' => ['nullable', 'string', Rule::in(['mobile', 'tablet', 'desktop'])],
            'events.*.utm' => ['nullable', 'array'],
            'events.*.utm.*' => ['nullable', 'string', 'max:190'],
            'events.*.payload' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        if (! $analytics->hasAnalyticsConsent($data['session_id'])) {
            return ApiResponse::error(
                'CONSENT_REQUIRED',
                'Analytics consent has not been granted for this session, so no events were stored.',
                422,
            );
        }

        $stored = $analytics->ingest($data['session_id'], $data['events'], $request);

        return ApiResponse::success(['accepted' => $stored], 202);
    }
}
