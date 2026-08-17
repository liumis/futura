<x-filament-panels::page>
    @php($summary = $this->summary())

    @if ($summary['alert_limit'] <= 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
            Low stock alert limit is not configured. Set it under
            <a href="{{ \App\Filament\Admin\Pages\OtherSettings::getUrl() }}" class="font-medium underline">System → Other</a>.
        </div>
    @endif

    <div class="mt-6">
        {{ $this->table }}
    </div>

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Low stock summary',
        'description' => $summary['alert_limit'] > 0
            ? 'Alert limit: '.\App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['alert_limit']).' m · stock = size × units'
            : 'Configure alert limit in System → Other',
        'footer' => $summary['products'].' product(s) below the alert limit.',
        'stats' => $summary['alert_limit'] <= 0 ? [] : [
            ['label' => 'Products', 'value' => number_format($summary['products']), 'hint' => 'Below alert limit', 'emphasis' => true],
            ['label' => 'Total stock', 'value' => \App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['total_meters']).' m', 'hint' => 'Combined meters'],
            ['label' => 'Alert limit', 'value' => \App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['alert_limit']).' m', 'hint' => 'From system settings'],
        ],
        'class' => 'mt-6',
    ])
</x-filament-panels::page>
