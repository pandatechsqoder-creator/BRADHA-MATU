<?php

namespace App\Http\Controllers\Api\V1\Workforce;

use App\Http\Controllers\Controller;
use App\Models\CountSession;
use App\Models\JobAssignment;
use App\Services\Count\OfflineSyncService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfflineSyncController extends Controller
{
    /**
     * Download the offline job package — scope, SOP identifiers, approved units.
     * Used by the browser to populate its local cache before going offline.
     */
    public function package(Request $request, JobAssignment $assignment, OfflineSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'device_fingerprint' => ['nullable', 'string', 'max:200'],
            'platform' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);

        return ApiResponse::success(
            $sync->jobPackage(
                $assignment,
                $request->user(),
                $data['device_fingerprint'] ?? null,
                $data['platform'] ?? null,
                $data['app_version'] ?? null,
            ),
        );
    }

    /**
     * Batch sync endpoint.
     * Accepts an array of offline-queued operations and returns per-item sync status.
     */
    public function sync(Request $request, JobAssignment $assignment, CountSession $session, OfflineSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'device_session_id' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.idempotency_key' => ['required', 'string', 'max:200'],
            'items.*.type' => ['required', 'string', 'in:entry,exception,unknown_product'],
            // entry fields
            'items.*.variant_id' => ['nullable', 'ulid'],
            'items.*.raw_quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.raw_unit_id' => ['nullable', 'ulid'],
            'items.*.observations' => ['nullable', 'array'],
            'items.*.captured_at' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:2000'],
            'items.*.device_session_id' => ['nullable', 'string', 'max:60'],
            // exception / unknown_product fields
            'items.*.exception_type' => ['nullable', 'string'],
            'items.*.type_detail' => ['nullable', 'string'],
            'items.*.barcode' => ['nullable', 'string', 'max:200'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            'items.*.name_guess' => ['nullable', 'string', 'max:200'],
            'items.*.location' => ['nullable', 'string', 'max:200'],
        ]);

        return ApiResponse::success($sync->syncBatch($assignment, $session, $data, $request->user()));
    }

    /**
     * Device heartbeat — records that the device is online without a full package download.
     */
    public function heartbeat(Request $request, JobAssignment $assignment, OfflineSyncService $sync): JsonResponse
    {
        $data = $request->validate([
            'device_fingerprint' => ['nullable', 'string', 'max:200'],
            'platform' => ['nullable', 'string', 'max:100'],
            'offline_capable' => ['boolean'],
        ]);

        $sync->heartbeat(
            $assignment,
            $request->user(),
            $data['device_fingerprint'] ?? null,
            $data['platform'] ?? null,
            (bool) ($data['offline_capable'] ?? false),
        );

        return ApiResponse::success(['acknowledged' => true]);
    }
}
