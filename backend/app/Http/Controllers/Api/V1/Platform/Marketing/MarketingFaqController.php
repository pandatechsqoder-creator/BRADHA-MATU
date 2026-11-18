<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsFaq;
use App\Models\Marketing\CmsPage;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketingFaqController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['page_id' => ['nullable', 'string', 'size:26']]);

        $faqs = CmsFaq::query()
            ->with('page')
            ->when($filters['page_id'] ?? null, function ($query, string $uuid): void {
                $pageId = CmsPage::withTrashed()->where('uuid', $uuid)->value('id');
                $query->where('page_id', $pageId);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'faqs' => $faqs->map(fn (CmsFaq $faq) => $this->payload($faq))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $faq = CmsFaq::create($this->attributes($data));

        $this->audit->log('marketing.faq.created', $faq, null, null, ['question' => $faq->question]);

        return ApiResponse::success(['faq' => $this->payload($faq->fresh('page'))], 201);
    }

    public function update(CmsFaq $faq, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $faq->update($this->attributes($data));

        $this->audit->log('marketing.faq.updated', $faq, null, null, ['question' => $faq->question]);

        return ApiResponse::success(['faq' => $this->payload($faq->fresh('page'))]);
    }

    public function destroy(CmsFaq $faq): JsonResponse
    {
        $question = $faq->question;
        $faq->delete();
        $this->audit->log('marketing.faq.deleted', $faq, null, ['question' => $question]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'page_id' => ['nullable', 'string', 'size:26', Rule::exists('cms_pages', 'uuid')->whereNull('deleted_at')],
            'category' => ['nullable', 'string', 'max:80'],
            'question' => [$presence, 'string', 'max:300'],
            'answer' => [$presence, 'string', 'max:3000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        foreach (['category', 'question', 'answer', 'sort_order', 'is_visible'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('page_id', $data)) {
            $attributes['page_id'] = $data['page_id'] === null
                ? null
                : CmsPage::withTrashed()->where('uuid', $data['page_id'])->value('id');
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CmsFaq $faq): array
    {
        return [
            'id' => $faq->uuid,
            'page_id' => $faq->page?->uuid,
            'category' => $faq->category,
            'question' => $faq->question,
            'answer' => $faq->answer,
            'sort_order' => $faq->sort_order,
            'is_visible' => $faq->is_visible,
        ];
    }
}
