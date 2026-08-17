<?php

namespace App\Filament\Admin\Resources\CustomerLevels;

use App\Models\Collection;
use App\Models\CustomerLevel;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CustomerLevelResource extends Resource
{
    protected static ?string $model = CustomerLevel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationLabel = 'Customer levels';

    protected static ?string $modelLabel = 'Customer level';

    protected static ?string $pluralModelLabel = 'Customer levels';

    protected static string|UnitEnum|null $navigationGroup = 'Users';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Repeater::make('priceRows')
                    ->label('Prices by collection')
                    ->schema([
                        Forms\Components\Hidden::make('collection_id'),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\Placeholder::make('collection_label')
                                    ->label('Collection')
                                    ->content(fn ($get) => Collection::query()->find($get('collection_id'))?->name ?? '—'),

                                Forms\Components\TextInput::make('price')
                                    ->label('Price')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->prefix(Money::prefix())
                                    ->required(),
                            ]),
                    ])
                    ->reorderable(false)
                    ->addable(false)
                    ->deletable(false)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_level_prices_count')
                    ->counts('customerLevelPrices')
                    ->label('Price rows')
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
                //
            ])
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
        return parent::getEloquentQuery()->withCount('customerLevelPrices');
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
            'index' => Pages\ListCustomerLevels::route('/'),
            'create' => Pages\CreateCustomerLevel::route('/create'),
            'edit' => Pages\EditCustomerLevel::route('/{record}/edit'),
        ];
    }
}
