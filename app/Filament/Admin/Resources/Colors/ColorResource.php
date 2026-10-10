<?php

namespace App\Filament\Admin\Resources\Colors;

use App\Models\Color;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ColorResource extends Resource
{
    protected static ?string $model = Color::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-swatch';

    protected static ?string $navigationLabel = 'Colors';

    protected static ?string $modelLabel = 'Color';

    protected static ?string $pluralModelLabel = 'Colors';

    protected static string|UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('collection_id')
                    ->relationship('collection', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Collection'),

                Forms\Components\TextInput::make('color_code')
                    ->required()
                    ->maxLength(255)
                    ->label('Color code'),

                Forms\Components\TextInput::make('color_name')
                    ->required()
                    ->maxLength(255)
                    ->label('Color name'),

                Forms\Components\TextInput::make('recommended_stock')
                    ->label('Recommended stock')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(200)
                    ->suffix('m')
                    ->required()
                    ->helperText('Target stock level in meters. Used later for low-stock alerts.'),

                Forms\Components\TextInput::make('default_order')
                    ->label('Default order')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(10)
                    ->suffix('rolls')
                    ->required()
                    ->helperText('Default order quantity in rolls (items), used by Auto order.'),

                Forms\Components\ColorPicker::make('hex')
                    ->label('Real color')
                    ->hex()
                    ->nullable()
                    ->helperText('Used on stock charts. Run colors:fill-hex or use “Fill hex from images” to autofill from the swatch.'),

                Forms\Components\FileUpload::make('image')
                    ->label('Image')
                    ->image()
                    ->disk(Color::storageDisk())
                    ->directory('colors')
                    ->visibility('public')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                    ->maxSize(5120)
                    ->helperText('Maximum upload size: 5 MB.')
                    ->fetchFileInformation(false)
                    ->imageEditor()
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->label('Image')
                    ->disk(Color::storageDisk())
                    ->visibility(Color::storageDisk() === 's3' ? 'private' : 'public')
                    ->checkFileExistence(false)
                    ->square()
                    ->size(40),

                Tables\Columns\ColorColumn::make('hex')
                    ->label('Real color')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('collection.name')
                    ->searchable()
                    ->sortable()
                    ->label('Collection'),

                Tables\Columns\TextColumn::make('color_name')
                    ->searchable()
                    ->sortable()
                    ->label('Color name'),

                Tables\Columns\TextColumn::make('color_code')
                    ->searchable()
                    ->sortable()
                    ->label('Color code'),

                Tables\Columns\TextInputColumn::make('recommended_stock')
                    ->label('Recommended stock (m)')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->width('7rem')
                    ->grow(false)
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('default_order')
                    ->label('Default order (rolls)')
                    ->type('number')
                    ->rules(['required', 'integer', 'min:0'])
                    ->width('7rem')
                    ->grow(false)
                    ->sortable(),

                Tables\Columns\TextColumn::make('products_count')
                    ->counts('products')
                    ->label('Products')
                    ->sortable(),

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
                Tables\Filters\SelectFilter::make('collection_id')
                    ->label('')
                    ->relationship('collection', 'name')
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
        return parent::getEloquentQuery()->with('collection')->withCount('products');
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
            'index' => Pages\ListColors::route('/'),
            'create' => Pages\CreateColor::route('/create'),
            'edit' => Pages\EditColor::route('/{record}/edit'),
        ];
    }
}
