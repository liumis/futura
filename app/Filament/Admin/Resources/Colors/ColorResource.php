<?php

namespace App\Filament\Admin\Resources\Colors;

use App\Filament\Admin\Resources\Colors\Pages;
use App\Models\Color;
use App\Support\UploadLimits;
use BackedEnum;
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
                    ->getStateUsing(fn (Color $record): ?string => $record->imageUrl())
                    ->checkFileExistence(false)
                    ->square()
                    ->size(40),

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
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->paginationPageOptions([20, 50, 100])
            ->defaultPaginationPageOption(20);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('collection')->withCount('products');
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
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
            'index' => Pages\ListColors::route('/'),
            'create' => Pages\CreateColor::route('/create'),
            'edit' => Pages\EditColor::route('/{record}/edit'),
        ];
    }
}
