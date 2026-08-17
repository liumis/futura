<x-filament-panels::page>
    @php($notifications = $this->getNotifications())

    <div class="space-y-3">
        @forelse ($notifications as $notification)
            @php($data = (array) $notification->data)
            @php($isUnread = $notification->read_at === null)
            @php($icon = $data['icon'] ?? 'heroicon-o-bell')
            @php($isDanger = ($data['color'] ?? null) === 'danger')
            @php($url = $data['url'] ?? null)

            <div @class([
                'flex items-start gap-3 rounded-xl border p-4',
                'border-primary-300 bg-primary-50 dark:border-primary-500/40 dark:bg-primary-500/10' => $isUnread,
                'border-gray-200 bg-white dark:border-white/10 dark:bg-white/5' => ! $isUnread,
            ])>
                <div @class([
                    'mt-0.5 shrink-0',
                    'text-danger-600 dark:text-danger-400' => $isDanger,
                    'text-primary-600 dark:text-primary-400' => ! $isDanger,
                ])>
                    @svg($icon, 'h-5 w-5')
                </div>

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        {{ $data['title'] ?? $data['message'] ?? \Illuminate\Support\Str::of($notification->type)->afterLast('\\')->headline() }}
                    </p>

                    @if (! empty($data['body']))
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ $data['body'] }}
                        </p>
                    @endif

                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="text-xs text-gray-400 dark:text-gray-500">
                            {{ $notification->created_at?->format('Y-m-d H:i:s') }}
                        </span>

                        @if (! empty($url))
                            <a
                                href="{{ $url }}"
                                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                            >
                                {{ $data['link_label'] ?? 'Open' }}
                            </a>
                        @endif
                    </div>
                </div>

                @if ($isUnread)
                    <span class="mt-1 shrink-0 rounded-full bg-primary-600 px-2 py-0.5 text-xs font-semibold text-white">
                        New
                    </span>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-gray-300 p-10 text-center dark:border-white/10">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-white/5">
                    @svg('heroicon-o-bell-slash', 'h-6 w-6')
                </div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">No notifications</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You're all caught up.</p>
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
