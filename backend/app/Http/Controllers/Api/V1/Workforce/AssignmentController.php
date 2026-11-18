<?php

namespace App\Http\Controllers\Api\V1\Workforce;

use App\Http\Controllers\Controller;
use App\Models\JobAssignment;
use App\Models\StockTakerProfile;
use App\Services\Workforce\AssignmentService;
use App\Services\Workforce\WorkforceProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssignmentController extends Controller
{
    public function index(Request $request, WorkforceProfileService $profiles, AssignmentService $assignments): JsonResponse
    {
        $profile = $this->profile($request, $profiles);

        return ApiResponse::success([
            'data' => $assignments->workerAssignments($profile),
        ]);
    }

    public function show(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        AssignmentService $assignments,
    ): JsonResponse {
        $profile = $this->profile($request, $profiles);
        abort_unless($assignment->profile_id === $profile->id, 404);

        return ApiResponse::success($assignments->workerPayload($assignment));
    }

    public function accept(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        AssignmentService $assignments,
    ): JsonResponse {
        $profile = $this->profile($request, $profiles);
        abort_unless($assignment->profile_id === $profile->id, 404);

        $assignment = $assignments->accept($assignment, $request->user());

        return ApiResponse::success($assignments->workerPayload($assignment));
    }

    public function decline(
        Request $request,
        JobAssignment $assignment,
        WorkforceProfileService $profiles,
        AssignmentService $assignments,
    ): JsonResponse {
        $profile = $this->profile($request, $profiles);
        abort_unless($assignment->profile_id === $profile->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $assignment = $assignments->decline($assignment, $request->user(), $data['reason']);

        return ApiResponse::success($assignments->workerPayload($assignment));
    }

    public function earnings(Request $request, WorkforceProfileService $profiles, AssignmentService $assignments): JsonResponse
    {
        $profile = $this->profile($request, $profiles);

        return ApiResponse::success($assignments->workerEarnings($profile));
    }

    private function profile(Request $request, WorkforceProfileService $profiles): StockTakerProfile
    {
        $profile = $profiles->forUser($request->user());
        abort_if($profile === null, 404, 'Create your stock-taker profile first.');

        return $profile;
    }
}
