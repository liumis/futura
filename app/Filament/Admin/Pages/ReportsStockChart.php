<?php

namespace App\Filament\Admin\Pages;

use App\Models\SystemSetting;
use App\Services\StockChartReportBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ReportsStockChart extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Stock chart';

    protected static ?string $title = 'Stock chart';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 17;

    protected string $view = 'filament.admin.pages.reports-stock-chart';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'metric' => StockChartReportBuilder::METRIC_METERS,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('metric')
                    ->label('Show')
                    ->options([
                        StockChartReportBuilder::METRIC_METERS => 'Meters',
                        StockChartReportBuilder::METRIC_ROLLS => 'Rolls',
                    ])
                    ->default(StockChartReportBuilder::METRIC_METERS)
                    ->native(false)
                    ->live(),
            ])
            ->columns(3);
    }

    public function editLowStockLimitAction(): Action
    {
        return Action::make('editLowStockLimit')
            ->modalHeading('Low stock alert limit')
            ->modalDescription('Products below this meter threshold appear in Reports → Low stock. The dashed horizontal line on this chart uses the same global value. Per-color recommended stock comes from each color’s Recommended stock setting.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn (): array => [
                'low_stock_alert_limit' => SystemSetting::instance()->low_stock_alert_limit,
            ])
            ->form([
                TextInput::make('low_stock_alert_limit')
                    ->label('Low stock alert limit')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('m')
                    ->required(),
            ])
            ->action(function (array $data): void {
                SystemSetting::instance()->update([
                    'low_stock_alert_limit' => max(0, (float) ($data['low_stock_alert_limit'] ?? 0)),
                ]);

                Notification::make()
                    ->title('Low stock limit updated')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     unit_label: string,
     *     collections: list<array{id: int, label: string, total: float, colors: list<array{id: int, label: string, code: string, stock: float}>}>,
     *     colors: int,
     *     grand_total: float,
     *     low_stock_limit: float,
     *     low_stock_limit_label: string
     * }
     */
    public function report(): array
    {
        return StockChartReportBuilder::build($this->data['metric'] ?? StockChartReportBuilder::METRIC_METERS);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
