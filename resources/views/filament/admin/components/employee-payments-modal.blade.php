@php
    /** @var list<array{type: string, type_label: string, date: string, period: ?string, amount: float, amount_label: string, status: string, detail: ?string, url: ?string}> $rows */
    $total = collect($rows)->sum('amount');
@endphp

<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    @if ($rows === [])
        <p class="text-gray-500 dark:text-gray-400">No payments found for this employee.</p>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800/60">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Type</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Date</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Period / details</th>
                        <th class="px-3 py-2 text-right text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Amount</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                        <th class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap font-medium">{{ $row['type_label'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['date'] }}</td>
                            <td class="px-3 py-2">
                                <div>{{ $row['period'] ?: '—' }}</div>
                                @if (filled($row['detail']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['detail'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap tabular-nums font-medium">{{ $row['amount_label'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $row['status'] }}</td>
                            <td class="px-3 py-2 whitespace-nowrap text-right">
                                @if (filled($row['url']))
                                    <a
                                        href="{{ $row['url'] }}"
                                        class="text-primary-600 hover:underline dark:text-primary-400"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Open
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50 dark:bg-gray-800/60">
                    <tr>
                        <td colspan="3" class="px-3 py-2 text-right font-medium">Total</td>
                        <td class="px-3 py-2 text-right tabular-nums font-semibold">{{ \App\Support\Money::format($total) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
