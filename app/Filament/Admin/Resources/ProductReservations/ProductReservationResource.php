<?php

namespace App\Filament\Admin\Resources\ProductReservations;

use App\Filament\Admin\Support\DoubleClickCellEdit;
use App\Models\Product;
use App\Models\ProductReservation;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ProductReservationResource extends Resource
{
    protected static ?string $model = ProductReservation::class;

    protected static ?string $slug = 'reservations';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationLabel = 'Reservations';

    protected static ?string $modelLabel = 'Reservation';

    protected static ?string $pluralModelLabel = 'Reservations';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('product_id')
                    ->label('Product')
                    ->relationship(
                        'product',
                        'product_code',
                        fn (Builder $query) => $query
                            ->artificialLeather()
                            ->with(['productType', 'color.collection']),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Product $record): string => self::productLabel($record))
                    ->searchable(['product_code', 'alternative_code', 'name'])
                    ->preload()
                    ->required()
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNotNull('product_id'),
                    )
                    ->disabledOn('edit')
                    ->native(false),

                Forms\Components\TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->default(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('product.productType.name')
                        ->label('Type')
                        ->sortable()
                        ->toggleable(),
                    'product_type',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('product.color.collection.name')
                        ->label('Collection')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—'),
                    'collection',
                    fn (ProductReservation $record): bool => $record->product?->color_id !== null,
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('product.color.color_name')
                        ->label('Color/Name')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—'),
                    'color_name',
                    fn (ProductReservation $record): bool => $record->product?->color_id !== null,
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('product.color.color_code')
                        ->label('Color code')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—'),
                    'color_code',
                    fn (ProductReservation $record): bool => $record->product?->color_id !== null,
                ),

                Tables\Columns\TextColumn::make('product.name')
                    ->label('Size (m)')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.product_code')
                    ->label('Product code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('quantity')
                    ->label('Quantity')
                    ->type('number')
                    ->inputMode('numeric')
                    ->rules(['required', 'integer', 'min:0'])
                    ->width('6rem')
                    ->grow(false)
                    ->alignEnd()
                    ->sortable()
                    ->afterStateUpdated(function (mixed $livewire): void {
                        if (is_object($livewire) && method_exists($livewire, 'resetTable')) {
                            $livewire->resetTable();
                        }
                    }),

                Tables\Columns\TextColumn::make('quantity_meters')
                    ->label('Quantity (m)')
                    ->getStateUsing(fn (ProductReservation $record): ?float => $record->quantityMeters())
                    ->formatStateUsing(function (mixed $state): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }

                        return rtrim(rtrim(number_format((float) $state, 3, '.', ''), '0'), '.');
                    })
                    ->placeholder('—')
                    ->alignEnd(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No reservations')
            ->emptyStateDescription('Add an artificial leather product by hand, or import sample-warehouse rows from Stock → Update from Excel.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('product', fn (Builder $query) => $query->artificialLeather())
            ->with(['product.productType', 'product.color.collection']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
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
            'index' => Pages\ListProductReservations::route('/'),
            'create' => Pages\CreateProductReservation::route('/create'),
            'edit' => Pages\EditProductReservation::route('/{record}/edit'),
        ];
    }

    private static function productLabel(Product $product): string
    {
        $product->loadMissing(['productType', 'color.collection']);

        $parts = array_filter([
            $product->product_code,
            $product->color?->collection?->name,
            trim(($product->color?->color_name ?? '').' / '.($product->name ?? '')),
        ], fn ($value): bool => filled($value));

        return implode(' · ', $parts);
    }
}
