@php
    /** @var \App\Models\Employee $record */
    $items = \App\Filament\Admin\Support\EmployeeConfirmationSummaries::forEmployee($record);
@endphp

@if ($items === [])
    <span class="ss-employee-confirmations__empty">—</span>
@else
    <ul class="ss-employee-confirmations">
        @foreach ($items as $item)
            <li class="ss-employee-confirmations__item">
                <span
                    class="ss-employee-confirmations__badge ss-employee-confirmations__badge--{{ $item['status'] }}"
                    title="{{ $item['status_label'] }}"
                >{{ $item['status_label'] }}</span>
                <span class="ss-employee-confirmations__meta">
                    <span class="ss-employee-confirmations__name" title="{{ $item['document_name'] }}">
                        {{ $item['type_name'] ?: $item['document_name'] }}
                    </span>
                    <span class="ss-employee-confirmations__detail">{{ $item['detail'] }}</span>
                </span>
            </li>
        @endforeach
    </ul>
@endif
