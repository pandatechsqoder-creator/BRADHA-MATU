<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\ProcedureTemplate;
use App\Services\Operations\ProcedureService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProcedureTemplateController extends Controller
{
    public function index(Request $request, ProcedureService $procedures): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);

        return ApiResponse::success([
            'templates' => $procedures->templates($filters['search'] ?? null),
        ]);
    }

    public function store(Request $request, ProcedureService $procedures): JsonResponse
    {
        $data = $request->validate($this->rules());
        $template = $procedures->createTemplate($data, $request->user());

        return ApiResponse::success(['template' => $procedures->templatePayload($template)], 201);
    }

    public function show(ProcedureTemplate $template, ProcedureService $procedures): JsonResponse
    {
        return ApiResponse::success(['template' => $procedures->templatePayload($template)]);
    }

    public function update(ProcedureTemplate $template, Request $request, ProcedureService $procedures): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'string', 'max:60'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'retired'])],
        ]);

        $template = $procedures->updateTemplate($template, $data, $request->user());

        return ApiResponse::success(['template' => $procedures->templatePayload($template)]);
    }

    public function publishVersion(ProcedureTemplate $template, Request $request, ProcedureService $procedures): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'require_acknowledgement' => ['sometimes', 'boolean'],
            'steps' => ['sometimes', 'array', 'min:1', 'max:50'],
            'steps.*.title' => ['required', 'string', 'max:160'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.required' => ['boolean'],
            'steps.*.evidence_required' => ['boolean'],
            'checklists' => ['sometimes', 'nullable', 'array', 'max:10'],
            'checklists.*.name' => ['required', 'string', 'max:120'],
            'checklists.*.kind' => ['nullable', Rule::in(['pre_start', 'site', 'close_out'])],
            'checklists.*.required' => ['boolean'],
            'checklists.*.items' => ['required', 'array', 'min:1', 'max:50'],
            'checklists.*.items.*.label' => ['required', 'string', 'max:200'],
            'checklists.*.items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'checklists.*.items.*.response_type' => ['nullable', Rule::in(['boolean', 'text', 'select'])],
            'checklists.*.items.*.required' => ['boolean'],
            'checklists.*.items.*.options' => ['nullable', 'array', 'max:20'],
            'checklists.*.items.*.options.*' => ['string', 'max:120'],
        ]);

        $template = $procedures->publishVersion($template, $data, $request->user());

        return ApiResponse::success(['template' => $procedures->templatePayload($template)], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:60'],
            'title' => ['nullable', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'require_acknowledgement' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:50'],
            'steps.*.title' => ['required', 'string', 'max:160'],
            'steps.*.instructions' => ['nullable', 'string', 'max:2000'],
            'steps.*.required' => ['boolean'],
            'steps.*.evidence_required' => ['boolean'],
            'checklists' => ['nullable', 'array', 'max:10'],
            'checklists.*.name' => ['required', 'string', 'max:120'],
            'checklists.*.kind' => ['nullable', Rule::in(['pre_start', 'site', 'close_out'])],
            'checklists.*.required' => ['boolean'],
            'checklists.*.items' => ['required', 'array', 'min:1', 'max:50'],
            'checklists.*.items.*.label' => ['required', 'string', 'max:200'],
            'checklists.*.items.*.instructions' => ['nullable', 'string', 'max:1000'],
            'checklists.*.items.*.response_type' => ['nullable', Rule::in(['boolean', 'text', 'select'])],
            'checklists.*.items.*.required' => ['boolean'],
            'checklists.*.items.*.options' => ['nullable', 'array', 'max:20'],
            'checklists.*.items.*.options.*' => ['string', 'max:120'],
        ];
    }
}
