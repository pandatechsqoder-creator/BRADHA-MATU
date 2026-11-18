<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MediaUsage;
use Illuminate\Database\Eloquent\Model;

final class MediaUsageService
{
    public function syncPage(CmsPage $page): void
    {
        MediaUsage::query()
            ->where('usable_type', CmsPage::class)
            ->where('usable_id', $page->id)
            ->delete();

        $uuids = [];
        $page->loadMissing('sections');

        foreach ($page->sections as $section) {
            $this->collectUuids($section->content, $uuids);
            $this->collectUuids($section->settings, $uuids);
        }

        if ($uuids === []) {
            return;
        }

        $assets = MediaAsset::query()->whereIn('uuid', array_values(array_unique($uuids)))->get();

        foreach ($assets as $asset) {
            MediaUsage::create([
                'media_asset_id' => $asset->id,
                'usable_type' => CmsPage::class,
                'usable_id' => $page->id,
                'field' => 'sections',
                'created_at' => now(),
            ]);
        }
    }

    public function record(Model $usable, ?int $assetId, string $field): void
    {
        if ($assetId === null) {
            return;
        }

        MediaUsage::query()
            ->where('usable_type', $usable::class)
            ->where('usable_id', $usable->getKey())
            ->where('field', $field)
            ->delete();

        MediaUsage::create([
            'media_asset_id' => $assetId,
            'usable_type' => $usable::class,
            'usable_id' => $usable->getKey(),
            'field' => $field,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, array{id: string, type: string, label: string, field: string}>
     */
    public function forAsset(MediaAsset $asset): array
    {
        return $asset->usages()
            ->get()
            ->map(function (MediaUsage $usage): array {
                $label = match ($usage->usable_type) {
                    CmsPage::class => CmsPage::withTrashed()->whereKey($usage->usable_id)->value('title') ?? 'Unknown page',
                    default => class_basename($usage->usable_type).' #'.$usage->usable_id,
                };

                return [
                    'id' => $usage->usable_type.'#'.$usage->usable_id,
                    'type' => class_basename($usage->usable_type),
                    'label' => $label,
                    'field' => $usage->field,
                ];
            })
            ->values()
            ->all();
    }

    private function collectUuids(mixed $value, array &$uuids): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->collectUuids($item, $uuids);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        if (preg_match_all('/[0-9A-HJKMNP-TV-Z]{26}/i', $value, $matches)) {
            foreach ($matches[0] as $match) {
                $uuids[] = $match;
            }
        }
    }
}
