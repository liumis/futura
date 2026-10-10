<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Services\ActivityLogger;
use App\Services\CustomerReportBuilder;
use App\Services\SalesReportBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReportsCustomer extends Page implements HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Customer sales matrix';

    protected static ?string $title = 'Customer sales matrix';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.reports-customer';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->subMonths(11)->startOfMonth()->toDateString(),
            'until' => now()->endOfMonth()->toDateString(),
            'month' => null,
            'metric' => SalesReportBuilder::METRIC_SUM,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('metric')
                    ->label('Show')
                    ->options([
                        SalesReportBuilder::METRIC_SUM => 'Total sum',
                        SalesReportBuilder::METRIC_METERS => 'Meters',
                    ])
                    ->default(SalesReportBuilder::METRIC_SUM)
                    ->native(false)
                    ->live(),
            ])
            ->columns(4);
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     months: list<array{key: string, label: string}>,
     *     rows: list<array{customer_id: int, company: string, code: string, values: array<string, float>, total: float}>,
     *     column_totals: array<string, float>,
     *     grand_total: float,
     *     customers: int,
     *     orders: int
     * }
     */
    public function report(): array
    {
        return CustomerReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['metric'] ?? SalesReportBuilder::METRIC_SUM,
        );
    }

    public function exportCsvAction(): Action
    {
        return Action::make('exportCsv')
            ->label('Export CSV')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(fn (): StreamedResponse => $this->exportCsv());
    }

    public function exportExcelAction(): Action
    {
        return Action::make('exportExcel')
            ->label('Export Excel')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->action(fn (): StreamedResponse => $this->exportExcel());
    }

    private function exportCsv(): StreamedResponse
    {
        $report = $this->report();
        $fileName = 'customer-report-'.now()->format('Ymd-His').'.csv';

        ActivityLogger::logReportDownloaded('Customer report CSV', 'csv', properties: [
            'file_name' => $fileName,
            'metric' => $report['metric'],
            'customers' => $report['customers'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $headers = array_merge(
                ['Customer'],
                array_column($report['months'], 'label'),
                ['Total'],
            );
            fputcsv($out, $headers);

            foreach ($report['rows'] as $row) {
                $line = [$row['company']];
                foreach ($report['months'] as $month) {
                    $line[] = $this->exportNumber($row['values'][$month['key']] ?? 0);
                }
                $line[] = $this->exportNumber($row['total']);
                fputcsv($out, $line);
            }

            $totals = ['Total'];
            foreach ($report['months'] as $month) {
                $totals[] = $this->exportNumber($report['column_totals'][$month['key']] ?? 0);
            }
            $totals[] = $this->exportNumber($report['grand_total']);
            fputcsv($out, $totals);

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportExcel(): StreamedResponse
    {
        $report = $this->report();
        $fileName = 'customer-report-'.now()->format('Ymd-His').'.xls';

        ActivityLogger::logReportDownloaded('Customer report XLS', 'xls', properties: [
            'file_name' => $fileName,
            'metric' => $report['metric'],
            'customers' => $report['customers'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            echo '<table border="1"><tr>';
            foreach (array_merge(
                ['Customer'],
                array_column($report['months'], 'label'),
                ['Total'],
            ) as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($report['rows'] as $row) {
                echo '<tr>';
                echo '<td>'.e($row['company']).'</td>';
                foreach ($report['months'] as $month) {
                    echo '<td>'.e($this->exportNumber($row['values'][$month['key']] ?? 0)).'</td>';
                }
                echo '<td>'.e($this->exportNumber($row['total'])).'</td>';
                echo '</tr>';
            }

            echo '<tr><th>Total</th>';
            foreach ($report['months'] as $month) {
                echo '<th>'.e($this->exportNumber($report['column_totals'][$month['key']] ?? 0)).'</th>';
            }
            echo '<th>'.e($this->exportNumber($report['grand_total'])).'</th>';
            echo '</tr></table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    private function exportNumber(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
