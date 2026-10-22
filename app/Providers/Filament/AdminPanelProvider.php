<?php

namespace App\Providers\Filament;

use App\Enums\AdminModule;
use App\Filament\Admin\Auth\Login;
use App\Filament\Admin\Pages\AbsenceCalendar;
use App\Filament\Admin\Pages\Calendar;
use App\Filament\Admin\Pages\Charts;
use App\Filament\Admin\Pages\ContactsBoard;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\EditProfile;
use App\Filament\Admin\Pages\Kanban;
use App\Filament\Admin\Pages\MonthlyPayment;
use App\Filament\Admin\Pages\NewInvoice;
use App\Filament\Admin\Pages\OrderCharts;
use App\Filament\Admin\Pages\PaymentReports;
use App\Filament\Admin\Pages\ProjectsBoard;
use App\Filament\Admin\Pages\ReportsBankPayments;
use App\Filament\Admin\Pages\ReportsOrdersChart;
use App\Filament\Admin\Pages\ReportsStockChart;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Services\EmailTestMode;
use App\Support\PanelHosts;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Middleware\ShareErrorsFromSession;

use function Filament\Support\generate_icon_html;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->default()
            ->id('admin');

        $panel = PanelHosts::applyAdmin($panel);

        return $panel
            ->homeUrl(fn (): string => Dashboard::getUrl())
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
            ->brandName('FuturaTextiles SS')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('2.35rem')
            ->favicon(asset('images/favicon.png'))
            ->colors([
                'primary' => Color::hex('#23262A'),
                'danger' => Color::hex('#A93226'),
            ])
            ->darkMode(false)
            ->maxContentWidth(Width::Full)
            ->sidebarWidth('15rem')
            ->sidebarFullyCollapsibleOnDesktop()
            // Static theme file (not Vite) so Laravel Cloud's npm build cannot replace it with a broken default bundle.
            ->theme(asset('css/filament-admin-theme.css'))
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.components.darbai-fonts')->render(),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_BEFORE,
                fn (): HtmlString => EmailTestMode::isEnabled()
                    ? new HtmlString(view('filament.admin.components.email-test-mode-banner')->render())
                    : new HtmlString(''),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): HtmlString => new HtmlString(
                    view('filament.admin.components.topbar-notifications')->render()
                ),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">',
                scopes: [Calendar::class, AbsenceCalendar::class],
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>',
                scopes: [Calendar::class, AbsenceCalendar::class],
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>'
                    .'<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.1.0/dist/chartjs-plugin-annotation.min.js"></script>',
                scopes: [Charts::class, OrderCharts::class, ReportsStockChart::class, ReportsOrdersChart::class, ReportsBankPayments::class],
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<script src="'.asset('js/cargo-order-picker.js').'?v=7"></script>'
                    .'<script src="'.asset('js/ss-double-click-cell-edit.js').'?v=2"></script>',
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn (): string => '<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>',
                scopes: [Kanban::class, ContactsBoard::class, ProjectsBoard::class],
            )
            ->navigationGroups([
                NavigationGroup::make('Orders')->collapsed(),
                NavigationGroup::make('Stock')->collapsed(),
                NavigationGroup::make('Finances')->collapsed(),
                NavigationGroup::make('Reports')->collapsed(),
                NavigationGroup::make('Catalog')->collapsed(),
                NavigationGroup::make('Contacts')->collapsed(),
                NavigationGroup::make('Documents')->collapsed(),
                NavigationGroup::make('Tasks')->collapsed(),
                NavigationGroup::make('Employees & contracts')->collapsed(),
                NavigationGroup::make('System')->collapsed(),
                NavigationGroup::make('Financial options')->collapsed(),
            ])
            ->navigationItems([
                NavigationItem::make('New order')
                    ->label('New order')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => OrderResource::getUrl('create'))
                    ->group('Orders')
                    ->sort(99)
                    ->visible(fn (): bool => auth()->user()?->canAccessModule(AdminModule::Orders) ?? false)
                    ->isActiveWhen(fn (): bool => request()->routeIs(OrderResource::getRouteBaseName().'.create'))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
                NavigationItem::make('TASK')
                    ->label('TASK')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => TaskResource::getUrl('create'))
                    ->group('Tasks')
                    ->sort(99)
                    ->visible(fn (): bool => auth()->user()?->canAccessModule(AdminModule::Tasks) ?? false)
                    ->isActiveWhen(fn (): bool => request()->routeIs(TaskResource::getRouteBaseName().'.create'))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
                NavigationItem::make('New invoice')
                    ->label('New invoice')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => NewInvoice::getUrl())
                    ->group('Finances')
                    ->sort(99)
                    ->visible(fn (): bool => auth()->user()?->canAccessModule(AdminModule::Finances) ?? false)
                    ->isActiveWhen(fn (): bool => request()->routeIs(NewInvoice::getRouteName()))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
                NavigationItem::make('New document')
                    ->label('New document')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => DocumentResource::getUrl('create'))
                    ->group('Documents')
                    ->sort(99)
                    ->visible(fn (): bool => auth()->user()?->canAccessModule(AdminModule::Documents) ?? false)
                    ->isActiveWhen(fn (): bool => request()->routeIs(DocumentResource::getRouteBaseName().'.create'))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
                NavigationItem::make('Payments')
                    ->label('Payments')
                    ->icon('heroicon-o-plus-circle')
                    ->url(fn (): string => MonthlyPayment::getUrl())
                    ->group('Employees & contracts')
                    ->sort(99)
                    ->visible(fn (): bool => auth()->user()?->canAccessModule(AdminModule::EmployeesContracts) ?? false)
                    ->isActiveWhen(fn (): bool => request()->routeIs(MonthlyPayment::getRouteName())
                        || request()->routeIs(PaymentReports::getRouteName()))
                    ->extraAttributes([
                        'class' => 'fi-sidebar-new-order',
                    ]),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
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
