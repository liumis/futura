@php
    /** @var list<array<string, mixed>> $rows */
    $rows = $rows ?? [];
    $sectionKey = (string) ($sectionKey ?? '');
    $heading = (string) ($heading ?? '');
    $productIds = array_values(array_map('intval', $productIds ?? []));
@endphp

<div
    class="fi-cargo-order-collection"
    data-collection-key="{{ $sectionKey }}"
    data-fi-order-section="1"
    data-fi-order-product-ids="{{ e(json_encode($productIds)) }}"
>
    {!! \App\Filament\Admin\Support\ProductCatalogPicker::collectionHeadingHtml($heading) !!}

    @foreach ($rows as $row)
        <div
            class="fi-cargo-order-row fi-sc{{ ! empty($row['is_low_stock']) ? ' fi-cargo-order-row--low-stock' : '' }}{{ ((int) ($row['amount'] ?? 0)) > 0 ? ' fi-cargo-order-row--ordered' : '' }}"
            data-search="{{ e((string) ($row['search'] ?? '')) }}"
            data-stock-meters="{{ e((string) ($row['stock_meters'] ?? '0')) }}"
            data-recommended-meters="{{ e((string) ($row['recommended'] ?? '0')) }}"
            data-color-id="{{ e((string) ($row['color_id'] ?? '0')) }}"
        >
            {!! $row['thumb_html'] !!}
            {!! $row['name_html'] !!}
            {!! $row['code_html'] !!}
            <div class="fi-cargo-order-size">{{ $row['size_label'] }}</div>
            {!! $row['stock_html'] !!}
            {!! $row['upcoming_html'] !!}
            <div class="fi-cargo-order-amount">
                <input
                    type="number"
                    class="fi-cargo-order-amount-input"
                    data-order-amount-input="{{ (int) $row['product_id'] }}"
                    inputmode="numeric"
                    min="0"
                    step="1"
                    max="{{ (int) ($row['max'] ?? 0) }}"
                    value="{{ (int) ($row['amount'] ?? 0) }}"
                    aria-label="Amount"
                />
            </div>
        </div>
    @endforeach
</div>
