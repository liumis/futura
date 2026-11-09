@php
    use App\Support\Money;

    $currency = $currency ?? Money::currency();
@endphp

<div class="invoice-edit-preview">
    <div class="invoice-edit-preview__parties">
        <div class="invoice-edit-preview__party">
            <p class="invoice-edit-preview__eyebrow">Issuer</p>
            <h3 class="invoice-edit-preview__name">{{ $issuer['name'] ?: '—' }}</h3>
            <div class="invoice-edit-preview__meta">
                @if (filled($issuer['companyId']))
                    <span>Company {{ $issuer['companyId'] }}</span>
                @endif
                @if (filled($issuer['vat']))
                    <span>VAT {{ $issuer['vat'] }}</span>
                @endif
                @if (filled($issuer['address']))
                    <span>{{ $issuer['address'] }}</span>
                @endif
                @if (filled($issuer['email']))
                    <span>{{ $issuer['email'] }}</span>
                @endif
                @if (filled($issuer['phone']))
                    <span>{{ $issuer['phone'] }}</span>
                @endif
            </div>
        </div>

        <div class="invoice-edit-preview__party invoice-edit-preview__party--accent">
            <p class="invoice-edit-preview__eyebrow">Customer</p>
            <h3 class="invoice-edit-preview__name">{{ $customer['name'] ?: '—' }}</h3>
            <div class="invoice-edit-preview__meta">
                @if (filled($customer['companyName']) && $customer['companyName'] !== $customer['name'])
                    <span>{{ $customer['companyName'] }}</span>
                @endif
                @if (filled($customer['companyId']))
                    <span>Company {{ $customer['companyId'] }}</span>
                @endif
                @if (filled($customer['vat']))
                    <span>VAT {{ $customer['vat'] }}</span>
                @endif
                @if (filled($customer['address']))
                    <span>{{ $customer['address'] }}</span>
                @endif
                @if (filled($customer['email']))
                    <span>{{ $customer['email'] }}</span>
                @endif
                @if (filled($customer['phone']))
                    <span>{{ $customer['phone'] }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="invoice-edit-preview__facts">
        <div class="invoice-edit-preview__fact">
            <span class="invoice-edit-preview__eyebrow">Invoice no</span>
            <strong>{{ $invoiceNumber ?: '—' }}</strong>
        </div>
        <div class="invoice-edit-preview__fact">
            <span class="invoice-edit-preview__eyebrow">Date</span>
            <strong>{{ $invoiceDate ?: '—' }}</strong>
        </div>
        <div class="invoice-edit-preview__fact">
            <span class="invoice-edit-preview__eyebrow">Currency</span>
            <strong>{{ $currency }}</strong>
        </div>
        @if (filled($orderNumber))
            <div class="invoice-edit-preview__fact">
                <span class="invoice-edit-preview__eyebrow">Order</span>
                <strong>#{{ $orderNumber }}</strong>
            </div>
        @endif
        <div class="invoice-edit-preview__fact invoice-edit-preview__fact--total">
            <span class="invoice-edit-preview__eyebrow">Total</span>
            <strong>{{ Money::format($sumIncVat, 2, $currency) }}</strong>
        </div>
    </div>

    @if (count($lines) > 0)
        <div class="invoice-edit-preview__lines">
            <table>
                <thead>
                    <tr>
                        <th>Collection</th>
                        <th>Color</th>
                        <th>Product code</th>
                        <th>Size</th>
                        <th class="is-num">Qty</th>
                        <th class="is-num">Unit</th>
                        <th class="is-num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line['collection'] }}</td>
                            <td>{{ $line['color'] }}</td>
                            <td>{{ $line['product_code'] }}</td>
                            <td>{{ $line['size'] }}</td>
                            <td class="is-num">{{ $line['amount'] }}</td>
                            <td class="is-num">{{ Money::format($line['unit_price'], 2, $currency) }}</td>
                            <td class="is-num">{{ Money::format($line['line_total'], 2, $currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="invoice-edit-preview__totals">
        <table>
            <tbody>
                @if ($hasOrderTotals)
                    <tr>
                        <td>Subtotal</td>
                        <td class="is-num">{{ Money::format($subtotal, 2, $currency) }}</td>
                    </tr>
                    <tr>
                        <td>Delivery</td>
                        <td class="is-num">{{ Money::format($shipping, 2, $currency) }}</td>
                    </tr>
                @endif
                <tr>
                    <td>Sum without VAT</td>
                    <td class="is-num">{{ Money::format($sumWithoutVat, 2, $currency) }}</td>
                </tr>
                <tr>
                    <td>VAT</td>
                    <td class="is-num">{{ Money::format($vat, 2, $currency) }}</td>
                </tr>
                <tr class="is-grand">
                    <td>Total to pay</td>
                    <td class="is-num">{{ Money::format($sumIncVat, 2, $currency) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
