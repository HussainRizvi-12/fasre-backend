<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('FASRE Admin Portal')
            ->brandLogo(fn () => new HtmlString('<div class="flex items-center gap-2.5"><img src="' . asset('images/crest.png') . '" class="h-8 w-8 rounded-full ring-2 ring-[#f5c518]/70 shadow-sm" /><span class="font-extrabold text-white text-[15px] tracking-tight">FASRE <span class="text-[#f5c518]">Admin</span></span></div>'))
            ->font('Inter')
            ->colors([
                'primary' => Color::hex('#1E3A8A'),
                'gray' => Color::Slate,
                'warning' => Color::hex('#F5C518'),
                'danger' => Color::Red,
                'success' => Color::Emerald,
                'info' => Color::Sky,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="' . asset('css/custom-admin.css') . '">'
            )
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                'Evaluation Config',
                'Reviews & Audits',
                'Academic Directory',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Custom dashboard widgets will be auto-discovered
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
