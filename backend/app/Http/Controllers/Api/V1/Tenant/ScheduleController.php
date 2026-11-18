<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\ScheduleOccurrence;
use App\Models\StocktakeSchedule;
use App\Models\Tenant;
use App\Services\Schedule\ScheduleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ScheduleController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage', 'stocktake.request.create']);

        return ApiResponse::success($schedules->list($tenant));
    }

    public function store(Request $request, Tenant $tenant, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage']);

        return ApiResponse::success($schedules->create($tenant, $request->all(), $request->user()), 201);
    }

    public function show(Request $request, Tenant $tenant, StocktakeSchedule $schedule, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage', 'stocktake.request.create']);
        abort_unless($schedule->tenant_id === $tenant->id, 404);

        return ApiResponse::success($schedules->show($schedule));
    }

    public function update(Request $request, Tenant $tenant, StocktakeSchedule $schedule, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage']);
        abort_unless($schedule->tenant_id === $tenant->id, 404);

        return ApiResponse::success($schedules->update($schedule, $request->all(), $request->user()));
    }

    public function generate(Request $request, Tenant $tenant, StocktakeSchedule $schedule, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage']);
        abort_unless($schedule->tenant_id === $tenant->id, 404);

        $count = (int) $request->input('count', 3);

        return ApiResponse::success($schedules->generateOccurrences($schedule, max(1, min($count, 12))));
    }

    public function trigger(Request $request, Tenant $tenant, StocktakeSchedule $schedule, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage']);
        abort_unless($schedule->tenant_id === $tenant->id, 404);

        return ApiResponse::success($schedules->triggerOnDemand($schedule, $request->user()), 201);
    }

    public function skipOccurrence(Request $request, Tenant $tenant, ScheduleOccurrence $occurrence, ScheduleService $schedules): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.schedule.manage']);
        abort_unless($occurrence->tenant_id === $tenant->id, 404);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::success($schedules->skipOccurrence($occurrence, $data['reason'], $request->user()));
    }
}
