<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Marketing\CmsPage;
use App\Models\Marketing\CmsPageVersion;
use App\Services\Marketing\PublicPageResolver;
use App\Services\Marketing\StructuredDataService;

final class MarketingPreviewController extends Controller
{
    public function __invoke(
        string $page,
        CmsPageVersion $version,
        PublicPageResolver $resolver,
        StructuredDataService $structuredData,
    ) {
        $cmsPage = CmsPage::query()->withTrashed()->where('uuid', $page)->firstOrFail();

        abort_unless((int) $version->page_id === (int) $cmsPage->id, 404);

        $view = $resolver->preview($cmsPage, $version);
        $view['structured_data'] = $structuredData->build($view);

        return response()
            ->view('public.page', ['page' => $view])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
