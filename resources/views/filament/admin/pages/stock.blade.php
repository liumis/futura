<x-filament-panels::page>
    @php($summary = $this->stockSummary())

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:max-w-xl">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Products</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($summary['products']) }}</div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-900">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total units in stock</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($summary['units']) }}</div>
        </div>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
