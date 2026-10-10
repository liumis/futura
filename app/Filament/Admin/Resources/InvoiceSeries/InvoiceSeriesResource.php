<?php

namespace App\Filament\Admin\Resources\InvoiceSeries;

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
                    ->helperText('Prepended to the invoice number, e.g. FTE (numbers are zero-padded to 4 digits: FTE0148).'),

                Forms\Components\TextInput::make('first_item_no')
                    ->label('First item no')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->integer()
                    ->helperText('Used when this series has no invoices yet. After existing invoices are linked, the next number continues from the highest series number.'),

                Forms\Components\Placeholder::make('next_invoice_preview')
                    ->label('Next invoice no')
                    ->content(function (?InvoiceSeries $record): string {
                        if ($record === null || ! $record->exists) {
                            return 'Save the series to preview the next number.';
                        }

                        return $record->formatNumber($record->nextSeriesNumber());
                    }),

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

                Tables\Columns\TextColumn::make('next_invoice_no')
                    ->label('Next invoice no')
                    ->state(fn (InvoiceSeries $record): string => $record->formatNumber($record->nextSeriesNumber()))
                    ->weight('semibold')
                    ->copyable(),

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
            'index' => Pages\ListInvoiceSeries::route('/'),
            'create' => Pages\CreateInvoiceSeries::route('/create'),
            'edit' => Pages\EditInvoiceSeries::route('/{record}/edit'),
        ];
    }
}
