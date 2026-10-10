@php
    $summary = $summary ?? ['groups' => [], 'sales_base' => 0, 'purchase_base' => 0, 'output_vat' => 0, 'input_vat' => 0, 'payable' => 0];
@endphp

<section @class(['fi-report-stat-summary', $class ?? 'mt-6'])>
    <div class="fi-report-stat-summary__header">
        <h3 class="fi-report-stat-summary__title">{{ $title ?? 'By PVM rate' }}</h3>
        @if (filled($description ?? null))
            <p class="fi-report-stat-summary__description">{{ $description }}</p>
        @endif
    </div>

    @if (($summary['groups'] ?? []) === [])
        <div class="fi-report-stat-summary__empty">
            No PVM amounts for the selected filters.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        <th class="px-4 py-3">Rate</th>
                        <th class="px-4 py-3 text-right">Sales net</th>
                        <th class="px-4 py-3 text-right">Output VAT</th>
                        <th class="px-4 py-3 text-right">Purchase net</th>
                        <th class="px-4 py-3 text-right">Input VAT</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['groups'] as $group)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="px-4 py-3 text-gray-950 dark:text-white">{{ $group['rate_label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($group['sales_base']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($group['output_vat']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($group['purchase_base']) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($group['input_vat']) }}</td>
                        </tr>
                    @endforeach
                    <tr class="bg-gray-50/80 font-semibold dark:bg-gray-800/40">
                        <td class="px-4 py-3 text-right">Total</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($summary['sales_base']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($summary['output_vat']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($summary['purchase_base']) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ \App\Support\Money::format($summary['input_vat']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
</section>
