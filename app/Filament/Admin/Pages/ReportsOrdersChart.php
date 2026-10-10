<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Services\OrdersChartReportBuilder;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ReportsOrdersChart extends Page implements HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Orders chart';

    protected static ?string $title = 'Orders chart';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.reports-orders-chart';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
            'metric' => OrdersChartReportBuilder::METRIC_METERS,
            'customer_ids' => [],
            'countries' => [],
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
                        OrdersChartReportBuilder::METRIC_METERS => 'Meters',
                        OrdersChartReportBuilder::METRIC_ROLLS => 'Rolls',
                    ])
                    ->default(OrdersChartReportBuilder::METRIC_METERS)
                    ->native(false)
                    ->live(),

                Select::make('customer_ids')
                    ->label('Customers')
                    ->options(fn (): array => OrdersChartReportBuilder::customerOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),

                Select::make('countries')
                    ->label('Customer country')
                    ->options(fn (): array => OrdersChartReportBuilder::countryOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
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
     *     unit_label: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     collections: list<array{id: int, label: string, total: float, colors: list<array{id: int, label: string, code: string, stock: float}>}>,
     *     colors: int,
     *     orders: int,
     *     grand_total: float
     * }
     */
    public function report(): array
    {
        return OrdersChartReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['metric'] ?? OrdersChartReportBuilder::METRIC_METERS,
            is_array($this->data['customer_ids'] ?? null) ? $this->data['customer_ids'] : [],
            is_array($this->data['countries'] ?? null) ? $this->data['countries'] : [],
        );
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
