<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\BlogPost;
use App\Models\Marketing\EditorialItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EditorialItemController extends Controller
{
    private const STATUSES = ['planned', 'in_progress', 'review', 'done'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $items = EditorialItem::query()
            ->with(['assignee', 'post'])
            ->orderByRaw("case when status = 'done' then 1 else 0 end")
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'editorial_items' => $items->map(fn (EditorialItem $item) => $this->payload($item))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $item = EditorialItem::create($this->attributes($data));

        $this->audit->log('marketing.editorial_item.created', $item, null, null, ['title' => $item->title]);

        return ApiResponse::success(['editorial_item' => $this->payload($item->fresh(['assignee', 'post']))], 201);
    }

    public function update(EditorialItem $editorialItem, Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));
        $editorialItem->update($this->attributes($data));

        $this->audit->log('marketing.editorial_item.updated', $editorialItem, null, null, [
            'status' => $editorialItem->status,
        ]);

        return ApiResponse::success(['editorial_item' => $this->payload($editorialItem->fresh(['assignee', 'post']))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$presence, 'string', 'max:200'],
            'brief' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assignee_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('users', 'uuid')],
            'due_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'blog_post_id' => ['sometimes', 'nullable', 'string', 'size:26', Rule::exists('blog_posts', 'uuid')->whereNull('deleted_at')],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [];

        foreach (['title', 'brief', 'due_on', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        if (array_key_exists('assignee_id', $data)) {
            $attributes['assignee_id'] = $data['assignee_id'] === null
                ? null
                : User::query()->where('uuid', $data['assignee_id'])->value('id');
        }

        if (array_key_exists('blog_post_id', $data)) {
            $attributes['blog_post_id'] = $data['blog_post_id'] === null
                ? null
                : BlogPost::query()->where('uuid', $data['blog_post_id'])->value('id');
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EditorialItem $item): array
    {
        return [
            'id' => $item->uuid,
            'title' => $item->title,
            'brief' => $item->brief,
            'assignee_id' => $item->assignee?->uuid,
            'assignee_name' => $item->assignee?->name,
            'due_on' => $item->due_on?->toDateString(),
            'status' => $item->status,
            'blog_post_id' => $item->post?->uuid,
            'blog_post_title' => $item->post?->title,
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
