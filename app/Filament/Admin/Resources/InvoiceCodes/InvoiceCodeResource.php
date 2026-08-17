<?php

namespace App\Filament\Admin\Resources\InvoiceCodes;

use App\Filament\Admin\Resources\InvoiceCodes\Pages;
use App\Models\InvoiceCode;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InvoiceCodeResource extends Resource
{
    protected static ?string $model = InvoiceCode::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Invoice codes';

    protected static ?string $modelLabel = 'Invoice code';

    protected static ?string $pluralModelLabel = 'Invoice codes';

    protected static string|UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true),

                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('parent_id')
                    ->label('Parent')
                    ->relationship(
                        'parent',
                        'name',
                        modifyQueryUsing: function ($query, Forms\Components\Select $component) {
                            $query->with('parent.parent.parent')->orderBy('code');

                            $record = $component->getRecord();

                            if ($record instanceof InvoiceCode) {
                                $query->whereNotIn(
                                    $query->getModel()->getQualifiedKeyName(),
                                    InvoiceCode::descendantIdsFor($record->id),
                                );
                            }

                            return $query;
                        },
                        ignoreRecord: true,
                    )
                    ->getOptionLabelFromRecordUsing(fn (InvoiceCode $record): string => $record->indentedLabel())
                    ->searchable(['code', 'name'])
                    ->preload()
                    ->native(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state, InvoiceCode $record): string => str_repeat('   ', $record->depth()).$state),

                Tables\Columns\TextColumn::make('parent.code')
                    ->label('Parent code')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('parent_id')
                    ->label('Parent')
                    ->relationship('parent', 'code')
                    ->getOptionLabelFromRecordUsing(fn (InvoiceCode $record): string => $record->indentedLabel())
                    ->searchable(['code', 'name'])
                    ->preload()
                    ->native(false),
            ])
            ->defaultSort('code')
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
        return parent::getEloquentQuery()->with(['parent.parent.parent']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoiceCodes::route('/'),
            'create' => Pages\CreateInvoiceCode::route('/create'),
            'edit' => Pages\EditInvoiceCode::route('/{record}/edit'),
        ];
    }
}
