<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Marketing\MarketingPageService;
use App\Services\Marketing\PublicPageResolver;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketingPageController extends Controller
{
    public function __construct(
        private readonly MarketingPageService $pages,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(MarketingPageService::STATUSES)],
            'type' => ['nullable', Rule::in(['page', 'landing', 'legal', 'system'])],
        ]);

        return ApiResponse::success($this->pages->list($filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $page = $this->pages->create($data, $request->user());

        $this->audit->log('marketing.page.created', $page, null, null, ['slug' => $page->slug]);

        return ApiResponse::success(['page' => $this->pages->present($page)], 201);
    }

    public function show(CmsPage $page): JsonResponse
    {
        return ApiResponse::success(['page' => $this->pages->present($page)]);
    }

    public function update(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $before = ['status' => $page->status, 'slug' => $page->slug, 'title' => $page->title];

        $page = $this->pages->update($page, $data, $request->user());

        $this->audit->log('marketing.page.updated', $page, null, $before, [
            'status' => $page->status,
            'slug' => $page->effectiveSlug(),
            'title' => $page->title,
        ]);

        return ApiResponse::success(['page' => $this->pages->present($page)]);
    }

    public function sections(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate([
            'sections' => ['present', 'array', 'max:40'],
            'sections.*.type' => ['required', Rule::in(PublicPageResolver::SUPPORTED_SECTIONS)],
            'sections.*.content' => ['sometimes', 'array'],
            'sections.*.settings' => ['sometimes', 'array'],
            'sections.*.is_visible' => ['sometimes', 'boolean'],
        ]);

        $this->pages->replaceSections($page, $data['sections'], $request->user(), recordVersion: true);
        $page = $page->fresh(['sections', 'seo', 'currentVersion']);

        $this->audit->log('marketing.page.sections_replaced', $page, null, null, [
            'section_count' => $page->sections->count(),
        ]);

        return ApiResponse::success(['page' => $this->pages->present($page)]);
    }

    public function submitReview(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate(['change_summary' => ['nullable', 'string', 'max:255']]);
        $version = $this->pages->submitReview($page, $request->user(), $data['change_summary'] ?? null);

        $this->audit->log('marketing.page.submitted_review', $page, $data['change_summary'] ?? null);

        return ApiResponse::success($this->transitionPayload($page, $version));
    }

    public function approve(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate(['change_summary' => ['nullable', 'string', 'max:255']]);
        $version = $this->pages->approve($page, $request->user(), $data['change_summary'] ?? null);

        $this->audit->log('marketing.page.approved', $page, $data['change_summary'] ?? null);

        return ApiResponse::success($this->transitionPayload($page, $version));
    }

    public function reject(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $version = $this->pages->reject($page, $request->user(), $data['reason']);

        $this->audit->log('marketing.page.rejected', $page, $data['reason']);

        return ApiResponse::success($this->transitionPayload($page, $version));
    }

    public function schedule(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $version = $this->pages->schedule($page, $request->user(), new \DateTimeImmutable($data['scheduled_at']));

        $this->audit->log('marketing.page.scheduled', $page, null, null, ['scheduled_at' => $data['scheduled_at']]);

        return ApiResponse::success($this->transitionPayload($page, $version));
    }

    public function publish(CmsPage $page, Request $request): JsonResponse
    {
        $data = $request->validate(['change_summary' => ['nullable', 'string', 'max:255']]);
        $version = $this->pages->publish($page, $request->user(), $data['change_summary'] ?? null);

        $this->audit->log('marketing.page.published', $page, null, null, [
            'version_no' => $version->version_no,
            'slug' => $page->fresh()->effectiveSlug(),
        ]);

        return ApiResponse::success($this->transitionPayload($page, $version));
    }

    public function unpublish(CmsPage $page, Request $request): JsonResponse
    {
        $page = $this->pages->unpublish($page, $request->user());

        $this->audit->log('marketing.page.unpublished', $page);

        return ApiResponse::success(['page' => $this->pages->present($page)]);
    }

    public function versions(CmsPage $page): JsonResponse
    {
        $versions = $page->versions()->with('author')->get();

        return ApiResponse::success([
            'versions' => $versions->map(fn (CmsPageVersion $version) => $this->versionPayload($version))->all(),
            'current_version_id' => $page->currentVersion?->uuid,
        ]);
    }

    public function restoreVersion(CmsPage $page, CmsPageVersion $version, Request $request): JsonResponse
    {
        $page = $this->pages->restoreVersion($page, $version, $request->user());

        $this->audit->log('marketing.page.version_restored', $page, null, null, [
            'restored_version' => $version->version_no,
        ]);

        return ApiResponse::success(['page' => $this->pages->present($page)]);
    }

    public function preview(CmsPage $page, Request $request): JsonResponse
    {
        $version = $this->pages->previewVersion(
            $page,
            CmsPageVersion::query()
                ->where('page_id', $page->id)
                ->when($request->query('version'), fn ($query, $uuid) => $query->where('uuid', $uuid))
                ->orderByDesc('version_no')
                ->first(),
        );

        return ApiResponse::success([
            'url' => $this->pages->previewUrl($page, $version),
            'version_id' => $version->uuid,
            'version_no' => $version->version_no,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ]);
    }

    public function destroy(CmsPage $page, Request $request): JsonResponse
    {
        $this->pages->delete($page, $request->user());

        $this->audit->log('marketing.page.deleted', $page, null, ['slug' => $page->slug]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionPayload(CmsPage $page, CmsPageVersion $version): array
    {
        return [
            'page' => $this->pages->present($page->fresh()),
            'version' => $this->versionPayload($version),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionPayload(CmsPageVersion $version): array
    {
        return [
            'id' => $version->uuid,
            'version_no' => $version->version_no,
            'status' => $version->status,
            'change_summary' => $version->change_summary,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'slug' => [$presence, 'string', 'max:180', 'regex:/^[a-z0-9]+(?:[-\/][a-z0-9]+)*$/'],
            'title' => [$presence, 'string', 'max:200'],
            'type' => ['sometimes', Rule::in(['page', 'landing', 'legal', 'system'])],
            'template' => ['sometimes', 'string', 'max:120'],
            'locale' => ['sometimes', 'string', 'max:12'],
            'requires_auth' => ['sometimes', 'boolean'],
            'noindex' => ['sometimes', 'boolean'],
            'seo' => ['sometimes', 'array'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.canonical_url' => ['nullable', 'url', 'max:2048'],
            'seo.robots_index' => ['sometimes', 'boolean'],
            'seo.robots_follow' => ['sometimes', 'boolean'],
            'seo.og_title' => ['nullable', 'string', 'max:255'],
            'seo.og_description' => ['nullable', 'string', 'max:500'],
            'seo.twitter_card' => ['sometimes', Rule::in(['summary', 'summary_large_image'])],
            'seo.priority' => ['sometimes', 'numeric', 'between:0,1'],
            'seo.change_frequency' => ['sometimes', Rule::in(['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'])],
            'sections' => ['sometimes', 'array', 'max:40'],
            'sections.*.type' => ['required', Rule::in(PublicPageResolver::SUPPORTED_SECTIONS)],
            'sections.*.content' => ['sometimes', 'array'],
            'sections.*.settings' => ['sometimes', 'array'],
            'sections.*.is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
