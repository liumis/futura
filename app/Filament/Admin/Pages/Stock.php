<?php

namespace App\Filament\Admin\Pages;

use App\Models\Product;
use App\Support\Money;
use App\Services\StockManualUpdateLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class Stock extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $title = 'Stock';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.stock';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Product::query()->with(['productType', 'color.collection']))
            ->columns([
                Tables\Columns\TextColumn::make('productType.name')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('color.collection.name')
                    ->label('Collection')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('color.color_name')
                    ->label('Color')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('color.color_code')
                    ->label('Color code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name / Size')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product_code')
                    ->label('Product code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('alternative_code')
                    ->label('Alternative code')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('default_cost')
                    ->label('Price m')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('current_amount')
                    ->label('Stock')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
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
            ->recordActions([
                Action::make('write_off')
                    ->label('Write-off')
                    ->icon('heroicon-o-minus-circle')
                    ->modalHeading(fn (Product $record): string => 'Write-off: '.$record->product_code)
                    ->modalDescription(fn (Product $record): string => 'Current stock: '.number_format((int) $record->current_amount))
                    ->form([
                        TextInput::make('amount')
                            ->label('Amount to write off')
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue(1),
                    ])
                    ->action(function (Product $record, array $data): void {
                        $writeOff = (int) ($data['amount'] ?? 0);
                        $oldAmount = (int) $record->current_amount;

                        if ($writeOff < 1) {
                            Notification::make()
                                ->title('Enter a valid amount')
                                ->danger()
                                ->send();

                            return;
                        }

                        if ($writeOff > $oldAmount) {
                            Notification::make()
                                ->title('Write-off exceeds current stock')
                                ->body('Maximum: '.number_format($oldAmount))
                                ->danger()
                                ->send();

                            return;
                        }

                        $newAmount = $oldAmount - $writeOff;
                        $record->update(['current_amount' => $newAmount]);
                        StockManualUpdateLogger::log($record, $oldAmount, $newAmount);

                        Notification::make()
                            ->title('Stock written off')
                            ->body(sprintf(
                                'Removed %s unit(s). New stock: %s.',
                                number_format($writeOff),
                                number_format($newAmount),
                            ))
                            ->success()
                            ->send();
                    })
                    ->visible(fn (Product $record): bool => (int) $record->current_amount > 0),
            ])
            ->defaultSort('product_code')
            ->paginated([20, 50, 100])
            ->defaultPaginationPageOption(50)
            ->emptyStateHeading('No stock items')
            ->emptyStateDescription('Products will appear here once they are added to the catalog.');
    }

    /**
     * @return array{products: int, units: int}
     */
    public function stockSummary(): array
    {
        $query = $this->getFilteredTableQuery() ?? Product::query();

        return [
            'products' => (int) (clone $query)->count(),
            'units' => (int) (clone $query)->sum('current_amount'),
        ];
    }
}
