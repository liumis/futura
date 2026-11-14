<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\Collection;
use App\Models\Supplier;
use App\Services\ForecastReportBuilder;
use App\Services\OrdersChartReportBuilder;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class ReportsForecast extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Forecast';

    protected static ?string $title = 'Stock forecast';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 18;

    protected string $view = 'filament.admin.pages.reports-forecast';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'lookback_days' => ForecastReportBuilder::DEFAULT_LOOKBACK_DAYS,
            'horizon_days' => ForecastReportBuilder::DEFAULT_HORIZON_DAYS,
            'collection_ids' => [],
            'customer_ids' => [],
            'only_needs_order' => true,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('lookback_days')
                    ->label('Lookback')
                    ->options([
                        30 => '30 days',
                        60 => '60 days',
                        90 => '90 days',
                        180 => '180 days',
                    ])
                    ->default(ForecastReportBuilder::DEFAULT_LOOKBACK_DAYS)
                    ->native(false)
                    ->live(),

                Select::make('horizon_days')
                    ->label('Horizon')
                    ->options([
                        30 => '30 days',
                        60 => '60 days',
                        90 => '90 days',
                        120 => '120 days',
                    ])
                    ->default(ForecastReportBuilder::DEFAULT_HORIZON_DAYS)
                    ->native(false)
                    ->live(),

                Select::make('collection_ids')
                    ->label('Collections')
                    ->options(fn (): array => Collection::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->multiple()
                    ->searchable()
                    ->preload()
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

                Toggle::make('only_needs_order')
                    ->label('Only colors that need ordering')
                    ->default(true)
                    ->live(),
            ])
            ->columns(5);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return ForecastReportBuilder::build(
            (int) ($this->data['lookback_days'] ?? ForecastReportBuilder::DEFAULT_LOOKBACK_DAYS),
            (int) ($this->data['horizon_days'] ?? ForecastReportBuilder::DEFAULT_HORIZON_DAYS),
            is_array($this->data['collection_ids'] ?? null) ? $this->data['collection_ids'] : [],
            is_array($this->data['customer_ids'] ?? null) ? $this->data['customer_ids'] : [],
            (bool) ($this->data['only_needs_order'] ?? true),
        );
    }

    public function createWarehouseOrderAction(): Action
    {
        return Action::make('createWarehouseOrder')
            ->label('Create warehouse order')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->modalHeading('Create warehouse order from forecast')
            ->modalDescription('Opens a new warehouse order. Prefill uses low-stock defaults for the selected supplier; use forecast buy quantities as a checklist.')
            ->modalSubmitActionLabel('Continue')
            ->modalWidth('md')
            ->fillForm(function (): array {
                $defaultId = CargoResource::defaultSupplierId();
                $options = Supplier::query()->orderBy('name')->pluck('name', 'id')->all();

                if ($defaultId !== null && isset($options[(int) $defaultId])) {
                    return ['supplier_id' => (int) $defaultId];
                }

                $firstId = array_key_first($options);

                return ['supplier_id' => $firstId !== null ? (int) $firstId : null];
            })
            ->form([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (array $data) {
                $supplierId = (int) ($data['supplier_id'] ?? 0);
                if ($supplierId <= 0) {
                    Notification::make()
                        ->title('Select a supplier')
                        ->warning()
                        ->send();

                    return;
                }

                return redirect()->to(
                    CargoResource::getUrl('create').'?'.http_build_query([
                        'supplier_id' => $supplierId,
                        'auto_order' => 1,
                    ])
                );
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->createWarehouseOrderAction(),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
