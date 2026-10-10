<x-filament-panels::page>
    @php($summary = $this->summary())

    @include('filament.admin.components.report-stat-summary', [
        'title' => 'Low stock summary',
        'description' => 'Artificial leather colors below their own recommended stock. Catalog products are excluded.',
        'footer' => $summary['colors'].' color(s) below recommended stock.',
        'stats' => [
            ['label' => 'Colors', 'value' => number_format($summary['colors']), 'hint' => 'Below recommended', 'emphasis' => true],
            ['label' => 'Total stock', 'value' => \App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['total_meters']).' m', 'hint' => 'Combined meters'],
            ['label' => 'Recommended', 'value' => \App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['total_recommended']).' m', 'hint' => 'Sum of targets'],
            ['label' => 'Total deficit', 'value' => \App\Filament\Admin\Pages\ReportsLowStock::formatMeters($summary['total_deficit']).' m', 'hint' => 'Recommended − stock'],
        ],
        'class' => 'mb-6',
    ])

    {{ $this->table }}
</x-filament-panels::page>
