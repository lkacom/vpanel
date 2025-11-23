<?php


namespace App\Providers\Filament;
use App\Filament\Widgets\VpnMarketInfoWidget;
use Filament\Navigation\NavigationGroup;
use Filament\Widgets\AccountWidget;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Nwidart\Modules\Facades\Module;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {

        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->darkMode()
            ->brandLogo('/images/default-logo.png')
            ->brandLogoHeight('3rem')
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::Red,
                'gray' => Color::Sky,
                'danger' => Color::Red,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font(
                'Vaz',
                url: asset('css/font.css'),
                provider: LocalFontProvider::class,
            )

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([

                VpnMarketInfoWidget::class,


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


        foreach (Module::getOrdered() as $module) {
            if ($module->isEnabled()) {

                $panel->discoverResources(in: $module->getPath() . '/Filament/Resources', for: 'Modules\\' . $module->getName() . '\\Filament\\Resources');
                $panel->discoverPages(in: $module->getPath() . '/Filament/Pages', for: 'Modules\\' . $module->getName() . '\\Filament\\Pages');
                $panel->discoverWidgets(in: $module->getPath() . '/Filament/Widgets', for: 'Modules\\' . $module->getName() . '\\Filament\\Widgets');
            }
        }

        return $panel;
    }
}
