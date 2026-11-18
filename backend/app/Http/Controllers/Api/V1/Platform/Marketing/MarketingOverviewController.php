<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsBanner;
use App\Models\Marketing\CmsCta;
use App\Models\Marketing\CmsFaq;
use App\Models\Marketing\CmsMenu;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsTestimonial;
use App\Models\Marketing\MediaAsset;
use App\Models\Marketing\MarketingSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketingOverviewController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $pagesByStatus = CmsPage::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $statusCounts = [];
        foreach (['draft', 'review', 'approved', 'scheduled', 'published', 'archived'] as $status) {
            $statusCounts[$status] = (int) ($pagesByStatus[$status] ?? 0);
        }

        return ApiResponse::success([
            'pages' => [
                'total' => CmsPage::query()->count(),
                'by_status' => $statusCounts,
                'live' => CmsPage::query()->published()->count(),
                'scheduled' => CmsPage::query()->where('status', 'scheduled')->count(),
            ],
            'media' => [
                'assets' => MediaAsset::query()->count(),
                'storage_bytes' => (int) MediaAsset::query()->sum('size_bytes'),
            ],
            'navigation' => [
                'menus' => CmsMenu::query()->count(),
                'menu_items' => CmsMenu::query()->withCount('items')->get()->sum('items_count'),
            ],
            'content' => [
                'banners' => CmsBanner::query()->count(),
                'active_banners' => CmsBanner::query()->where('is_active', true)->count(),
                'ctas' => CmsCta::query()->count(),
                'faqs' => CmsFaq::query()->count(),
                'testimonials' => CmsTestimonial::query()->count(),
                'approved_testimonials' => CmsTestimonial::query()->where('is_approved', true)->count(),
            ],
            'workflow' => [
                'separation_of_duties' => (bool) (MarketingSetting::query()
                    ->where('key', 'marketing.separation_of_duties')
                    ->value('value') ?? true),
            ],
        ]);
    }
}
