<?php

namespace App\Http\Controllers\Api\V1\Workforce;

use App\Http\Controllers\Controller;
use App\Models\CountEntry;
use App\Models\CountSession;
use App\Models\JobAssignment;
use App\Services\Count\CountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CountController extends Controller
{
    public function workspace(Request $request, JobAssignment $assignment, CountService $counts): JsonResponse
    {
        return ApiResponse::success($counts->workspace($assignment, $request->user()));
    }

    public function start(Request $request, JobAssignment $assignment, CountService $counts): JsonResponse
    {
        $data = $request->validate([
            'device' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:200'],
        ]);

        return ApiResponse::success($counts->startSession($assignment, $data, $request->user()), 201);
    }

    public function show(Request $request, JobAssignment $assignment, CountSession $session, CountService $counts): JsonResponse
    {
        return ApiResponse::success($counts->session($assignment, $session, $request->user()));
    }

    public function upsertEntry(Request $request, JobAssignment $assignment, CountSession $session, CountService $counts): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:200'],
            'variant_id' => ['required', 'ulid'],
            'stock_location_id' => ['nullable', 'ulid'],
            'raw_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'raw_unit_id' => ['required', 'ulid'],
            'observations' => ['nullable', 'array'],
            'observations.*' => ['string', 'in:good,damaged,expired,quarantined,missing_label,inaccessible,unknown'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'captured_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success($counts->upsertEntry($assignment, $session, $data, $request->user()));
    }

    public function reviseEntry(Request $request, JobAssignment $assignment, CountSession $session, CountEntry $entry, CountService $counts): JsonResponse
    {
        $data = $request->validate([
            'raw_quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'raw_unit_id' => ['required', 'ulid'],
            'observations' => ['nullable', 'array'],
            'observations.*' => ['string', 'in:good,damaged,expired,quarantined,missing_label,inaccessible,unknown'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return ApiResponse::success($counts->reviseEntry($assignment, $session, $entry, $data, $request->user()));
    }

    public function exception(Request $request, JobAssignment $assignment, CountSession $session, CountService $counts): JsonResponse
    {
        $data = $request->validate([
            'variant_id' => ['nullable', 'ulid'],
            'barcode' => ['nullable', 'string', 'max:200'],
            'type' => ['required', 'string', 'in:mismatched_barcode,wrong_label,unexpected_variant,packaging,other'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'observed_unit_id' => ['nullable', 'ulid'],
        ]);

        return ApiResponse::success($counts->reportException($assignment, $session, $data, $request->user()), 201);
    }

    public function unknownProduct(Request $request, JobAssignment $assignment, CountSession $session, CountService $counts): JsonResponse
    {
        $data = $request->validate([
            'barcode' => ['nullable', 'string', 'max:200'],
            'name_guess' => ['nullable', 'string', 'max:200'],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'location' => ['nullable', 'string', 'max:200'],
            'observed_unit_id' => ['nullable', 'ulid'],
            'evidence' => ['nullable', 'array'],
        ]);

        return ApiResponse::success($counts->reportUnknownProduct($assignment, $session, $data, $request->user()), 201);
    }

    public function submit(Request $request, JobAssignment $assignment, CountSession $session, CountService $counts): JsonResponse
    {
        return ApiResponse::success($counts->submit($assignment, $session, $request->user()));
    }
}
