<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Services\ActivityLogger;
use App\Services\LatePaymentsReportBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReportsLatePayments extends Page implements HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-circle';

    protected static ?string $navigationLabel = 'Late payments';

    protected static ?string $title = 'Late payments';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.admin.pages.reports-late-payments';

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
            'source' => LatePaymentsReportBuilder::SOURCE_ALL,
            'status' => LatePaymentsReportBuilder::STATUS_OUTSTANDING,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('source')
                    ->label('Show')
                    ->options(LatePaymentsReportBuilder::sourceOptions())
                    ->default(LatePaymentsReportBuilder::SOURCE_ALL)
                    ->native(false)
                    ->live(),

                Select::make('status')
                    ->label('Status')
                    ->options(LatePaymentsReportBuilder::statusOptions())
                    ->default(LatePaymentsReportBuilder::STATUS_OUTSTANDING)
                    ->native(false)
                    ->live(),
            ])
            ->columns(5);
    }

    /**
     * @return array{
     *     title: string,
     *     period_label: string,
     *     source: string,
     *     source_label: string,
     *     status: string,
     *     status_label: string,
     *     from: ?string,
     *     until: ?string,
     *     as_of: string,
     *     rows: list<array{
     *         key: string,
     *         source: string,
     *         source_label: string,
     *         record_id: int,
     *         number: string,
     *         date: ?string,
     *         company: string,
     *         due_date: ?string,
     *         amount: float,
     *         late: bool
     *     }>,
     *     grand_total: float,
     *     late_total: float,
     *     count: int,
     *     late_count: int,
     *     invoice_count: int,
     *     contract_count: int
     * }
     */
    public function report(): array
    {
        return LatePaymentsReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['source'] ?? LatePaymentsReportBuilder::SOURCE_ALL,
            $this->data['status'] ?? LatePaymentsReportBuilder::STATUS_OUTSTANDING,
        );
    }

    /**
     * @param  array{source: string, record_id: int}  $row
     */
    public function rowUrl(array $row): ?string
    {
        if ($row['source'] === LatePaymentsReportBuilder::SOURCE_CONTRACT) {
            return PaymentResource::getUrl('edit', ['record' => $row['record_id']]);
        }

        return Invoices::getUrl();
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
        $fileName = 'late-payments-'.now()->format('Ymd-His').'.csv';

        ActivityLogger::logReportDownloaded('Late payments CSV', 'csv', properties: [
            'file_name' => $fileName,
            'count' => $report['count'],
            'late_count' => $report['late_count'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Number', 'Date', 'Company', 'Due date', 'Type', 'Sum', 'Status']);

            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['number'],
                    $row['date'] ?? '',
                    $row['company'],
                    $row['due_date'] ?? '',
                    $row['source_label'],
                    $this->exportNumber($row['amount']),
                    $row['late'] ? 'Late' : '',
                ]);
            }

            fputcsv($out, [
                'Total',
                '',
                '',
                '',
                '',
                $this->exportNumber($report['grand_total']),
                '',
            ]);

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportExcel(): StreamedResponse
    {
        $report = $this->report();
        $fileName = 'late-payments-'.now()->format('Ymd-His').'.xls';

        ActivityLogger::logReportDownloaded('Late payments XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => $report['count'],
            'late_count' => $report['late_count'],
        ]);

        return response()->streamDownload(function () use ($report): void {
            echo '<table border="1"><tr>';
            foreach (['Number', 'Date', 'Company', 'Due date', 'Type', 'Sum', 'Status'] as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($report['rows'] as $row) {
                echo '<tr>';
                echo '<td>'.e($row['number']).'</td>';
                echo '<td>'.e($row['date'] ?? '').'</td>';
                echo '<td>'.e($row['company']).'</td>';
                echo '<td>'.e($row['due_date'] ?? '').'</td>';
                echo '<td>'.e($row['source_label']).'</td>';
                echo '<td>'.e($this->exportNumber($row['amount'])).'</td>';
                echo '<td>'.e($row['late'] ? 'Late' : '').'</td>';
                echo '</tr>';
            }

            echo '<tr>';
            echo '<th>Total</th><th></th><th></th><th></th><th></th>';
            echo '<th>'.e($this->exportNumber($report['grand_total'])).'</th>';
            echo '<th></th>';
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
