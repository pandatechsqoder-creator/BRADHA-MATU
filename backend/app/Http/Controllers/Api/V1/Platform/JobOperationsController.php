<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\StocktakeJob;
use App\Services\Operations\JobOperationsService;
use App\Services\Operations\ProcedureService;
use App\Services\Tenancy\TenantContext;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class JobOperationsController extends Controller
{
    public function dashboard(JobOperationsService $operations): JsonResponse
    {
        return ApiResponse::success($operations->dashboard());
    }

    public function show(string $job, JobOperationsService $operations, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success($operations->platformPayload($model));
    }

    public function procedure(string $job, ProcedureService $procedures, TenantContext $context): JsonResponse
    {
        $model = $this->findJob($job, $context);

        return ApiResponse::success([
            'procedure' => $procedures->jobProcedurePayload($model),
        ]);
    }

    public function applyProcedure(
        string $job,
        Request $request,
        ProcedureService $procedures,
        JobOperationsService $operations,
        TenantContext $context,
    ): JsonResponse {
        $model = $this->findJob($job, $context);
        $data = $request->validate([
            'template_id' => ['nullable', 'string', 'max:60', 'required_without:title'],
            'version_id' => ['nullable', 'string', 'max:60'],
            'title' => ['nullable', 'required_without:template_id', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'require_acknowledgement' => ['sometimes', 'boolean'],
            'steps' => ['nullable', 'required_without:template_id', 'array', 'min:1', 'max:50'],
            'steps.*.title' => ['required', 'string', 'max:160'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.required' => ['boolean'],
            'steps.*.evidence_required' => ['boolean'],
            'checklists' => ['nullable', 'array', 'max:10'],
            'checklists.*.name' => ['required', 'string', 'max:120'],
            'checklists.*.kind' => ['nullable', 'string', 'in:pre_start,site,close_out'],
            'checklists.*.required' => ['boolean'],
            'checklists.*.items' => ['required', 'array', 'min:1', 'max:50'],
            'checklists.*.items.*.label' => ['required', 'string', 'max:200'],
            'checklists.*.items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'checklists.*.items.*.response_type' => ['nullable', 'string', 'in:boolean,text,select'],
            'checklists.*.items.*.required' => ['boolean'],
            'checklists.*.items.*.options' => ['nullable', 'array', 'max:20'],
            'checklists.*.items.*.options.*' => ['string', 'max:120'],
        ]);

        $procedures->applyToJob($model, $data, $request->user());

        return ApiResponse::success([
            'procedure' => $procedures->jobProcedurePayload($model->fresh()),
            'operations' => $operations->platformPayload($model->fresh()),
        ], 201);
    }

    private function findJob(string $uuid, TenantContext $context): StocktakeJob
    {
        $job = StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $uuid)->firstOrFail();
        $context->set($job->tenant);

        return $job;
    }
}
