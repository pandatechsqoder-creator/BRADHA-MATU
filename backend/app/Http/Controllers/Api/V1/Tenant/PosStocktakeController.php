<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\PosSale;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Services\Pos\PosStocktakeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PosStocktakeController extends Controller
{
    use ChecksTenantAccess;

    public function checkCatalogueReadiness(Request $request, Tenant $tenant, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'stocktake.request.create']);
        $readiness = $service->checkCatalogueReadiness($tenant);

        return ApiResponse::success([
            'catalogue_readiness' => $readiness['ready'],
            'product_count' => $readiness['product_count'],
            'minimum_required' => $readiness['minimum_required'],
            'blocks' => $readiness['blocks'],
            'prepaid_eligible' => $readiness['prepaid_eligible'],
            'can_commission' => $readiness['can_commission'],
        ]);
    }

    public function eligibility(Request $request, Tenant $tenant, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'stocktake.request.create']);

        return ApiResponse::success($service->fetchPosStocktakeEligibility($tenant));
    }

    public function jobs(Request $request, Tenant $tenant, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'stocktake.request.create']);

        return ApiResponse::success($service->listJobs($tenant));
    }

    public function commissionStocktake(Request $request, Tenant $tenant, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['stocktake.request.create', 'pos.manage']);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'mode' => ['sometimes', 'string', 'in:reminder,proposed'],
            'frequency' => ['sometimes', 'string', 'in:on_demand,weekly,fortnightly,monthly,quarterly,half_yearly,annual,custom'],
            'scope_config' => ['sometimes', 'array'],
            'recurrence_config' => ['sometimes', 'array'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after:starts_at'],
            'minimum_team_size' => ['nullable', 'integer', 'min:1'],
            'estimated_duration_hours' => ['nullable', 'integer', 'min:1'],
            'pos_reference' => ['nullable', 'string', 'max:50'],
            'authorisation_notes' => ['nullable', 'string', 'max:500'],
            'prepaid_agreement_id' => ['nullable', 'string', 'max:100'],
        ]);

        $job = $service->commissionStocktake($tenant, $request->user(), $data);

        return ApiResponse::success($service->jobPayload($job), 201);
    }

    public function authorizeStocktakeJob(Request $request, Tenant $tenant, StocktakeJob $job, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'stocktake.request.create']);
        $job = $service->authorizeStocktakeJob($job, $tenant, $request->user());

        return ApiResponse::success($service->jobPayload($job));
    }

    public function declineStocktakeJob(Request $request, Tenant $tenant, StocktakeJob $job, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.manage', 'stocktake.request.create']);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $job = $service->declineStocktakeJob($job, $tenant, $request->user(), $data['reason']);

        return ApiResponse::success($service->jobPayload($job));
    }

    public function generateQuote(Request $request, Tenant $tenant, PosSale $sale, PosStocktakeService $service): JsonResponse
    {
        $this->assertAnyCan($request, $tenant, ['pos.sales.create', 'pos.manage']);

        return ApiResponse::success($service->generateQuoteFromPos($sale, $tenant));
    }
}
