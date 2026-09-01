<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
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
