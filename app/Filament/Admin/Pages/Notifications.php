<?php

namespace App\Filament\Admin\Pages;

use App\Services\LowStockNotifier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

class Notifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected string $view = 'filament.admin.pages.notifications';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public static function getUnreadCount(): int
    {
        return (int) (auth()->user()?->unreadNotifications()->count() ?? 0);
    }

    /**
     * @return Collection<int, DatabaseNotification>
     */
    public function getNotifications(): Collection
    {
        $user = auth()->user();

        if ($user === null) {
            return collect();
        }

        return $user->notifications()->latest()->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkLowStock')
                ->label('Check low stock now')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->visible(fn (): bool => auth()->user()?->hasRole('admin') ?? false)
                ->action(function (): void {
                    $notified = LowStockNotifier::run();

                    Notification::make()
                        ->title($notified > 0
                            ? 'Low stock notifications sent to '.$notified.' user(s)'
                            : 'No new low stock notifications')
                        ->body($notified > 0
                            ? null
                            : 'Either stock is above the limit, no users opted in, or recipients already have an unread alert.')
                        ->success()
                        ->send();
                }),

            Action::make('markAllAsRead')
                ->label('Mark all as read')
                ->color('gray')
                ->visible(fn (): bool => static::getUnreadCount() > 0)
                ->action(function (): void {
                    auth()->user()?->unreadNotifications->markAsRead();
                }),
        ];
    }
}
