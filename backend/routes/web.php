<?php

use App\Http\Controllers\Web\MarketingPreviewController;
use App\Http\Controllers\Web\MediaDeliveryController;
use App\Http\Controllers\Web\PublicArticleController;
use App\Http\Controllers\Web\PublicFormController;
use App\Http\Controllers\Web\PublicPageController;
use App\Http\Controllers\Web\RobotsController;
use App\Http\Controllers\Web\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicPageController::class, 'home'])->name('home');
Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/rss.xml', [PublicArticleController::class, 'feed'])->name('resources.feed');
Route::get('/resources', [PublicArticleController::class, 'index'])->name('resources.index');
Route::get('/resources/categories/{slug}', [PublicArticleController::class, 'category'])->name('resources.category');
Route::get('/resources/tags/{slug}', [PublicArticleController::class, 'tag'])->name('resources.tag');
Route::get('/resources/downloads/{slug}', [PublicArticleController::class, 'downloadPage'])->name('resources.download');
Route::post('/resources/downloads/{slug}', [PublicArticleController::class, 'download'])
    ->middleware('throttle:public-forms')
    ->name('resources.download.request');
Route::get('/resources/{slug}', [PublicArticleController::class, 'show'])->name('resources.show');
Route::post('/forms/{form}', [PublicFormController::class, 'store'])
    ->whereIn('form', ['quote', 'pos-demo', 'contact', 'callback', 'partnership'])
    ->middleware('throttle:public-forms')
    ->name('forms.submit');
Route::get('/media/{uuid}', MediaDeliveryController::class)->name('media.show');
Route::get('/preview/{page}/{version}', MarketingPreviewController::class)
    ->middleware('signed')
    ->name('marketing.preview');

Route::fallback([PublicPageController::class, 'fallback']);
