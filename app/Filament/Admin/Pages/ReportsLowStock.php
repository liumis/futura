<?php

namespace App\Filament\Admin\Pages;

use App\Models\Color;
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

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 16;

    protected static ?string $title = 'Low stock';

    protected string $view = 'filament.admin.pages.reports-low-stock';

    public function table(Table $table): Table
    {
        $metersSql = Color::stockMetersSqlExpression();

        return $table
            ->query(fn (): Builder => $this->colorsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('collection.name')
                    ->label('Collection')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('color_name')
                    ->label('Color')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('color_code')
                    ->label('Color code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock_meters')
                    ->label('Total stock (m)')
                    ->getStateUsing(fn (Color $record): float => (float) ($record->stock_meters_total ?? $record->stockMeters()))
                    ->formatStateUsing(fn (float $state): string => self::formatMeters($state))
                    ->alignEnd()
                    ->sortable(query: function (Builder $query, string $direction) use ($metersSql): Builder {
                        return $query->orderByRaw($metersSql.' '.$direction);
                    })
                    ->color('danger')
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('recommended_stock')
                    ->label('Recommended (m)')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('collection_id')
                    ->label('Collection')
                    ->relationship('collection', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No low stock colors')
            ->emptyStateDescription('All colors are at or above their recommended stock.');
    }

    private function colorsQuery(): Builder
    {
        $metersSql = Color::stockMetersSqlExpression();

        return Color::query()
            ->with('collection')
            ->belowRecommendedStock()
            ->select('colors.*')
            ->selectRaw("{$metersSql} as stock_meters_total")
            ->orderByRaw("{$metersSql} asc");
    }

    /**
     * @return array{colors: int, total_meters: float, total_recommended: float, total_deficit: float}
     */
    public function summary(): array
    {
        $colors = $this->getFilteredTableQuery()?->get() ?? collect();

        $totalMeters = (float) $colors->sum(fn (Color $color): float => (float) ($color->stock_meters_total ?? $color->stockMeters()));
        $totalRecommended = (float) $colors->sum(fn (Color $color): float => (float) max(0, (int) $color->recommended_stock));

        return [
            'colors' => $colors->count(),
            'total_meters' => $totalMeters,
            'total_recommended' => $totalRecommended,
            'total_deficit' => max(0, $totalRecommended - $totalMeters),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function formatMeters(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }
}
