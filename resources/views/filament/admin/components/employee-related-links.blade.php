@php
    /** @var \App\Models\Employee $record */
    $groups = \App\Filament\Admin\Support\EmployeeRelatedLinks::groupsFor($record);
@endphp

<nav
    class="ss-employee-related"
    aria-label="Related records for {{ $record->fullName() }}"
>
    <ul class="ss-employee-related__list">
        @foreach ($groups as $group)
            @php
                $viewLink = collect($group['links'])->firstWhere('variant', 'view');
                $addLink = collect($group['links'])->firstWhere('variant', 'add');
            @endphp
            <li class="ss-employee-related__row">
                <span class="ss-employee-related__label">{{ $group['label'] }}</span>

                <span class="ss-employee-related__slot ss-employee-related__slot--view">
                    @if ($viewLink)
                        <a
                            href="{{ $viewLink['url'] }}"
                            class="ss-employee-related__btn ss-employee-related__btn--view"
                            title="{{ $viewLink['title'] }}"
                        >
                            <x-heroicon-m-eye class="ss-employee-related__btn-icon" aria-hidden="true" />
                            <span>{{ $viewLink['label'] }}</span>
                        </a>
                    @endif
                </span>

                <span class="ss-employee-related__slot ss-employee-related__slot--add">
                    @if ($addLink)
                        <a
                            href="{{ $addLink['url'] }}"
                            class="ss-employee-related__btn ss-employee-related__btn--add"
                            title="{{ $addLink['title'] }}"
                        >
                            <x-heroicon-m-plus class="ss-employee-related__btn-icon" aria-hidden="true" />
                            <span>{{ $addLink['label'] }}</span>
                        </a>
                    @endif
                </span>
            </li>
        @endforeach
    </ul>
</nav>
