<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MarketingRedirect;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeoRedirectService
{
    public const STATUS_CODES = [301, 302, 307, 308];

    public function normalise(string $path): string
    {
        $path = trim($path);

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $path = (string) parse_url($path, PHP_URL_PATH);
        }

        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function recordSlugChange(CmsPage $page, string $oldSlug, ?User $user): ?MarketingRedirect
    {
        if ($page->is_home) {
            return null;
        }

        $from = $this->normalise($oldSlug);
        $to = $this->normalise($page->slug);

        if ($from === '/' || $from === $to) {
            return null;
        }

        return MarketingRedirect::query()->updateOrCreate(
            ['from_path' => $from],
            [
                'to_path' => $to,
                'status_code' => 301,
                'page_id' => $page->id,
                'reason' => 'Page slug changed',
                'is_active' => true,
                'created_by' => $user?->id,
            ],
        );
    }

    /**
     * Resolve a path through the redirect table, following chains and stopping on loops.
     *
     * @return array{location: string, status: int}|null
     */
    public function resolve(string $path, int $maxHops = 5): ?array
    {
        $current = $this->normalise($path);
        $seen = [$current => true];
        $location = null;
        $status = null;

        for ($hop = 0; $hop < $maxHops; $hop++) {
            $redirect = MarketingRedirect::query()
                ->where('from_path', $current)
                ->where('is_active', true)
                ->first();

            if ($redirect === null) {
                break;
            }

            $redirect->increment('hits');

            $location ??= $redirect->to_path;
            $status ??= $redirect->status_code;
            $next = $this->normalise($redirect->to_path);

            if (isset($seen[$next]) || $next === $current) {
                break;
            }

            $seen[$next] = true;
            $location = $redirect->to_path;

            if ($this->isPublishedPath($next) || $next === '/') {
                break;
            }

            $current = $next;
        }

        if ($location === null) {
            return null;
        }

        return ['location' => $location, 'status' => $status ?? 301];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return MarketingRedirect::query()
            ->with('page:id,uuid,slug,title')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MarketingRedirect $redirect) => $this->present($redirect))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(MarketingRedirect $redirect): array
    {
        return [
            'id' => $redirect->uuid,
            'from_path' => $redirect->from_path,
            'to_path' => $redirect->to_path,
            'status_code' => $redirect->status_code,
            'is_active' => $redirect->is_active,
            'hits' => $redirect->hits,
            'reason' => $redirect->reason,
            'page' => $redirect->page ? [
                'id' => $redirect->page->uuid,
                'slug' => $redirect->page->slug,
                'title' => $redirect->page->title,
            ] : null,
            'created_at' => $redirect->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{from_path: string, to_path: string, status_code?: int, reason?: ?string}  $data
     */
    public function create(array $data, User $user): MarketingRedirect
    {
        $from = $this->normalise($data['from_path']);
        $to = $this->normalise($data['to_path']);

        if ($from === $to) {
            throw ValidationException::withMessages(['to_path' => 'A redirect must point to a different path.']);
        }

        if (MarketingRedirect::query()->where('from_path', $from)->exists()) {
            throw ValidationException::withMessages(['from_path' => 'A redirect already exists for this path.']);
        }

        if ($this->wouldLoop($from, $to)) {
            throw ValidationException::withMessages(['to_path' => 'This redirect would create a loop.']);
        }

        return MarketingRedirect::create([
            'from_path' => $from,
            'to_path' => $to,
            'status_code' => $data['status_code'] ?? 301,
            'reason' => $data['reason'] ?? 'Manual redirect',
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array{to_path?: string, status_code?: int, is_active?: bool, reason?: ?string}  $data
     */
    public function update(MarketingRedirect $redirect, array $data): MarketingRedirect
    {
        $to = isset($data['to_path']) ? $this->normalise($data['to_path']) : $redirect->to_path;

        if ($to === $redirect->from_path) {
            throw ValidationException::withMessages(['to_path' => 'A redirect must point to a different path.']);
        }

        if ($this->wouldLoop($redirect->from_path, $to)) {
            throw ValidationException::withMessages(['to_path' => 'This redirect would create a loop.']);
        }

        $redirect->fill([
            'to_path' => $to,
            'status_code' => $data['status_code'] ?? $redirect->status_code,
            'is_active' => $data['is_active'] ?? $redirect->is_active,
            'reason' => array_key_exists('reason', $data) ? $data['reason'] : $redirect->reason,
        ])->save();

        return $redirect->fresh();
    }

    public function delete(MarketingRedirect $redirect): void
    {
        $redirect->delete();
    }

    private function wouldLoop(string $from, string $to): bool
    {
        $current = $to;
        $seen = [$from => true];

        for ($hop = 0; $hop < 10; $hop++) {
            if (isset($seen[$current])) {
                return true;
            }

            $seen[$current] = true;

            $next = MarketingRedirect::query()
                ->where('from_path', $current)
                ->where('is_active', true)
                ->value('to_path');

            if ($next === null) {
                return false;
            }

            $current = $this->normalise($next);
        }

        return true;
    }

    private function isPublishedPath(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        return DB::table('cms_pages')
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where(function ($query) use ($path): void {
                $query->where('slug', ltrim($path, '/'))
                    ->orWhere('draft_slug', ltrim($path, '/'));
            })
            ->exists();
    }
}
