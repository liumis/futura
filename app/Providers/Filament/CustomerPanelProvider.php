<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\EditProfile;
use App\Filament\Customer\Auth\Login;
use App\Filament\Customer\Resources\Orders\OrderResource;
use App\Support\PanelHosts;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\generate_icon_html;

class CustomerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('customer');

        $panel = PanelHosts::applyCustomer($panel);

        return $panel
            ->homeUrl(fn (): string => OrderResource::getUrl('index'))
            ->profile(EditProfile::class)
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action
                    ->label(function (): HtmlString {
                        $user = auth()->user();
                        $email = e($user?->email ?? '');
                        $editIcon = generate_icon_html(
                            Heroicon::PencilSquare,
                            null,
                            (new ComponentAttributeBag)->class(['shrink-0', 'text-gray-500', 'dark:text-gray-400']),
                            IconSize::Small,
                        );

                        return new HtmlString(
                            '<div class="flex min-w-0 items-center justify-between gap-2 text-start">'
                            .'<span class="min-w-0 truncate text-sm text-gray-700 dark:text-gray-200">'
                            .$email
                            .'</span>'
                            .($editIcon?->toHtml() ?? '')
                            .'</div>'
                        );
                    })
                    ->icon(null)
                    ->extraAttributes([
                        'class' => 'fi-user-menu-profile',
                    ]),

                'logout' => fn (Action $action): Action => $action
                    ->label(__('filament-panels::layout.actions.logout.label'))
                    ->icon(null),
            ])
            ->passwordReset()
            ->login(Login::class)
            ->brandName('FuturaTextiles')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.85rem')
            ->favicon(asset('images/favicon.png'))
            ->colors([
                'primary' => Color::hex('#222222'),
                'warning' => Color::hex('#EB8027'),
                'danger' => Color::hex('#222222'),
            ])
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            ->theme(asset('css/filament-admin-theme.css'))
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.components.darbai-fonts')->render()
                    .'<script src="'.asset('js/cargo-order-picker.js').'?v=7"></script>',
            )
            ->navigationGroups([
                'Orders',
            ])
            ->navigationItems([
                NavigationItem::make('New order')
                    ->label('New order')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => OrderResource::getUrl('create'))
                    ->group('Orders')
                    ->sort(99)
                    ->isActiveWhen(fn (): bool => request()->routeIs(OrderResource::getRouteBaseName().'.create'))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
            ])
            ->discoverResources(in: app_path('Filament/Customer/Resources'), for: 'App\\Filament\\Customer\\Resources')
            ->discoverPages(in: app_path('Filament/Customer/Pages'), for: 'App\\Filament\\Customer\\Pages')
            ->discoverWidgets(in: app_path('Filament/Customer/Widgets'), for: 'App\\Filament\\Customer\\Widgets')
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
