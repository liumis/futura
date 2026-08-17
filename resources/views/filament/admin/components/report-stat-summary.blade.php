@php
    $title = $title ?? 'Summary';
    $description = $description ?? null;
    $stats = $stats ?? [];
    $footer = $footer ?? null;
    $class = $class ?? 'mt-6';
@endphp

<section @class(['fi-report-stat-summary', $class])>
    <div class="fi-report-stat-summary__header">
        <h3 class="fi-report-stat-summary__title">{{ $title }}</h3>
        @if (filled($description))
            <p class="fi-report-stat-summary__description">{{ $description }}</p>
        @endif
    </div>

    @if ($stats === [])
        <div class="fi-report-stat-summary__empty">
            No data for the selected filters.
        </div>
    @else
        @php
            $gridClass = match (true) {
                count($stats) === 2 => 'fi-report-stat-summary__grid--cols-2',
                count($stats) === 3 => 'fi-report-stat-summary__grid--cols-3',
                count($stats) >= 6 => 'fi-report-stat-summary__grid--cols-6',
                default => 'fi-report-stat-summary__grid--cols-4',
            };
        @endphp
        <dl @class(['fi-report-stat-summary__grid', $gridClass])>
            @foreach ($stats as $stat)
                <div @class([
                    'fi-report-stat-summary__item',
                    'fi-report-stat-summary__item--emphasis' => ($stat['emphasis'] ?? false),
                ])>
                    <dt class="fi-report-stat-summary__label">
                        {{ $stat['label'] }}
                    </dt>
                    <dd @class([
                        'fi-report-stat-summary__value',
                        'fi-report-stat-summary__value--emphasis' => ($stat['emphasis'] ?? false),
                    ])>
                        {{ $stat['value'] }}
                    </dd>
                    @if (filled($stat['hint'] ?? null))
                        <dd class="fi-report-stat-summary__hint">{{ $stat['hint'] }}</dd>
                    @endif
                </div>
            @endforeach
        </dl>
    @endif

    @if (filled($footer))
        <div class="fi-report-stat-summary__footer">
            {{ $footer }}
        </div>
    @endif
</section>
