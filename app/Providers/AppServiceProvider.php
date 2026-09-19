<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login throttling keyed on the TARGET ACCOUNT. A per-IP budget alone
        // can be reset by spoofing X-Forwarded-For; the per-email limit
        // cannot, so guessing one user's password stays capped no matter how
        // many source IPs are used. The per-IP limit still bounds
        // cross-account password spraying from a single source.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(10)->by('email:'.mb_strtolower((string) $request->input('email'))),
                Limit::perMinute(20)->by('ip:'.$request->ip()),
            ];
        });

        if (app()->environment('production') || request()->isSecure() || request()->header('x-forwarded-proto') === 'https' || str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Point password reset links at the admin portal's reset screen.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('fasre.frontend_url'), '/').
                '/#/reset-password?token='.urlencode($token).
                '&email='.urlencode($user->email);
        });
    }
}
