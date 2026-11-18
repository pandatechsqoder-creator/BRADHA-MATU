<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsTestimonial;
use App\Models\Marketing\MediaAsset;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MarketingTestimonialController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $testimonials = CmsTestimonial::query()->with(['asset', 'page'])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success([
            'testimonials' => $testimonials->map(fn (CmsTestimonial $testimonial) => $this->payload($testimonial))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $this->guardApproval($request, $data);

        $testimonial = CmsTestimonial::create($this->attributes($data, $request));

        $this->audit->log('marketing.testimonial.created', $testimonial, null, null, ['author' => $testimonial->author_name]);

        return ApiResponse::success(['testimonial' => $this->payload($testimonial->fresh(['asset', 'page']))], 201);
    }

    public function update(CmsTestimonial $testimonial, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $this->guardApproval($request, $data, $testimonial);

        $testimonial->update($this->attributes($data, $request));

        $this->audit->log('marketing.testimonial.updated', $testimonial, null, null, ['author' => $testimonial->author_name]);

        return ApiResponse::success(['testimonial' => $this->payload($testimonial->fresh(['asset', 'page']))]);
    }

    public function destroy(CmsTestimonial $testimonial): JsonResponse
    {
        $author = $testimonial->author_name;
        $testimonial->delete();
        $this->audit->log('marketing.testimonial.deleted', $testimonial, null, ['author' => $author]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function guardApproval(Request $request, array $data, ?CmsTestimonial $testimonial = null): void
    {
        if (! array_key_exists('is_approved', $data)) {
            return;
        }

        if ($data['is_approved'] && ! $request->user()->hasPermission('cms.review')) {
            throw ValidationException::withMessages([
                'is_approved' => 'Only reviewers can approve a testimonial for publication.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'page_id' => ['nullable', 'string', 'size:26', Rule::exists('cms_pages', 'uuid')->whereNull('deleted_at')],
            'quote' => [$presence, 'string', 'max:2000'],
            'author_name' => [$presence, 'string', 'max:120'],
            'author_role' => ['nullable', 'string', 'max:120'],
            'business_name' => ['nullable', 'string', 'max:160'],
            'media_asset_id' => ['nullable', 'string', 'size:26', Rule::exists('media_assets', 'uuid')->whereNull('deleted_at')],
            'is_approved' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, Request $request): array
    {
        $attributes = [];

        foreach (['quote', 'author_name', 'author_role', 'business_name', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('page_id', $data)) {
            $attributes['page_id'] = $data['page_id'] === null
                ? null
                : CmsPage::withTrashed()->where('uuid', $data['page_id'])->value('id');
        }

        if (array_key_exists('media_asset_id', $data)) {
            $attributes['media_asset_id'] = $data['media_asset_id'] === null
                ? null
                : MediaAsset::query()->where('uuid', $data['media_asset_id'])->value('id');
        }

        if (array_key_exists('is_approved', $data)) {
            $attributes['is_approved'] = $data['is_approved'];
            $attributes['approved_by'] = $data['is_approved'] ? $request->user()->id : null;
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CmsTestimonial $testimonial): array
    {
        return [
            'id' => $testimonial->uuid,
            'page_id' => $testimonial->page?->uuid,
            'quote' => $testimonial->quote,
            'author_name' => $testimonial->author_name,
            'author_role' => $testimonial->author_role,
            'business_name' => $testimonial->business_name,
            'media_asset_id' => $testimonial->asset?->uuid,
            'image_url' => $testimonial->asset?->publicUrl(),
            'is_approved' => $testimonial->is_approved,
            'sort_order' => $testimonial->sort_order,
        ];
    }
}
