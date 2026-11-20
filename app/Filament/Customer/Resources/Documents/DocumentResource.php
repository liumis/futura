<?php

namespace App\Filament\Customer\Resources\Documents;

use App\Models\Document;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $modelLabel = 'Document';

    protected static ?string $pluralModelLabel = 'Documents';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 1;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_date')
                    ->label('Date')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Document')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('documentType.name')
                    ->label('Type')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\ViewColumn::make('files')
                    ->label('Files')
                    ->view('filament.customer.tables.columns.document-links'),
            ])
            ->defaultSort('document_date', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $userId = auth()->id();

        return parent::getEloquentQuery()
            ->whereHas('customers', fn (Builder $query): Builder => $query->whereKey($userId))
            ->with(['documentType']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole(User::ROLE_CUSTOMER) ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if (! ($user?->hasRole(User::ROLE_CUSTOMER) ?? false)) {
            return false;
        }

        return Document::query()
            ->whereHas('customers', fn (Builder $query): Builder => $query->whereKey($user->getKey()))
            ->exists();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canView($record): bool
    {
        return $record instanceof Document
            && $record->isAssignedToCustomer(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
        ];
    }
}
