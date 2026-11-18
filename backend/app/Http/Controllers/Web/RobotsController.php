<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Marketing\PublicUrls;
use Illuminate\Http\Response;

final class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        if (! config('marketing.indexable')) {
            return response(
                "User-agent: *\nDisallow: /\n",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $private = [
            '/api/',
            '/app',
            '/business',
            '/pos',
            '/platform',
            '/workforce',
            '/login',
            '/register',
            '/forgot-password',
            '/verify',
            '/onboarding',
            '/profile',
            '/settings',
            '/notifications',
            '/support',
            '/invitations/',
            '/preview/',
            '/forms/',
        ];

        $lines = ['User-agent: *', 'Allow: /'];

        foreach ($private as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.PublicUrls::path('sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
