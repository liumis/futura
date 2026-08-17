@php
    /** @var array<int, bool> $activeWeekdays */
    $activeWeekdays = $activeWeekdays ?? [];
    $labels = [1 => 'MON', 2 => 'TUE', 3 => 'WED', 4 => 'THU', 5 => 'FRI', 6 => 'SAT', 7 => 'SUN'];
@endphp

<div class="mb-4 flex flex-wrap items-center gap-3" wire:key="weekday-toggles-{{ md5(json_encode($activeWeekdays)) }}">
    <span class="text-sm font-medium text-gray-700 dark:text-gray-200">Toggle weekday:</span>
    <div class="flex flex-wrap gap-2">
        @foreach ($labels as $isoDay => $label)
            @php
                $isOn = (bool) ($activeWeekdays[$isoDay] ?? false);
            @endphp
            <button
                type="button"
                wire:click="toggleScheduleWeekday({{ $isoDay }})"
                aria-pressed="{{ $isOn ? 'true' : 'false' }}"
                title="{{ $isOn ? 'Turn all '.$label.' days off' : 'Turn all '.$label.' days on' }}"
                @class([
                    'inline-flex min-w-[3.25rem] items-center justify-center rounded-lg border px-3 py-1.5 text-xs font-semibold tracking-wide shadow-sm transition',
                    'border-primary-600 bg-primary-600 text-white hover:border-primary-700 hover:bg-primary-700' => $isOn,
                    'border-gray-300 bg-white text-gray-700 hover:bg-gray-50' => ! $isOn,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>
</div>
