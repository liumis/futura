<?php

namespace App\Filament\Admin\Resources\DocumentTypes;

use App\Filament\Admin\Tables\Columns\PeriodicalSignatureMonthsColumn;
use App\Models\DocumentType;
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
use UnitEnum;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Document types';

    protected static ?string $modelLabel = 'Document type';

    protected static ?string $pluralModelLabel = 'Document types';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Document type')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Toggle::make('group_by_year')
                    ->label('Group document to Year folders')
                    ->helperText('Stores documents under a year folder (from document date), e.g. documents/Type/2026/.')
                    ->default(false),

                Forms\Components\TextInput::make('periodical_signature_months')
                    ->label('Revalidate in (months)')
                    ->numeric()
                    ->minValue(0)
                    ->nullable()
                    ->default(0)
                    ->live()
                    ->helperText('0 = no periodical signature. 1+ = renewal period in months.'),

                Forms\Components\Toggle::make('confirmation_attachment_required')
                    ->label('Attachment required on confirm')
                    ->default(false)
                    ->visible(fn (Get $get): bool => ((int) ($get('periodical_signature_months') ?? 0)) > 0)
                    ->helperText('User must upload a copy or photo when confirming (e.g. medical check certificate).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Document type')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('group_by_year')
                    ->label('Year folder')
                    ->sortable(),

                PeriodicalSignatureMonthsColumn::make('periodical_signature_months')
                    ->label('Revalidate in (months)')
                    ->sortable(),

                Tables\Columns\IconColumn::make('confirmation_attachment_required')
                    ->label('Upload on confirm')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->alignCenter()
                    ->toggleable(),

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
            'index' => Pages\ListDocumentTypes::route('/'),
            'create' => Pages\CreateDocumentType::route('/create'),
            'edit' => Pages\EditDocumentType::route('/{record}/edit'),
        ];
    }
}
