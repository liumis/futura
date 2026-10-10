<?php

namespace App\Filament\Admin\Resources\Products;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductType;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'Product';

    protected static ?string $pluralModelLabel = 'Products';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('product_type_id')
                    ->label('Product type')
                    ->relationship('productType', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->native(false)
                    ->default(fn (): ?int => ProductType::artificialLeatherId()),

                Forms\Components\Select::make('color_id')
                    ->relationship(
                        'color',
                        'color_name',
                        fn (Builder $query) => $query->with('collection')->orderBy('collection_id')->orderBy('color_code'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Color $color): string => sprintf(
                            '%s · %s (%s)',
                            $color->collection?->name ?? '—',
                            $color->color_name,
                            $color->color_code,
                        ),
                    )
                    ->searchable(['color_name', 'color_code'])
                    ->preload()
                    ->required(fn (Get $get): bool => self::typeRequiresColor($get('product_type_id')))
                    ->label('Color'),

                Forms\Components\TextInput::make('name')
                    ->label(fn (Get $get): string => self::typeRequiresColor($get('product_type_id')) ? 'Size (m)' : 'Name')
                    ->required()
                    ->maxLength(255)
                    ->rules(fn (Get $get): array => self::typeRequiresColor($get('product_type_id'))
                        ? ['numeric', 'min:0']
                        : ['string', 'max:255'])
                    ->default('20'),

                Forms\Components\TextInput::make('product_code')
                    ->required()
                    ->maxLength(255)
                    ->label('Product code'),

                Forms\Components\TextInput::make('alternative_code')
                    ->maxLength(255)
                    ->label('Alternative code'),

                Forms\Components\TextInput::make('dsv_code')
                    ->maxLength(255)
                    ->label('DSV code'),

                Forms\Components\TextInput::make('cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->default(0)
                    ->label(fn (Get $get): string => self::typeRequiresColor($get('product_type_id')) ? 'Cost/m' : 'Cost'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('productType.name')
                    ->searchable()
                    ->sortable()
                    ->label('Type'),

                Tables\Columns\TextColumn::make('color.collection.name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->label('Collection'),

                Tables\Columns\TextColumn::make('color.color_name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->label('Color name'),

                Tables\Columns\TextColumn::make('color.color_code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->label('Color code'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->label('Size (m)'),

                Tables\Columns\TextColumn::make('product_code')
                    ->searchable()
                    ->sortable()
                    ->label('Product code'),

                Tables\Columns\TextColumn::make('alternative_code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->label('Alternative code')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_type_id')
                    ->label('')
                    ->relationship('productType', 'name')
                    ->searchable()
                    ->placeholder('Type: All')
                    ->preload(),

                Tables\Filters\SelectFilter::make('collection')
                    ->label('')
                    ->relationship('color.collection', 'name')
                    ->searchable()
                    ->placeholder('Collection: All')
                    ->preload(),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->paginationPageOptions([20, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['productType', 'color.collection']);
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }

    private static function typeRequiresColor(mixed $productTypeId): bool
    {
        if (blank($productTypeId)) {
            return true;
        }

        $type = ProductType::query()->find($productTypeId);

        return $type === null || (bool) $type->requires_color;
    }
}
