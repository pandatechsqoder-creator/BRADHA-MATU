<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsMenu;
use App\Models\Marketing\CmsMenuItem;
use App\Models\Marketing\CmsPage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

final class PublicMenu
{
    /**
     * @return array<int, array{label: string, url: string, path: string, target: string, active: bool, children: array<int, mixed>}>
     */
    public function items(string $key): array
    {
        try {
            $menu = CmsMenu::query()->where('key', $key)->first();
        } catch (QueryException) {
            return [];
        }

        if ($menu === null) {
            return [];
        }

        $items = $menu->items()
            ->whereNull('parent_id')
            ->where('is_visible', true)
            ->with(['page', 'children.page'])
            ->get();

        return $this->mapItems($items);
    }

    /**
     * @param  Collection<int, CmsMenuItem>  $items
     * @return array<int, array{label: string, url: string, path: string, target: string, active: bool, children: array<int, mixed>}>
     */
    private function mapItems(Collection $items): array
    {
        return $items
            ->map(fn (CmsMenuItem $item) => $this->mapItem($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{label: string, url: string, path: string, target: string, active: bool, children: array<int, mixed>}|null
     */
    private function mapItem(CmsMenuItem $item): ?array
    {
        $url = null;

        if ($item->page_id !== null) {
            if ($item->page === null || ! $this->isPublished($item->page)) {
                return null;
            }

            $url = $item->page->is_home ? url('/') : url('/'.$item->page->slug);
        } elseif ($item->url !== null && $item->url !== '') {
            $url = $item->url;
        }

        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $path = '/'.ltrim($path, '/');

        $children = $item->children
            ->map(fn (CmsMenuItem $child) => $this->mapItem($child))
            ->filter()
            ->values()
            ->all();

        return [
            'label' => $item->label,
            'url' => $url,
            'path' => $path,
            'target' => $item->target === '_blank' ? '_blank' : '_self',
            'active' => $this->isActive($path),
            'children' => $children,
        ];
    }

    private function isPublished(CmsPage $page): bool
    {
        return $page->published_at !== null
            && $page->published_at->isPast()
            && ! $page->trashed();
    }

    private function isActive(string $path): bool
    {
        $current = '/'.ltrim(request()->path(), '/');
        $current = $current === '/' ? '/' : rtrim($current, '/');

        if ($path === '/') {
            return $current === '/';
        }

        return $current === $path || str_starts_with($current, rtrim($path, '/').'/');
    }
}
