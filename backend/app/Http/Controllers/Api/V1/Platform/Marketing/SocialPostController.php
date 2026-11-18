<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Models\Marketing\SocialPost;
use App\Services\Marketing\Meta\SocialPostService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SocialPostController extends Controller
{
    private const STATUSES = ['draft', 'review', 'approved', 'rejected', 'scheduled', 'published', 'failed'];

    public function __construct(private readonly SocialPostService $posts) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $result = $this->posts->list($filters['status'] ?? null);
        $result['options'] = $this->posts->formOptions();

        return ApiResponse::success($result);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        try {
            $post = $this->posts->create($request->user(), $data);
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(['post' => $this->posts->show($post)], 201);
    }

    public function show(SocialPost $post): JsonResponse
    {
        return ApiResponse::success(['post' => $this->posts->show($post)]);
    }

    public function update(SocialPost $post, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        try {
            $post = $this->posts->update($post, $request->user(), $data);
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(['post' => $this->posts->show($post)]);
    }

    public function destroy(SocialPost $post, Request $request): JsonResponse
    {
        try {
            $this->posts->delete($post, $request->user());
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(['deleted' => true]);
    }

    public function submitReview(SocialPost $post, Request $request): JsonResponse
    {
        return $this->run(fn () => $this->posts->submitReview($post, $request->user()));
    }

    public function approve(SocialPost $post, Request $request): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        return $this->run(fn () => $this->posts->approve($post, $request->user(), $data['notes'] ?? null));
    }

    public function reject(SocialPost $post, Request $request): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        return $this->run(fn () => $this->posts->reject($post, $request->user(), $data['notes']));
    }

    public function schedule(SocialPost $post, Request $request): JsonResponse
    {
        $data = $request->validate(['scheduled_at' => ['required', 'date', 'after:now']]);

        return $this->run(fn () => $this->posts->schedule($post, $request->user(), $data['scheduled_at']));
    }

    public function publish(SocialPost $post, Request $request): JsonResponse
    {
        return $this->run(fn () => $this->posts->publish($post, $request->user()));
    }

    /**
     * @param  callable(): SocialPost  $action
     */
    private function run(callable $action): JsonResponse
    {
        try {
            $post = $action();
        } catch (MarketingIntegrationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->status);
        }

        return ApiResponse::success(['post' => $this->posts->show($post->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'caption' => [$presence, 'string', 'max:2200'],
            'meta_page_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'meta_instagram_account_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'media_asset_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('media_assets', 'uuid')->whereNull('deleted_at')],
            'link_url' => ['sometimes', 'nullable', 'url', 'max:1024'],
        ];
    }
}
