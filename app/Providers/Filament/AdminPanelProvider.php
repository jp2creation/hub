<?php

namespace App\Providers\Filament;

use App\Support\CrmTheme;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->login()
            ->profile(isSimple: false)
            ->darkMode(false)
            ->font('DM Sans')
            ->defaultThemeMode(ThemeMode::Light)
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString('<link href="'.asset('css/filament/crm-filament.css').'?v=2026072702" rel="stylesheet" data-navigate-track />'.CrmTheme::styleTag()->toHtml())
            )
            ->brandName('JP2 Hub')
            ->brandLogo(asset('martin-sols-logo.png'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::hex(CrmTheme::primaryHex()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: base_path('Modules/CrmAdministration/app/Filament/Resources'), for: 'Modules\CrmAdministration\Filament\Resources')
            ->discoverResources(in: base_path('Modules/CrmEquipmentRentals/app/Filament/Resources'), for: 'Modules\CrmEquipmentRentals\Filament\Resources')
            ->discoverResources(in: base_path('Modules/CrmPages/app/Filament/Resources'), for: 'Modules\CrmPages\Filament\Resources')
            ->discoverResources(in: base_path('Modules/CrmReservations/app/Filament/Resources'), for: 'Modules\CrmReservations\Filament\Resources')
            ->discoverResources(in: base_path('Modules/CrmStats/app/Filament/Resources'), for: 'Modules\CrmStats\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverPages(in: base_path('Modules/CrmStats/app/Filament/Pages'), for: 'Modules\CrmStats\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->discoverWidgets(in: base_path('Modules/CrmStats/app/Filament/Widgets'), for: 'Modules\CrmStats\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
