<?php

namespace App\Filament\Admin\Resources\Shareholders;

use App\Filament\Admin\Resources\Shareholders\Pages;
use App\Models\Shareholder;
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

class ShareholderResource extends Resource
{
    protected static ?string $model = Shareholder::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Shareholders';

    protected static ?string $modelLabel = 'Shareholder';

    protected static ?string $pluralModelLabel = 'Shareholders';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name / company')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('shareholder_percentage')
                    ->label('Shareholder percent')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->required(),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(50),

                Forms\Components\TextInput::make('bank_account')
                    ->label('Bank account')
                    ->maxLength(255),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name / company')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('shareholder_percentage')
                    ->label('Shareholder percent')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('bank_account')
                    ->label('Bank account')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
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
            'index' => Pages\ListShareholders::route('/'),
            'create' => Pages\CreateShareholder::route('/create'),
            'edit' => Pages\EditShareholder::route('/{record}/edit'),
        ];
    }
}
