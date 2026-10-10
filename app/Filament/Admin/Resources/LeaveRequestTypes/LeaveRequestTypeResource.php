<?php

namespace App\Filament\Admin\Resources\LeaveRequestTypes;

use App\Models\LeaveRequestType;
use App\Support\TimesheetCode;
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

class LeaveRequestTypeResource extends Resource
{
    protected static ?string $model = LeaveRequestType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Leave request types';

    protected static ?string $modelLabel = 'Leave request type';

    protected static ?string $pluralModelLabel = 'Leave request types';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\ColorPicker::make('color')
                    ->label('Calendar color')
                    ->default('#6b7280')
                    ->required(),

                Forms\Components\Select::make('timesheet_code')
                    ->label('Timesheet code')
                    ->options(TimesheetCode::assignableOptions())
                    ->searchable()
                    ->nullable()
                    ->native(false)
                    ->dehydrateStateUsing(fn (mixed $state): ?string => TimesheetCode::normalize(
                        is_string($state) ? $state : null,
                    ))
                    ->helperText('Official žiniaraštis code (e.g. A = annual leave). Leave empty for types that should not mark absences (e.g. remote work).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('timesheet_code')
                    ->label('Code')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\ColorColumn::make('color')
                    ->label('Color'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => Pages\ListLeaveRequestTypes::route('/'),
            'create' => Pages\CreateLeaveRequestType::route('/create'),
            'edit' => Pages\EditLeaveRequestType::route('/{record}/edit'),
        ];
    }
}
