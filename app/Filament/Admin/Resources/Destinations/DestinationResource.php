<?php

namespace App\Filament\Admin\Resources\Destinations;

use App\Filament\Admin\Resources\Destinations\Pages;
use App\Models\Destination;
use App\Models\ShippingSetting;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class DestinationResource extends Resource
{
    protected static ?string $model = Destination::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Destinations';

    protected static ?string $modelLabel = 'Destination';

    protected static ?string $pluralModelLabel = 'Destinations';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?string $navigationParentItem = 'Shipping settings';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('shipping_setting_id')
                    ->label('Shipping provider')
                    ->relationship('shippingSetting', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn (): ?int => ShippingSetting::query()->where('is_default', true)->value('id')
                        ?? ShippingSetting::query()->orderBy('name')->value('id')),

                Forms\Components\Select::make('country_id')
                    ->relationship('country', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Country'),

                Forms\Components\TextInput::make('city')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('postal_code')
                    ->label('Postal code')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('default_package_cost')
                    ->label('Default package cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->required(),

                Forms\Components\TextInput::make('cost_per_kg')
                    ->label('1kg cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shippingSetting.name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('country.name')
                    ->label('Country')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('city')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('postal_code')
                    ->label('Postal code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_package_cost')
                    ->label('Default package cost')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_per_kg')
                    ->label('1kg cost')
                    ->money(Money::currency())
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('shipping_setting_id')
                    ->label('Provider')
                    ->relationship('shippingSetting', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('country_id');
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
            'index' => Pages\ListDestinations::route('/'),
            'create' => Pages\CreateDestination::route('/create'),
            'edit' => Pages\EditDestination::route('/{record}/edit'),
        ];
    }
}
