<?php

namespace App\Filament\Admin\Resources\DirectoryContactStages;

use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Models\DirectoryContactStage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DirectoryContactStageResource extends Resource
{
    protected static ?string $model = DirectoryContactStage::class;

    protected static ?string $slug = 'directory-contact-stages';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-queue-list';

    protected static ?string $navigationLabel = 'Stages';

    protected static ?string $modelLabel = 'Stage';

    protected static ?string $pluralModelLabel = 'Stages';

    protected static string|UnitEnum|null $navigationGroup = 'Contacts';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('contacts_count')
                    ->label('Contacts')
                    ->counts('contacts')
                    ->numeric()
                    ->alignEnd()
                    ->sortable()
                    ->url(fn (DirectoryContactStage $record): string => self::contactsListUrl($record))
                    ->color('primary')
                    ->tooltip('Show contacts in this stage'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false)
            ->recordActions([
                Action::make('viewContacts')
                    ->label('Contacts')
                    ->icon('heroicon-o-users')
                    ->color('gray')
                    ->url(fn (DirectoryContactStage $record): string => self::contactsListUrl($record)),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function contactsListUrl(DirectoryContactStage $stage): string
    {
        return DirectoryContactResource::getUrl('index', [
            'tableFilters' => [
                'directory_contact_stage_id' => [
                    'value' => $stage->getKey(),
                ],
            ],
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('contacts');
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
            'index' => Pages\ListDirectoryContactStages::route('/'),
            'create' => Pages\CreateDirectoryContactStage::route('/create'),
            'edit' => Pages\EditDirectoryContactStage::route('/{record}/edit'),
        ];
    }
}
