<?php

namespace App\Filament\Admin\Resources\WarehouseImports;

use App\Enums\WarehouseImportStatus;
use App\Models\Product;
use App\Models\WarehouseImport;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Throwable;
use UnitEnum;

class WarehouseImportResource extends Resource
{
    protected static ?string $model = WarehouseImport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Import to stock';

    protected static ?string $modelLabel = 'Import to stock';

    protected static ?string $pluralModelLabel = 'Import to stock';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 3;

    public static function isLocked(?WarehouseImport $record): bool
    {
        return $record?->status === WarehouseImportStatus::Received;
    }

    public static function recalculateOverheadsAfterAmountChange(WarehouseImport $record, mixed $livewire = null): void
    {
        if (self::isLocked($record)) {
            return;
        }

        WarehouseImport::recalculateOverheadsForCargo(
            $record->cargo_id ? (int) $record->cargo_id : null,
        );

        if (is_object($livewire) && method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Placeholder::make('status_display')
                    ->label('Status')
                    ->content(fn (?WarehouseImport $record): string => $record?->status?->getLabel() ?? '—'),

                Forms\Components\Select::make('product_id')
                    ->label('Product')
                    ->relationship(
                        'product',
                        'product_code',
                        function (Builder $query, ?string $search): Builder {
                            $query
                                ->with(['productType', 'color.collection'])
                                ->orderBy('product_code');

                            if (blank($search)) {
                                return $query;
                            }

                            $term = '%'.$search.'%';

                            return $query->where(function (Builder $inner) use ($term): void {
                                $inner
                                    ->where('product_code', 'like', $term)
                                    ->orWhere('name', 'like', $term)
                                    ->orWhereHas('color', function (Builder $color) use ($term): void {
                                        $color
                                            ->where('color_name', 'like', $term)
                                            ->orWhere('color_code', 'like', $term);
                                    })
                                    ->orWhereHas('color.collection', function (Builder $collection) use ($term): void {
                                        $collection->where('name', 'like', $term);
                                    })
                                    ->orWhereHas('productType', function (Builder $type) use ($term): void {
                                        $type->where('name', 'like', $term);
                                    });
                            });
                        },
                    )
                    ->getOptionLabelFromRecordUsing(
                        function (Product $product): string {
                            if ($product->isCatalog()) {
                                return sprintf(
                                    '%s · %s (%s)',
                                    $product->productType?->name ?? 'Catalog',
                                    $product->name ?? '—',
                                    $product->product_code,
                                );
                            }

                            return sprintf(
                                '%s · %s (%s)',
                                $product->color?->collection?->name ?? '—',
                                $product->color?->color_name ?? '—',
                                $product->product_code,
                            );
                        },
                    )
                    ->searchable([])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

                Forms\Components\TextInput::make('base_cost')
                    ->label('Base cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

                Forms\Components\TextInput::make('overhead_cost')
                    ->label('Overhead per unit')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix(Money::prefix())
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

                Forms\Components\TextInput::make('cost')
                    ->label('Unit cost (total)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

                Forms\Components\Placeholder::make('unit_cost_per_meter')
                    ->label('Unit cost /m')
                    ->content(function (?WarehouseImport $record): string {
                        $value = $record?->unitCostPerMeter();

                        return filled($value)
                            ? Money::format((float) $value)
                            : '—';
                    }),

                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->step(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?WarehouseImport $record, mixed $state, Set $set): void {
                        if ($record === null || self::isLocked($record)) {
                            return;
                        }

                        $record->amount = max(0, (int) $state);
                        $record->save();
                        WarehouseImport::recalculateOverheadsForCargo(
                            $record->cargo_id ? (int) $record->cargo_id : null,
                        );
                        $record->refresh();
                        $set('overhead_cost', $record->overhead_cost);
                        $set('cost', $record->cost);
                    })
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

                Forms\Components\DatePicker::make('received_date')
                    ->label('Received date')
                    ->required()
                    ->native(false)
                    ->default(now())
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.product_code')
                    ->label('Product code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.color.color_name')
                    ->label('Color')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Size')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.color.collection.name')
                    ->label('Collection')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('base_cost')
                    ->label('Base cost')
                    ->money(Money::currency())
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('overhead_cost')
                    ->label('Overhead / unit')
                    ->money(Money::currency())
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('cost')
                    ->label('Unit cost')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_cost_per_meter')
                    ->label('Unit cost /m')
                    ->getStateUsing(fn (WarehouseImport $record): ?float => $record->unitCostPerMeter())
                    ->money(Money::currency())
                    ->placeholder('—'),

                Tables\Columns\TextInputColumn::make('amount')
                    ->label('Amount')
                    ->type('number')
                    ->inputMode('numeric')
                    ->rules(['required', 'integer', 'min:0'])
                    ->sortable()
                    ->disabled(fn (WarehouseImport $record): bool => self::isLocked($record))
                    ->afterStateUpdated(function (WarehouseImport $record, mixed $livewire): void {
                        self::recalculateOverheadsAfterAmountChange($record, $livewire);
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (WarehouseImportStatus $state): string => $state->getColor() ?? 'gray')
                    ->formatStateUsing(fn (WarehouseImportStatus $state): string => $state === WarehouseImportStatus::Pending
                        ? 'Pending | Import'
                        : 'In stock')
                    ->sortable()
                    ->action(
                        Action::make('importToStock')
                            ->visible(fn (WarehouseImport $record): bool => $record->status === WarehouseImportStatus::Pending)
                            ->requiresConfirmation()
                            ->modalHeading('Import to warehouse stock?')
                            ->modalDescription('Adds this quantity to product stock (average cost updated) and marks the line as In stock.')
                            ->modalSubmitActionLabel('Import')
                            ->color('danger')
                            ->action(function (WarehouseImport $record): void {
                                try {
                                    $record->importToStock();
                                } catch (Throwable $exception) {
                                    Notification::make()
                                        ->title('Import failed')
                                        ->body($exception->getMessage())
                                        ->danger()
                                        ->send();

                                    return;
                                }

                                Notification::make()
                                    ->title('Imported to warehouse stock')
                                    ->success()
                                    ->send();
                            }),
                    ),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Received date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultGroup(
                Group::make('cargo_id')
                    ->label('Warehouse order')
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
                    ->getTitleFromRecordUsing(function (WarehouseImport $record): string {
                        $cargo = $record->cargo;

                        if ($cargo === null) {
                            return 'Unlinked lines';
                        }

                        $parts = ['#'.$cargo->id];

                        if (filled($cargo->supplier?->name)) {
                            $parts[] = (string) $cargo->supplier->name;
                        }

                        if (filled($cargo->tracking)) {
                            $parts[] = (string) $cargo->tracking;
                        }

                        $date = $record->received_date?->toDateString()
                            ?? $cargo->actual_date?->toDateString();

                        if (filled($date)) {
                            $parts[] = $date;
                        }

                        return implode(' · ', $parts);
                    }),
            )
            ->collapsedGroupsByDefault()
            ->groupingSettingsHidden()
            ->filters([
                SelectFilter::make('cargo_link')
                    ->label('Warehouse order link')
                    ->options([
                        'linked' => 'Linked orders only',
                        'unlinked' => 'Unlinked lines only',
                        'all' => 'All lines',
                    ])
                    ->default('linked')
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = (string) ($data['value'] ?? 'linked');

                        match ($value) {
                            'unlinked' => $query->whereNull('cargo_id'),
                            'all' => null,
                            default => $query->whereNotNull('cargo_id'),
                        };
                    }),

                Filter::make('received_date')
                    ->label('Received date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return;
                        }

                        $column = $query->getModel()->qualifyColumn('received_date');

                        if (filled($from)) {
                            $query->whereDate($column, '>=', $from);
                        }

                        if (filled($until)) {
                            $query->whereDate($column, '<=', $until);
                        }
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->defaultSort('received_date', 'desc')
            ->emptyStateHeading('No imports yet')
            ->emptyStateDescription('Lines appear when a warehouse order is marked Received. Unlinked lines are hidden by default — use “Unlinked lines only” to find orphans. Click Pending | Import to add stock.')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product.color.collection', 'cargo.supplier']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseImports::route('/'),
            'edit' => Pages\EditWarehouseImport::route('/{record}/edit'),
        ];
    }
}
