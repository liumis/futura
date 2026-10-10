@php
    $statement = $statement ?? ['lines' => []];
@endphp

<section @class(['fi-report-stat-summary', $class ?? 'mt-6'])>
    <div class="fi-report-stat-summary__header">
        <h3 class="fi-report-stat-summary__title">{{ $title ?? 'Profit (loss) statement' }}</h3>
        @if (filled($description ?? null))
            <p class="fi-report-stat-summary__description">{{ $description }}</p>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                    <th class="px-4 py-3">Line</th>
                    <th class="px-4 py-3 text-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statement['lines'] as $line)
                    <tr @class([
                        'border-b border-gray-100 dark:border-gray-800',
                        'bg-gray-50/80 font-semibold dark:bg-gray-800/40' => in_array($line['style'], ['subtotal', 'total'], true),
                        'bg-primary-50/60 font-semibold dark:bg-primary-950/20' => $line['style'] === 'total',
                    ])>
                        <td class="px-4 py-3">
                            <div class="text-gray-950 dark:text-white">{{ $line['label'] }}</div>
                            <div class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $line['label_lt'] }}</div>
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-950 dark:text-white">
                            {{ \App\Support\Money::format($line['amount']) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
