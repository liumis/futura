<?php

namespace App\Filament\Admin\Resources\WarehouseImports;

use App\Enums\WarehouseImportStatus;
use App\Filament\Admin\Resources\WarehouseImports\Pages;
use App\Models\Product;
use App\Models\WarehouseImport;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class WarehouseImportResource extends Resource
{
    protected static ?string $model = WarehouseImport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Received orders';

    protected static ?string $modelLabel = 'Received order';

    protected static ?string $pluralModelLabel = 'Received orders';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 2;

    public static function isLocked(?WarehouseImport $record): bool
    {
        return $record?->status === WarehouseImportStatus::Received;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('status')
                    ->options(WarehouseImportStatus::class)
                    ->default(WarehouseImportStatus::Pending)
                    ->required()
                    ->native(false)
                    ->disabled(fn (?WarehouseImport $record): bool => self::isLocked($record)),

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

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->sortable(),

                Tables\Columns\SelectColumn::make('status')
                    ->options(WarehouseImportStatus::class)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->sortable()
                    ->disabled(fn (WarehouseImport $record): bool => $record->status === WarehouseImportStatus::Received),

                Tables\Columns\TextColumn::make('received_date')
                    ->label('Received date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cargo.id')
                    ->label('Warehouse order')
                    ->formatStateUsing(fn ($state): string => filled($state) ? '#'.$state : '—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('received_date', 'desc')
            ->emptyStateHeading('No received orders yet')
            ->emptyStateDescription('Received orders appear here when a warehouse order is received and stock is imported.')
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
        return parent::getEloquentQuery()->with(['product.color.collection', 'cargo']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWarehouseImports::route('/'),
            'edit' => Pages\EditWarehouseImport::route('/{record}/edit'),
        ];
    }
}
