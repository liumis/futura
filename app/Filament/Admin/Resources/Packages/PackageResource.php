<?php

namespace App\Filament\Admin\Resources\Packages;

use App\Filament\Admin\Resources\Packages\Pages;
use App\Models\Package;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationLabel = 'Packages';

    protected static ?string $modelLabel = 'Package';

    protected static ?string $pluralModelLabel = 'Packages';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('total_weight')
                    ->label('Total weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('kg'),

                Forms\Components\TextInput::make('plastic_weight')
                    ->label('Plastic weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('kg'),

                Forms\Components\TextInput::make('cardboard_i_weight')
                    ->label('Cardboard I weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('kg'),

                Forms\Components\TextInput::make('cardboard_ii_weight')
                    ->label('Cardboard II weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('kg'),

                Forms\Components\TextInput::make('items_on_palette')
                    ->label('Items on palette')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(0)
                    ->step(1),

                Forms\Components\TextInput::make('palette_weight')
                    ->label('Palette weight')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('kg'),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_weight')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('plastic_weight')
                    ->label('Plastic')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cardboard_i_weight')
                    ->label('Cardboard I')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cardboard_ii_weight')
                    ->label('Cardboard II')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg')
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_on_palette')
                    ->label('Items on palette')
                    ->sortable(),

                Tables\Columns\TextColumn::make('palette_weight')
                    ->label('Palette')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 3).' kg')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
