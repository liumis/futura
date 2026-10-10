@php
    $invoice = $invoice ?? null;
    $groups = $categoryGroups ?? [];
    $kind = $invoice?->financeCategoryKind() ?? 'missing';
    $selected = $invoice?->storedFinanceTypeValue();
@endphp
@if ($invoice)
    <select
        wire:key="{{ $wireKey ?? 'invoice-category-'.$invoice->getKey() }}"
        wire:change="updateFinanceCategory({{ $invoice->getKey() }}, $event.target.value)"
        class="invoice-category-select invoice-category-select--{{ $kind }}"
        title="Change invoice category"
    >
        <option value="" @selected($selected === null)>Missing type</option>
        @foreach ($groups as $group => $options)
            <optgroup label="{{ $group }}">
                @foreach ($options as $value => $label)
                    <option value="{{ $value }}" @selected($selected === $value)>
                        {{ $label }}
                    </option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
@else
    <span class="text-gray-400 dark:text-gray-500" title="Category is stored on the linked invoice">—</span>
@endif
