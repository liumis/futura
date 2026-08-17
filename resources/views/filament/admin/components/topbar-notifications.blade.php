@php
    $unreadCount = \App\Filament\Admin\Pages\Notifications::getUnreadCount();
    $notificationsUrl = \App\Filament\Admin\Pages\Notifications::getUrl();
@endphp

<a
    href="{{ $notificationsUrl }}"
    title="Notifications"
    class="fi-topbar-notifications relative flex shrink-0 items-center justify-center rounded-lg p-2 text-gray-400 outline-hidden transition duration-75 hover:bg-gray-50 hover:text-gray-500 focus-visible:bg-gray-50 dark:text-gray-500 dark:hover:bg-white/5 dark:hover:text-gray-400"
>
    <span class="sr-only">Notifications</span>

    @svg('heroicon-o-bell', 'h-6 w-6')

    @if ($unreadCount > 0)
        <span
            class="absolute -right-0.5 -top-0.5 flex min-w-[1.15rem] items-center justify-center rounded-full bg-danger-600 px-1 text-[0.65rem] font-bold leading-4 text-white ring-2 ring-white dark:ring-gray-900"
        >
            {{ $unreadCount > 99 ? '99+' : $unreadCount }}
        </span>
    @endif
</a>
