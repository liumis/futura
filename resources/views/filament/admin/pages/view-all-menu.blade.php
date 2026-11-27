<x-filament-panels::page>
    <div class="ss-view-all-menu">
        <p class="ss-view-all-menu__hint">
            Items allowed for your account that are hidden from your primary sidebar.
        </p>

        @if (count($overflowItems) === 0)
            <div class="ss-view-all-menu__empty">
                No hidden items. Everything you can access is already in your menu.
            </div>
        @else
            <div class="ss-view-all-menu__grid">
                @foreach ($overflowItems as $item)
                    @php
                        $url = $item['url'] ?? null;
                        $label = $item['label'] ?? 'Item';
                        $group = $item['group'] ?? null;
                    @endphp
                    <div class="ss-view-all-menu__card">
                        <div class="ss-view-all-menu__group">{{ $group ?: 'Other' }}</div>
                        @if (filled($url))
                            <a href="{{ $url }}" class="ss-view-all-menu__link">{{ $label }}</a>
                        @else
                            <div class="ss-view-all-menu__link is-disabled">{{ $label }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="ss-view-all-menu__footer">
            <a href="{{ \App\Filament\Admin\Pages\CustomizeMenu::getUrl() }}" class="ss-view-all-menu__manage">
                Customize menu
            </a>
        </div>
    </div>

    <style>
        .ss-view-all-menu {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .ss-view-all-menu__hint,
        .ss-view-all-menu__empty {
            margin: 0;
            color: #6b6b6b;
            font-size: 0.875rem;
        }

        .ss-view-all-menu__grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr));
            gap: 0.65rem;
        }

        .ss-view-all-menu__card {
            border: 1px solid #c9c9c9;
            padding: 0.75rem 0.85rem;
            background: #fff;
        }

        .ss-view-all-menu__group {
            font-size: 0.68rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #8a8a8a;
            margin-bottom: 0.35rem;
        }

        .ss-view-all-menu__link {
            color: #222;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .ss-view-all-menu__link:hover {
            text-decoration: underline;
        }

        .ss-view-all-menu__link.is-disabled {
            color: #9a9a9a;
        }

        .ss-view-all-menu__footer {
            padding-top: 0.25rem;
        }

        .ss-view-all-menu__manage {
            color: #222;
            font-size: 0.8rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            text-decoration: underline;
        }
    </style>
</x-filament-panels::page>
