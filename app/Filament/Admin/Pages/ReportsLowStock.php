<?php

namespace App\Filament\Admin\Pages;

use App\Models\Product;
use App\Models\SystemSetting;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReportsLowStock extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Low stock';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Low stock';

    protected string $view = 'filament.admin.pages.reports-low-stock';

    public function table(Table $table): Table
    {
        $limit = $this->alertLimit();

        return $table
            ->query(fn (): Builder => $this->productsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('color.collection.name')
                    ->label('Collection')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('color.color_name')
                    ->label('Color')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('color.color_code')
                    ->label('Color code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product_code')
                    ->label('Product code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Size (m)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Units')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_meters')
                    ->label('Stock (m)')
                    ->getStateUsing(fn (Product $record): float => $record->stockMeters())
                    ->formatStateUsing(fn (float $state): string => self::formatMeters($state))
                    ->alignEnd()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(Product::stockMetersSqlExpression().' '.$direction);
                    })
                    ->color('danger')
                    ->weight('semibold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collection')
                    ->label('Collection')
                    ->relationship('color.collection', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->paginated([25, 50, 100])
            ->emptyStateHeading($limit > 0 ? 'No low stock products' : 'Alert limit not configured')
            ->emptyStateDescription($limit > 0
                ? 'All products are at or above the configured alert limit.'
                : 'Set the low stock alert limit under System → Other.');
    }

    public function alertLimit(): float
    {
        return SystemSetting::instance()->lowStockAlertLimit();
    }

    private function productsQuery(): Builder
    {
        return Product::query()
            ->with(['color.collection'])
            ->belowStockMeterLimit($this->alertLimit())
            ->orderByRaw(Product::stockMetersSqlExpression().' asc');
    }

    /**
     * @return array{products: int, total_meters: float, alert_limit: float}
     */
    public function summary(): array
    {
        $limit = $this->alertLimit();
        $products = $this->getFilteredTableQuery()?->get() ?? collect();

        return [
            'products' => $products->count(),
            'total_meters' => (float) $products->sum(fn (Product $product): float => $product->stockMeters()),
            'alert_limit' => $limit,
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function formatMeters(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
