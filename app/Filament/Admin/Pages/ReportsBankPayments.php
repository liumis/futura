<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Services\BankPaymentsChartReportBuilder;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ReportsBankPayments extends Page implements HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Bank payment report';

    protected static ?string $title = 'Bank payment report';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 14;

    protected string $view = 'filament.admin.pages.reports-bank-payments';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
            'metric' => BankPaymentsChartReportBuilder::METRIC_SUM,
            'direction' => BankPaymentsChartReportBuilder::DIRECTION_ALL,
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
                        BankPaymentsChartReportBuilder::METRIC_SUM => 'Sum',
                        BankPaymentsChartReportBuilder::METRIC_COUNT => 'Count',
                    ])
                    ->default(BankPaymentsChartReportBuilder::METRIC_SUM)
                    ->native(false)
                    ->live(),

                Select::make('direction')
                    ->label('Direction')
                    ->options([
                        BankPaymentsChartReportBuilder::DIRECTION_ALL => 'All',
                        BankPaymentsChartReportBuilder::DIRECTION_INCOME => 'Income',
                        BankPaymentsChartReportBuilder::DIRECTION_OUTCOME => 'Outcome',
                    ])
                    ->default(BankPaymentsChartReportBuilder::DIRECTION_ALL)
                    ->native(false)
                    ->live(),
            ])
            ->columns(5);
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     direction: string,
     *     direction_label: string,
     *     title: string,
     *     unit_label: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     days: list<array{day: string, label: string, value: float}>,
     *     transactions: int,
     *     income_total: float,
     *     outcome_total: float,
     *     net_total: float,
     *     grand_total: float
     * }
     */
    public function report(): array
    {
        return BankPaymentsChartReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['metric'] ?? BankPaymentsChartReportBuilder::METRIC_SUM,
            $this->data['direction'] ?? BankPaymentsChartReportBuilder::DIRECTION_ALL,
        );
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
