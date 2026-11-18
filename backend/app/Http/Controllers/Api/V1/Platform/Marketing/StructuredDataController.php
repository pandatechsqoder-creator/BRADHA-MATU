<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsPage;
use App\Services\Audit\AuditLogger;
use App\Services\Marketing\MarketingPageService;
use App\Services\Marketing\StructuredDataService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StructuredDataController extends Controller
{
    public function __construct(
        private readonly StructuredDataService $structuredData,
        private readonly MarketingPageService $pages,
        private readonly AuditLogger $audit,
    ) {}

    public function show(CmsPage $page): JsonResponse
    {
        return ApiResponse::success($this->payload($page));
    }

    public function update(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate([
            'types' => ['sometimes', 'array'],
            'types.*' => ['string', Rule::in(StructuredDataService::REQUESTABLE_TYPES)],
            'overrides' => ['sometimes', 'array'],
            'overrides.service_type' => ['nullable', 'string', 'max:120'],
            'overrides.application_category' => ['nullable', 'string', 'max:120'],
        ]);

        $this->structuredData->saveSettings(
            $page,
            $data['types'] ?? [],
            array_filter($data['overrides'] ?? [], fn ($value) => $value !== null && $value !== ''),
        );

        $this->audit->log('marketing.structured_data.updated', $page, null, null, [
            'types' => $data['types'] ?? [],
        ]);

        return ApiResponse::success($this->payload($page));
    }

    public function validate(CmsPage $page, Request $request): JsonResponse
    {
        $snapshot = $this->pages->snapshot($page);
        $result = $this->structuredData->validateSnapshot($page, $snapshot);
        $run = $this->structuredData->recordRun($page, $result['validation'], $result['graph'], $request->user());

        $this->audit->log('marketing.structured_data.validated', $page, null, null, [
            'status' => $result['validation']['status'],
            'issues' => count($result['validation']['issues']),
        ]);

        return ApiResponse::success([
            'structured_data' => $result['graph'],
            'validation' => $result['validation'],
            'run' => [
                'id' => $run->uuid,
                'status' => $run->status,
                'created_at' => $run->created_at?->toIso8601String(),
            ],
            'runs' => $this->structuredData->runs($page),
        ]);
    }

    public function runs(CmsPage $page): JsonResponse
    {
        return ApiResponse::success(['runs' => $this->structuredData->runs($page)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CmsPage $page): array
    {
        $snapshot = $this->pages->snapshot($page);
        $result = $this->structuredData->validateSnapshot($page, $snapshot);

        return [
            'page' => [
                'id' => $page->uuid,
                'slug' => $page->effectiveSlug(),
                'title' => $page->title,
                'status' => $page->status,
                'type' => $page->type,
            ],
            'settings' => $this->structuredData->settingsFor($page),
            'types' => $result['validation']['types'],
            'structured_data' => $result['graph'],
            'validation' => $result['validation'],
            'runs' => $this->structuredData->runs($page),
        ];
    }
}
