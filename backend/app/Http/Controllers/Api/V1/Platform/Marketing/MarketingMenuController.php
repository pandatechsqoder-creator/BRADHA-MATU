<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsMenu;
use App\Models\Marketing\CmsMenuItem;
use App\Models\Marketing\CmsPage;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MarketingMenuController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $menus = CmsMenu::query()->with(['items.page'])->orderBy('key')->get();

        return ApiResponse::success([
            'menus' => $menus->map(fn (CmsMenu $menu) => [
                'id' => $menu->id,
                'key' => $menu->key,
                'name' => $menu->name,
                'items' => $this->tree($menu->items),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'alpha_dash', 'max:40', Rule::unique('cms_menus', 'key')],
            'name' => ['required', 'string', 'max:120'],
        ]);

        $menu = CmsMenu::create($data);
        $this->audit->log('marketing.menu.created', $menu, null, null, ['key' => $menu->key]);

        return ApiResponse::success(['menu' => ['id' => $menu->id, 'key' => $menu->key, 'name' => $menu->name, 'items' => []]], 201);
    }

    public function update(CmsMenu $menu, Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $menu->update($data);
        $this->audit->log('marketing.menu.updated', $menu, null, null, ['key' => $menu->key]);

        return ApiResponse::success(['menu' => ['id' => $menu->id, 'key' => $menu->key, 'name' => $menu->name]]);
    }

    public function destroy(CmsMenu $menu): JsonResponse
    {
        $key = $menu->key;
        $menu->delete();
        $this->audit->log('marketing.menu.deleted', $menu, null, ['key' => $key]);

        return ApiResponse::success(['deleted' => true]);
    }

    public function storeItem(CmsMenu $menu, Request $request): JsonResponse
    {
        $data = $request->validate($this->itemRules());
        $this->assertSingleTarget($data);
        $this->assertDepth($menu, $data['parent_id'] ?? null);

        $item = $menu->items()->create([
            'label' => $data['label'],
            'page_id' => $this->pageId($data['page_id'] ?? null),
            'url' => $data['url'] ?? null,
            'parent_id' => $this->itemId($menu, $data['parent_id'] ?? null),
            'target' => $data['target'] ?? '_self',
            'sort_order' => $data['sort_order'] ?? (int) $menu->items()->max('sort_order') + 1,
            'is_visible' => (bool) ($data['is_visible'] ?? true),
        ]);

        $this->audit->log('marketing.menu_item.created', $item, null, null, ['menu' => $menu->key, 'label' => $item->label]);

        return ApiResponse::success(['item' => $this->itemPayload($item)], 201);
    }

    public function updateItem(CmsMenu $menu, CmsMenuItem $item, Request $request): JsonResponse
    {
        $data = $request->validate($this->itemRules(partial: true));

        if ($item->menu_id !== $menu->id) {
            abort(404);
        }

        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $item->parent_id;

        if ($parentId !== null && (int) $parentId === (int) $item->id) {
            throw ValidationException::withMessages(['parent_id' => 'An item cannot be its own parent.']);
        }

        $merged = [
            'page_id' => array_key_exists('page_id', $data) ? $data['page_id'] : ($item->page_id ? $item->page?->uuid : null),
            'url' => array_key_exists('url', $data) ? $data['url'] : $item->url,
        ];

        $this->assertSingleTarget($merged);
        $this->assertDepth($menu, $parentId, $item);

        $item->update([
            'label' => $data['label'] ?? $item->label,
            'page_id' => array_key_exists('page_id', $data) ? $this->pageId($data['page_id']) : $item->page_id,
            'url' => array_key_exists('url', $data) ? $data['url'] : $item->url,
            'parent_id' => $this->itemId($menu, $parentId),
            'target' => $data['target'] ?? $item->target,
            'sort_order' => $data['sort_order'] ?? $item->sort_order,
            'is_visible' => $data['is_visible'] ?? $item->is_visible,
        ]);

        $this->audit->log('marketing.menu_item.updated', $item, null, null, ['menu' => $menu->key, 'label' => $item->label]);

        return ApiResponse::success(['item' => $this->itemPayload($item->fresh())]);
    }

    public function destroyItem(CmsMenu $menu, CmsMenuItem $item): JsonResponse
    {
        if ($item->menu_id !== $menu->id) {
            abort(404);
        }

        $item->children()->update(['parent_id' => null]);
        $item->delete();

        return ApiResponse::success(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRules(bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'required';

        return [
            'label' => [$presence, 'string', 'max:120'],
            'page_id' => ['nullable', 'string', 'size:26', Rule::exists('cms_pages', 'uuid')->whereNull('deleted_at')],
            'url' => ['nullable', 'string', 'max:500', 'regex:/^(\/|https?:\/\/)/'],
            'parent_id' => ['nullable', 'integer', Rule::exists('cms_menu_items', 'id')],
            'target' => ['sometimes', Rule::in(['_self', '_blank'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSingleTarget(array $data): void
    {
        $hasPage = ! empty($data['page_id']);
        $hasUrl = ! empty($data['url']);

        if ($hasPage === $hasUrl) {
            throw ValidationException::withMessages([
                'url' => 'Provide either a page or an external URL, not both.',
            ]);
        }
    }

    private function assertDepth(CmsMenu $menu, ?int $parentId, ?CmsMenuItem $item = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = CmsMenuItem::query()->where('menu_id', $menu->id)->whereKey($parentId)->first();

        if ($parent === null) {
            throw ValidationException::withMessages(['parent_id' => 'The chosen parent is not part of this menu.']);
        }

        if ($parent->parent_id !== null) {
            throw ValidationException::withMessages(['parent_id' => 'Menus support two levels only.']);
        }

        if ($item !== null && $parent->id === $item->id) {
            throw ValidationException::withMessages(['parent_id' => 'An item cannot be its own parent.']);
        }
    }

    private function pageId(?string $uuid): ?int
    {
        return $uuid === null ? null : CmsPage::withTrashed()->where('uuid', $uuid)->value('id');
    }

    private function itemId(CmsMenu $menu, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }

        $found = CmsMenuItem::query()->where('menu_id', $menu->id)->whereKey($id)->value('id');

        if ($found === null) {
            throw ValidationException::withMessages(['parent_id' => 'The chosen parent is not part of this menu.']);
        }

        return $found;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CmsMenuItem>  $items
     * @return array<int, array<string, mixed>>
     */
    private function tree($items, ?int $parentId = null): array
    {
        return $items
            ->where('parent_id', $parentId)
            ->sortBy('sort_order')
            ->map(fn (CmsMenuItem $item) => $this->itemPayload($item, $this->tree($items, $item->id)))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function itemPayload(CmsMenuItem $item, array $children = []): array
    {
        return [
            'id' => $item->id,
            'label' => $item->label,
            'page_id' => $item->page?->uuid,
            'url' => $item->url ?? ($item->page ? '/'.$item->page->effectiveSlug() : null),
            'target' => $item->target,
            'sort_order' => $item->sort_order,
            'is_visible' => $item->is_visible,
            'children' => $children,
        ];
    }
}
