<?php

namespace App\Http\Controllers\Api\V1\Workforce;

use App\Http\Controllers\Controller;
use App\Models\JobAssignment;
use App\Services\Operations\JobOperationsService;
use App\Services\Workforce\WorkforceProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OperationsController extends Controller
{
    public function show(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertOwned($request, $assignment, $profiles);

        return ApiResponse::success($operations->workerPayload($assignment));
    }

    public function acknowledge(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertOwned($request, $assignment, $profiles);

        return ApiResponse::success(
            $operations->acknowledge($assignment, $request->user(), $request->ip(), $request->userAgent()),
        );
    }

    public function checklist(
        Request $request,
        JobAssignment $assignment,
        string $item,
        WorkforceProfileService $profiles,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertOwned($request, $assignment, $profiles);

        $data = $request->validate([
            'value' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $operations->respondChecklist($assignment, $item, $data, $request->user());

        return ApiResponse::success($operations->workerPayload($assignment));
    }

    public function checkIn(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertOwned($request, $assignment, $profiles);

        $data = $request->validate([
            'site_label' => ['nullable', 'string', 'max:160'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'distance_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'method' => ['nullable', 'string', 'in:gps,manual'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $operations->checkIn($assignment, $data, $request->user());

        return ApiResponse::success($operations->workerPayload($assignment));
    }

    public function checkOut(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        JobOperationsService $operations,
    ): JsonResponse {
        $this->assertOwned($request, $assignment, $profiles);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $operations->checkOut($assignment, $data, $request->user());

        return ApiResponse::success($operations->workerPayload($assignment));
    }

    private function assertOwned(Request $request, JobAssignment $assignment, WorkforceProfileService $profiles): void
    {
        $profile = $profiles->forUser($request->user());
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');
        abort_unless($assignment->profile_id === $profile->id, 404);
    }
}
