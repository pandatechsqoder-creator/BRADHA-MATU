<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Marketing\PublicPageResolver;
use App\Services\Marketing\SeoRedirectService;
use App\Services\Marketing\StructuredDataService;
use Illuminate\Http\Request;

final class PublicPageController extends Controller
{
    public function home(PublicPageResolver $resolver, StructuredDataService $structuredData)
    {
        return $this->render($resolver, '', $structuredData);
    }

    public function fallback(
        Request $request,
        PublicPageResolver $resolver,
        SeoRedirectService $redirects,
        StructuredDataService $structuredData,
    ) {
        if ($request->is('api/*') || $request->expectsJson()) {
            abort(404);
        }

        $page = $resolver->find($request->path());

        if ($page !== null) {
            return $this->respond($page, $structuredData);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            $redirect = $redirects->resolve($request->path());

            if ($redirect !== null) {
                return redirect($redirect['location'], $redirect['status']);
            }
        }

        abort(404);
    }

    private function render(PublicPageResolver $resolver, string $path, StructuredDataService $structuredData)
    {
        $page = $resolver->find($path);

        if ($page === null) {
            abort(404);
        }

        return $this->respond($page, $structuredData);
    }

    private function respond(array $page, StructuredDataService $structuredData)
    {
        $page['structured_data'] = $structuredData->build($page);

        return response()->view($page['template'], ['page' => $page]);
    }
}
