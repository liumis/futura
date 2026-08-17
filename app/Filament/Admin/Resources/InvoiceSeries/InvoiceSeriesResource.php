<?php

namespace App\Filament\Admin\Resources\InvoiceSeries;

use App\Filament\Admin\Resources\InvoiceSeries\Pages;
use App\Models\InvoiceSeries;
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

class InvoiceSeriesResource extends Resource
{
    protected static ?string $model = InvoiceSeries::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hashtag';

    protected static ?string $navigationLabel = 'Invoices series';

    protected static ?string $modelLabel = 'invoice series';

    protected static ?string $pluralModelLabel = 'Invoices series';

    protected static string|UnitEnum|null $navigationGroup = 'Financial options';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('prefix')
                    ->label('Prefix')
                    ->required()
                    ->maxLength(50)
                    ->helperText('Prepended to the invoice number, e.g. FT-'),

                Forms\Components\TextInput::make('first_item_no')
                    ->label('First item no')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->integer()
                    ->helperText('Number used for the first invoice in this series.'),

                Forms\Components\Checkbox::make('is_default')
                    ->label('Use as default')
                    ->helperText('The default series is used when generating invoices from shipped orders.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('prefix')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('first_item_no')
                    ->label('First item no')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
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
            ->defaultSort('prefix')
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
            'index' => Pages\ListInvoiceSeries::route('/'),
            'create' => Pages\CreateInvoiceSeries::route('/create'),
            'edit' => Pages\EditInvoiceSeries::route('/{record}/edit'),
        ];
    }
}
