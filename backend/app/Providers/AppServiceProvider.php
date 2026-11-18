<?php

namespace App\Providers;

use App\Listeners\TrackEmailAcceptance;
use App\Listeners\TrackEmailProcessing;
use App\Models\User;
use App\Services\Marketing\MarketingSettings;
use App\Services\Marketing\PublicChrome;
use App\Services\Marketing\PublicMenu;
use App\Services\Marketing\PublicPageResolver;
use App\Services\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn ($app) => new TenantContext($app->make('request')));

        $this->app->scoped(MarketingSettings::class);
        $this->app->scoped(PublicMenu::class);
        $this->app->scoped(PublicPageResolver::class);
        $this->app->scoped(PublicChrome::class);
    }

    public function boot(): void
    {
        Event::listen(MessageSending::class, TrackEmailProcessing::class);
        Event::listen(MessageSent::class, TrackEmailAcceptance::class);

        View::composer(['public.layout', 'errors.404', 'errors.410', 'errors::404', 'errors::410'], function ($view): void {
            $view->with('chrome', app(PublicChrome::class)->toArray());
        });
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isPlatformAdmin()) {
                return true;
            }

            return null;
        });

        RateLimiter::for('api', function (Request $request) {
            $perMinute = $request->user() ? 600 : 120;

            return Limit::perMinute($perMinute)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('public-forms', function (Request $request) {
            $identity = Str::lower((string) ($request->input('email') ?? ''))
                .'|'.preg_replace('/\D+/', '', (string) ($request->input('phone') ?? ''));

            return [
                Limit::perMinute(12)->by('form-ip:'.$request->ip()),
                Limit::perMinute(4)->by('form-subject:'.hash('sha256', $identity.'|'.$request->ip())),
            ];
        });

        RateLimiter::for('marketing-export', function (Request $request) {
            return Limit::perMinute(6)->by('marketing-export:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('web-vitals', function (Request $request) {
            return Limit::perMinute(60)->by('web-vitals:'.$request->ip());
        });

        RateLimiter::for('public-events', function (Request $request) {
            return Limit::perMinute(120)->by('public-events:'.$request->ip());
        });
    }
}
