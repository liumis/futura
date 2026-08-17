@php
    $lines = \App\Filament\Admin\Resources\Orders\OrderResource::orderLineItemsSummary($order);
    $subtotal = collect($lines)->sum('line_total');
    $shipping = (float) ($order->shipping_cost ?? 0);
    $total = $subtotal + $shipping;
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <div class="grid gap-3 sm:grid-cols-3">
        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Order</p>
            <p>#{{ $order->id }}</p>
        </div>

        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Customer</p>
            <p>{{ $order->user?->company_name ?: trim(implode(' ', array_filter([$order->user?->name, $order->user?->surname]))) ?: '—' }}</p>
        </div>

        <div>
            <p class="font-medium text-gray-500 dark:text-gray-400">Status</p>
            <p>{{ $order->status?->getLabel() ?? '—' }}</p>
        </div>
    </div>

    @if ($lines === [])
        <p class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-6 text-center text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            No items in this order.
        </p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Collection</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Color</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Product code</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Size</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Amount</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Unit price</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Line total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-950">
                    @foreach ($lines as $line)
                        <tr>
                            <td class="px-3 py-2">{{ $line['collection'] }}</td>
                            <td class="px-3 py-2">{{ $line['color'] }}</td>
                            <td class="px-3 py-2">{{ $line['product_code'] }}</td>
                            <td class="px-3 py-2">{{ $line['size'] }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($line['amount']) }}</td>
                            <td class="px-3 py-2 text-right">{{ \App\Support\Money::format($line['unit_price']) }}</td>
                            <td class="px-3 py-2 text-right">{{ \App\Support\Money::format($line['line_total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-900">
                    <tr>
                        <td colspan="6" class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Subtotal</td>
                        <td class="px-3 py-2 text-right font-medium">{{ \App\Support\Money::format($subtotal) }}</td>
                    </tr>
                    <tr>
                        <td colspan="6" class="px-3 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Shipping</td>
                        <td class="px-3 py-2 text-right font-medium">{{ \App\Support\Money::format($shipping) }}</td>
                    </tr>
                    <tr>
                        <td colspan="6" class="px-3 py-2 text-right font-semibold">Total</td>
                        <td class="px-3 py-2 text-right font-semibold">{{ \App\Support\Money::format($total) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
