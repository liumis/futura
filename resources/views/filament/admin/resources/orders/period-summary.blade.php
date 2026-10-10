@php($summary = $this->periodSummary())

@include('filament.admin.components.report-stat-summary', [
    'title' => 'Period summary',
    'description' => $summary['description'],
    'footer' => $summary['footer'],
    'stats' => $summary['stats'],
    'class' => 'mt-6',
])
