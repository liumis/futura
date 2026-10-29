<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Models\Contact;
use App\Models\OriginCountry;
use App\Services\InvoicesChartReportBuilder;
use App\Support\ReportDateRange;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ReportsInvoicesChart extends Page implements HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Invoices chart';

    protected static ?string $title = 'Invoices chart';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.reports-invoices-chart';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...ReportDateRange::lastMonthFill(),
            'status' => InvoicesChartReportBuilder::STATUS_ALL,
            'late_days' => InvoicesChartReportBuilder::LATE_ANY,
            'contact_id' => null,
            'country' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('status')
                    ->label('Payment')
                    ->options(InvoicesChartReportBuilder::statusOptions())
                    ->default(InvoicesChartReportBuilder::STATUS_ALL)
                    ->native(false)
                    ->live(),

                Select::make('late_days')
                    ->label('Late days')
                    ->options(InvoicesChartReportBuilder::lateDaysOptions())
                    ->default(InvoicesChartReportBuilder::LATE_ANY)
                    ->native(false)
                    ->live(),

                Select::make('contact_id')
                    ->label('Counterpart')
                    ->options(fn (): array => Contact::query()
                        ->orderBy('company_name')
                        ->pluck('company_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->placeholder('All')
                    ->native(false)
                    ->live(),

                Select::make('country')
                    ->label('Country')
                    ->options(fn (): array => OriginCountry::options())
                    ->searchable()
                    ->preload()
                    ->placeholder('All')
                    ->native(false)
                    ->live(),
            ])
            ->columns(4);
    }

    /**
     * @return array{
     *     title: string,
     *     period_label: string,
     *     status: string,
     *     status_label: string,
     *     late_days: ?int,
     *     late_days_label: string,
     *     contact_id: ?int,
     *     contact_label: string,
     *     country: ?string,
     *     country_label: string,
     *     from: ?string,
     *     until: ?string,
     *     as_of: string,
     *     invoice_count: int,
     *     paid_count: int,
     *     partly_paid_count: int,
     *     unpaid_count: int,
     *     paid_total: float,
     *     partly_paid_total: float,
     *     unpaid_total: float,
     *     outstanding_total: float,
     *     grand_total: float,
     *     days: list<array{
     *         day: string,
     *         label: string,
     *         paid: float,
     *         partly_paid: float,
     *         unpaid: float
     *     }>
     * }
     */
    public function report(): array
    {
        return InvoicesChartReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['status'] ?? InvoicesChartReportBuilder::STATUS_ALL,
            $this->data['late_days'] ?? InvoicesChartReportBuilder::LATE_ANY,
            $this->data['contact_id'] ?? null,
            $this->data['country'] ?? null,
        );
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
