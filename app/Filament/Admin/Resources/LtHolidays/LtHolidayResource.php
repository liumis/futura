<?php

namespace App\Filament\Admin\Resources\LtHolidays;

use App\Filament\Admin\Resources\LtHolidays\Pages;
use App\Models\LtHoliday;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class LtHolidayResource extends Resource
{
    protected static ?string $model = LtHoliday::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'LT holidays';

    protected static ?string $modelLabel = 'LT holiday';

    protected static ?string $pluralModelLabel = 'LT holidays';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Select::make('rule_type')
                    ->label('Recurrence')
                    ->options([
                        'fixed' => 'Fixed date (same every year)',
                        'easter' => 'Relative to Easter',
                    ])
                    ->default('fixed')
                    ->required()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(function (Forms\Components\Select $component, ?LtHoliday $record): void {
                        if ($record === null) {
                            return;
                        }

                        $component->state($record->isEasterBased() ? 'easter' : 'fixed');
                    }),

                Forms\Components\Select::make('month')
                    ->label('Month')
                    ->options(static::monthOptions())
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('rule_type') === 'fixed')
                    ->visible(fn (Get $get): bool => $get('rule_type') === 'fixed'),

                Forms\Components\Select::make('day')
                    ->label('Day')
                    ->options(static::dayOptions())
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('rule_type') === 'fixed')
                    ->visible(fn (Get $get): bool => $get('rule_type') === 'fixed'),

                Forms\Components\Select::make('easter_offset')
                    ->label('Easter rule')
                    ->options([
                        1 => 'Easter Monday',
                    ])
                    ->default(1)
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('rule_type') === 'easter')
                    ->visible(fn (Get $get): bool => $get('rule_type') === 'easter'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('recurrence')
                    ->label('When')
                    ->getStateUsing(fn (LtHoliday $record): string => $record->recurrenceLabel())
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderByRaw('CASE WHEN easter_offset IS NULL THEN 0 ELSE 1 END '.$direction)
                            ->orderBy('month', $direction)
                            ->orderBy('day', $direction)
                            ->orderBy('easter_offset', $direction);
                    }),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('month')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function monthOptions(): array
    {
        return [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function dayOptions(): array
    {
        $days = [];

        for ($day = 1; $day <= 31; $day++) {
            $days[$day] = (string) $day;
        }

        return $days;
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
            'index' => Pages\ListLtHolidays::route('/'),
            'create' => Pages\CreateLtHoliday::route('/create'),
            'edit' => Pages\EditLtHoliday::route('/{record}/edit'),
        ];
    }
}
