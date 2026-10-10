@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\BankStatementTransaction> $payments */
    /** @var \App\Models\BusinessTripRequest $trip */
    /** @var int|null $currentTransactionId */
    $tripUrl = \App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource::getUrl('edit', ['record' => $trip]);
    $settlement = $trip->settlementSummary();
@endphp

<div class="space-y-3 text-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/60">
        <div class="min-w-0">
            <div class="font-medium text-gray-900 dark:text-gray-100">
                {{ $trip->employee?->fullName() ?: 'Business trip' }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ optional($trip->date_from)->format('Y-m-d') }}
                @if ($trip->date_to)
                    – {{ optional($trip->date_to)->format('Y-m-d') }}
                @endif
                @if (filled($trip->country_name))
                    · {{ $trip->country_name }}
                @endif
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Expected {{ \App\Support\Money::format($settlement['expected_total']) }}
                @if ($settlement['expenses_amount'] > 0.02)
                    (allowance {{ \App\Support\Money::format($settlement['allowance_amount']) }}
                    + expenses {{ \App\Support\Money::format($settlement['expenses_amount']) }})
                @endif
                · {{ $settlement['label'] }}
                @if ($settlement['left_amount'] > 0.02)
                    · left {{ \App\Support\Money::format($settlement['left_amount']) }}
                @endif
            </div>
        </div>
        <div class="text-right">
            <div class="font-semibold tabular-nums text-gray-900 dark:text-gray-100">
                {{ \App\Support\Money::format($settlement['paid_amount']) }}
            </div>
            <div class="text-xs text-gray-500 dark:text-gray-400">
                {{ $payments->count() }} linked payment{{ $payments->count() === 1 ? '' : 's' }}
            </div>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                <tr>
                    <th class="px-3 py-2 font-semibold">Date</th>
                    <th class="px-3 py-2 font-semibold">Direction</th>
                    <th class="px-3 py-2 font-semibold text-right">Amount</th>
                    <th class="px-3 py-2 font-semibold">Counterpart / description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @forelse ($payments as $payment)
                    @php
                        $isCurrent = $currentTransactionId !== null && (int) $payment->getKey() === (int) $currentTransactionId;
                        $isIncome = (bool) $payment->direction?->isIncome();
                    @endphp
                    <tr @class([
                        'bg-primary-50/70 dark:bg-primary-950/30' => $isCurrent,
                    ])>
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-gray-700 dark:text-gray-200">
                            {{ optional($payment->booking_date)->format('Y-m-d') ?: '—' }}
                            @if ($isCurrent)
                                <span class="ml-1 text-[10px] font-semibold uppercase text-primary-600 dark:text-primary-400">this row</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 text-gray-600 dark:text-gray-300">
                            {{ $isIncome ? 'In' : 'Out' }}
                        </td>
                        <td @class([
                            'whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums',
                            'text-success-700 dark:text-success-400' => $isIncome,
                            'text-rose-700 dark:text-rose-400' => ! $isIncome,
                        ])>
                            {{ \App\Support\Money::format(abs((float) $payment->amount), 2, $payment->currencyCode()) }}
                        </td>
                        <td class="min-w-0 px-3 py-2 text-gray-700 dark:text-gray-200">
                            <div class="truncate" title="{{ $payment->counterpart_name }}">
                                {{ $payment->counterpart_name ?: '—' }}
                            </div>
                            @if (filled($payment->description))
                                <div class="truncate text-xs text-gray-500 dark:text-gray-400" title="{{ $payment->description }}">
                                    {{ $payment->description }}
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-3 py-4 text-center text-gray-500 dark:text-gray-400">
                            No bank payments linked to this trip.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex justify-end">
        <a
            href="{{ $tripUrl }}"
            target="_blank"
            rel="noopener noreferrer"
            class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
        >
            Open business trip
        </a>
    </div>
</div>
