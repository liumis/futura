@php
    $formatMeters = static function (float $meters): string {
        return rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.');
    };
@endphp

<div class="fi-cargo-cart-expanded">
    @if ($items === [])
        <div class="fi-cargo-cart-expanded__empty">
            Cart is empty. Use Quick order or set quantities in the product list.
        </div>
    @else
        <div class="fi-cargo-cart-expanded__table-wrap">
            <table class="fi-cargo-cart-expanded__table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Description</th>
                        <th class="is-num">Stock (m)</th>
                        <th class="is-num">On order (m)</th>
                        <th class="is-num">Qty (rolls)</th>
                        <th class="is-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $item)
                        <tr wire:key="expanded-cart-{{ $item['product_id'] }}-{{ $item['amount'] }}">
                            <td class="fi-cargo-cart-expanded__code">{{ $item['code'] }}</td>
                            <td class="fi-cargo-cart-expanded__description">{{ $item['description'] ?: $item['label'] }}</td>
                            <td class="is-num {{ $item['is_low_stock'] ? 'is-low-stock' : '' }}">
                                {{ $formatMeters((float) $item['stock_meters']) }}
                                @if ($item['is_low_stock'])
                                    <span class="fi-cargo-cart-expanded__hint" title="Recommended {{ $item['recommended_meters'] }} m">
                                        / {{ $item['recommended_meters'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="is-num fi-cargo-cart-expanded__on-order">
                                @if ((float) $item['on_order_meters'] > 0)
                                    <span class="fi-cargo-cart-expanded__on-order-value">
                                        {{ $formatMeters((float) $item['on_order_meters']) }}
                                    </span>
                                    <span class="fi-cargo-cart-expanded__on-order-label">on order</span>
                                    @if ((int) $item['on_order_rolls'] > 0 && $item['size_meters'])
                                        <span class="fi-cargo-cart-expanded__hint">
                                            ({{ (int) $item['on_order_rolls'] }} rolls)
                                        </span>
                                    @endif
                                @else
                                    0
                                @endif
                            </td>
                            <td class="is-num">
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="fi-cargo-cart-expanded__qty"
                                    value="{{ $item['amount'] }}"
                                    wire:change="updateExpandedCartAmount({{ $item['product_id'] }}, $event.target.value)"
                                >
                            </td>
                            <td class="is-actions">
                                <button
                                    type="button"
                                    class="fi-cargo-cart-expanded__remove"
                                    wire:click="removeExpandedCartItem({{ $item['product_id'] }})"
                                    title="Remove"
                                >×</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
