<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsBanner;
use App\Models\Marketing\MediaAsset;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketingBannerController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $banners = CmsBanner::query()->with('asset')->orderBy('key')->orderByDesc('starts_at')->get();

        return ApiResponse::success([
            'banners' => $banners->map(fn (CmsBanner $banner) => $this->payload($banner))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['key'] = $data['key'] ?? 'site';
        $banner = CmsBanner::create($this->attributes($data));

        if ($banner->is_active) {
            $this->deactivateOthers($banner);
        }

        $this->audit->log('marketing.banner.created', $banner, null, null, $this->payload($banner));

        return ApiResponse::success(['banner' => $this->payload($banner->fresh('asset'))], 201);
    }

    public function update(CmsBanner $banner, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $before = $this->payload($banner);
        $banner->update($this->attributes($data, $banner));

        if ($banner->is_active) {
            $this->deactivateOthers($banner);
        }

        $this->audit->log('marketing.banner.updated', $banner, null, $before, $this->payload($banner->fresh('asset')));

        return ApiResponse::success(['banner' => $this->payload($banner->fresh('asset'))]);
    }

    public function destroy(CmsBanner $banner): JsonResponse
    {
        $banner->delete();
        $this->audit->log('marketing.banner.deleted', $banner, null, ['key' => $banner->key]);

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'key' => ['sometimes', 'alpha_dash', 'max:40'],
            'title' => [$presence, 'string', 'max:160'],
            'body' => ['nullable', 'string', 'max:500'],
            'media_asset_id' => ['nullable', 'string', 'size:26', Rule::exists('media_assets', 'uuid')->whereNull('deleted_at')],
            'link_url' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?CmsBanner $banner = null): array
    {
        $attributes = [];

        foreach (['key', 'title', 'body', 'link_url', 'starts_at', 'ends_at', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('media_asset_id', $data)) {
            $attributes['media_asset_id'] = $data['media_asset_id'] === null
                ? null
                : MediaAsset::query()->where('uuid', $data['media_asset_id'])->value('id');
        }

        return $attributes;
    }

    private function deactivateOthers(CmsBanner $banner): void
    {
        CmsBanner::query()
            ->where('key', $banner->key)
            ->whereKeyNot($banner->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CmsBanner $banner): array
    {
        return [
            'id' => $banner->uuid,
            'key' => $banner->key,
            'title' => $banner->title,
            'body' => $banner->body,
            'media_asset_id' => $banner->asset?->uuid,
            'image_url' => $banner->asset?->publicUrl(),
            'link_url' => $banner->link_url,
            'starts_at' => $banner->starts_at?->toIso8601String(),
            'ends_at' => $banner->ends_at?->toIso8601String(),
            'is_active' => $banner->is_active,
        ];
    }
}
