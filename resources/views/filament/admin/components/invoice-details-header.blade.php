@php
    use App\Support\Money;
@endphp

<div class="mb-4 space-y-4 text-sm">
    <div class="grid gap-4 md:grid-cols-2">
        <div class="space-y-1 text-gray-700 dark:text-gray-200">
            <div class="text-base font-semibold text-gray-950 dark:text-white">
                {{ $issuer['name'] ?: '—' }}
            </div>
            @if (filled($issuer['companyId']))
                <div>Company code: {{ $issuer['companyId'] }}</div>
            @endif
            @if (filled($issuer['vat']))
                <div>VAT code: {{ $issuer['vat'] }}</div>
            @endif
            @if (filled($issuer['address']))
                <div>{{ $issuer['address'] }}</div>
            @endif
            @if (filled($issuer['email']))
                <div>{{ $issuer['email'] }}</div>
            @endif
            @if (filled($issuer['phone']))
                <div>{{ $issuer['phone'] }}</div>
            @endif
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 space-y-1 text-gray-700 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            <div class="text-base font-semibold text-gray-950 dark:text-white">
                {{ $customer['name'] ?: '—' }}
            </div>
            @if (filled($customer['companyName']) && $customer['companyName'] !== $customer['name'])
                <div>{{ $customer['companyName'] }}</div>
            @endif
            @if (filled($customer['companyId']))
                <div>Company code: {{ $customer['companyId'] }}</div>
            @endif
            @if (filled($customer['vat']))
                <div>VAT code: {{ $customer['vat'] }}</div>
            @endif
            @if (filled($customer['address']))
                <div>{{ $customer['address'] }}</div>
            @endif
            @if (filled($customer['email']))
                <div>{{ $customer['email'] }}</div>
            @endif
            @if (filled($customer['phone']))
                <div>{{ $customer['phone'] }}</div>
            @endif
        </div>
    </div>

    <div style="display:flex;flex-wrap:wrap;gap:0.75rem 2rem;margin:0.25rem 0 0.5rem;">
        <div>
            <span class="text-gray-500 dark:text-gray-400">Invoice no:</span>
            <strong style="margin-left:0.35rem;">{{ $invoiceNumber }}</strong>
        </div>
        <div>
            <span class="text-gray-500 dark:text-gray-400">Invoice date:</span>
            <strong style="margin-left:0.35rem;">{{ $invoiceDate }}</strong>
        </div>
        @if (filled($orderNumber))
            <div>
                <span class="text-gray-500 dark:text-gray-400">Order:</span>
                <strong style="margin-left:0.35rem;">#{{ $orderNumber }}</strong>
            </div>
        @endif
    </div>

    @if (count($lines) > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr class="text-left text-gray-600 dark:text-gray-300">
                        <th class="px-3 py-2 font-medium">Collection</th>
                        <th class="px-3 py-2 font-medium">Color</th>
                        <th class="px-3 py-2 font-medium">Product code</th>
                        <th class="px-3 py-2 font-medium">Size</th>
                        <th class="px-3 py-2 font-medium text-right">Qty</th>
                        <th class="px-3 py-2 font-medium text-right">Unit price</th>
                        <th class="px-3 py-2 font-medium text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lines as $line)
                        <tr class="border-t border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $line['collection'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $line['color'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $line['product_code'] }}</td>
                            <td class="px-3 py-2 text-gray-700 dark:text-gray-200">{{ $line['size'] }}</td>
                            <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ $line['amount'] }}</td>
                            <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ Money::format($line['unit_price']) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700 dark:text-gray-200">{{ Money::format($line['line_total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="flex justify-end">
        <div class="w-full max-w-xs overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <tbody>
                    @if ($hasOrderTotals)
                        <tr class="border-b border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">Subtotal</td>
                            <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ Money::format($subtotal) }}</td>
                        </tr>
                        <tr class="border-b border-gray-100 dark:border-gray-700">
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-300">Delivery</td>
                            <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ Money::format($shipping) }}</td>
                        </tr>
                    @endif
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">Sum without VAT</td>
                        <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ Money::format($sumWithoutVat) }}</td>
                    </tr>
                    <tr class="border-b border-gray-100 dark:border-gray-700">
                        <td class="px-3 py-2 text-gray-600 dark:text-gray-300">VAT</td>
                        <td class="px-3 py-2 text-right font-medium text-gray-900 dark:text-gray-100">{{ Money::format($vat) }}</td>
                    </tr>
                    <tr class="bg-gray-50 dark:bg-gray-800">
                        <td class="px-3 py-2 font-semibold text-gray-900 dark:text-white">Total to pay</td>
                        <td class="px-3 py-2 text-right font-semibold text-gray-900 dark:text-white">{{ Money::format($sumIncVat) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
