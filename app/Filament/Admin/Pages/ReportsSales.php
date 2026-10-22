<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SalesReportBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReportsSales extends Page implements HasForms
{
    use HasDoubleClickCellEdit;
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Sales matrix';

    protected static ?string $title = 'Sales matrix';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.reports-sales';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
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
     *     collections: list<array{key: string, label: string}>,
     *     rows: list<array{customer_id: int, company: string, code: string, values: array<string, float>, total: float}>,
     *     column_totals: array<string, float>,
     *     grand_total: float,
     *     customers: int,
     *     orders: int
     * }
     */
    public function report(): array
    {
        return SalesReportBuilder::build(
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
        $fileName = 'sales-report-'.now()->format('Ymd-His').'.csv';

        ActivityLogger::logReportDownloaded('Sales report CSV', 'csv', properties: [
            'file_name' => $fileName,
            'metric' => $report['metric'],
            'customers' => $report['customers'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $headers = array_merge(
                ['Company', 'Customer code'],
                array_column($report['collections'], 'label'),
                ['Total'],
            );
            fputcsv($out, $headers);

            foreach ($report['rows'] as $row) {
                $line = [$row['company'], $row['code']];
                foreach ($report['collections'] as $collection) {
                    $line[] = $this->exportNumber($row['values'][$collection['key']] ?? 0);
                }
                $line[] = $this->exportNumber($row['total']);
                fputcsv($out, $line);
            }

            $totals = ['Total', ''];
            foreach ($report['collections'] as $collection) {
                $totals[] = $this->exportNumber($report['column_totals'][$collection['key']] ?? 0);
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
        $fileName = 'sales-report-'.now()->format('Ymd-His').'.xls';

        ActivityLogger::logReportDownloaded('Sales report XLS', 'xls', properties: [
            'file_name' => $fileName,
            'metric' => $report['metric'],
            'customers' => $report['customers'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            echo '<table border="1"><tr>';
            foreach (array_merge(
                ['Company', 'Customer code'],
                array_column($report['collections'], 'label'),
                ['Total'],
            ) as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($report['rows'] as $row) {
                echo '<tr>';
                echo '<td>'.e($row['company']).'</td>';
                echo '<td>'.e($row['code']).'</td>';
                foreach ($report['collections'] as $collection) {
                    echo '<td>'.e($this->exportNumber($row['values'][$collection['key']] ?? 0)).'</td>';
                }
                echo '<td>'.e($this->exportNumber($row['total'])).'</td>';
                echo '</tr>';
            }

            echo '<tr>';
            echo '<th>Total</th><th></th>';
            foreach ($report['collections'] as $collection) {
                echo '<th>'.e($this->exportNumber($report['column_totals'][$collection['key']] ?? 0)).'</th>';
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

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return User::query()->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'company_name' => [
                'label' => 'Company name',
                'description' => 'Updates this customer’s company name everywhere it appears.',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (User $record): ?string => $record->company_name,
                'save' => function (User $record, mixed $value): void {
                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $record->update(['company_name' => $name]);
                },
            ],
            'company_code' => [
                'label' => 'Customer code',
                'description' => 'Stored as the customer’s company code. Leave blank to fall back to an auto-derived code.',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Customer code')
                    ->maxLength(255),
                'fill' => fn (User $record): ?string => $record->company_code,
                'save' => function (User $record, mixed $value): void {
                    $code = $this->doubleClickValidate($value, ['nullable', 'string', 'max:255']);
                    $record->update([
                        'company_code' => filled($code) ? trim((string) $code) : null,
                    ]);
                },
            ],
        ];
    }
}
