<?php

namespace App\Filament\Admin\Resources\BusinessTripDailyAllowances;

use App\Models\BusinessTripDailyAllowance;
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

class BusinessTripDailyAllowanceResource extends Resource
{
    protected static ?string $model = BusinessTripDailyAllowance::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-europe-africa';

    protected static ?string $navigationLabel = 'Business trip allowances';

    protected static ?string $modelLabel = 'Business trip allowance';

    protected static ?string $pluralModelLabel = 'Business trip allowances';

    protected static string|UnitEnum|null $navigationGroup = 'Financial options';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'business-trip-allowances';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('list_number')
                    ->label('List No.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(999)
                    ->nullable(),

                Forms\Components\TextInput::make('sort_order')
                    ->label('Sort order')
                    ->numeric()
                    ->required()
                    ->default(fn (): int => ((int) BusinessTripDailyAllowance::query()->max('sort_order')) + 1),

                Forms\Components\TextInput::make('country')
                    ->label('Country / city')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('daily_rate')
                    ->label('Daily rate (EUR)')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('€'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('list_number')
                    ->label('No.')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('country')
                    ->label('Country / city')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('daily_rate')
                    ->label('Daily rate')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
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
            'index' => Pages\ListBusinessTripDailyAllowances::route('/'),
            'create' => Pages\CreateBusinessTripDailyAllowance::route('/create'),
            'edit' => Pages\EditBusinessTripDailyAllowance::route('/{record}/edit'),
        ];
    }
}
