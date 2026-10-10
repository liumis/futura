<?php

namespace App\Filament\Admin\Resources\Notes;

use App\Enums\NoteStatus;
use App\Filament\Admin\Resources\Notes\Pages\CreateNote;
use App\Filament\Admin\Resources\Notes\Pages\EditNote;
use App\Filament\Admin\Resources\Notes\Pages\ListNotes;
use App\Models\Note;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class NoteResource extends Resource
{
    protected static ?string $model = Note::class;

    protected static ?string $slug = 'notes';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Notes';

    protected static ?string $modelLabel = 'Note';

    protected static ?string $pluralModelLabel = 'Notes';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('body')
                    ->label('Text')
                    ->rows(8)
                    ->columnSpanFull(),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(NoteStatus::options())
                    ->default(NoteStatus::Active->value)
                    ->required()
                    ->native(false),

                Forms\Components\Placeholder::make('author_display')
                    ->label('Author')
                    ->content(function (?Note $record): string {
                        if ($record?->user) {
                            return $record->user->displayName();
                        }

                        $user = auth()->user();

                        return $user?->displayName() ?? '—';
                    }),

                Forms\Components\Hidden::make('user_id')
                    ->default(fn (): ?int => auth()->id())
                    ->dehydrated(fn (?Note $record): bool => $record === null),

                Forms\Components\Placeholder::make('existing_note_files')
                    ->label('Attached files')
                    ->content(function (?Note $record): Htmlable {
                        if ($record === null) {
                            return new HtmlString('');
                        }

                        $links = $record->sharepointAttachmentLinks();
                        if ($links === []) {
                            return new HtmlString('<span class="text-sm text-gray-500">No files on SharePoint yet.</span>');
                        }

                        $items = [];
                        foreach ($links as $file) {
                            $name = e($file['name']);
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url
                                ? '<li><a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                                : '<li>'.$name.'</li>';
                        }

                        return new HtmlString(
                            '<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>'
                        );
                    })
                    ->visible(fn (?Note $record): bool => $record !== null)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('attachments')
                    ->label(fn (?Note $record): string => ($record?->hasSharepointFiles() ?? false)
                        ? 'Add more files'
                        : 'Files')
                    ->disk('public')
                    ->directory('notes/tmp')
                    ->visibility('public')
                    ->multiple()
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->helperText(UploadLimits::withExistingNote(
                        'Stored on SharePoint under notes/{id}-{title}. Temporary local upload is removed after save.',
                    ))
                    ->downloadable(false)
                    ->openable(false)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Text')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Author')
                    ->getStateUsing(fn (Note $record): string => $record->user?->displayName() ?? '—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sharepoint_files')
                    ->label('Files')
                    ->getStateUsing(fn (Note $record): string => $record->hasSharepointFiles()
                        ? (string) count($record->sharepointAttachmentLinks())
                        : '—')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(NoteStatus::options())
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('user');

        $user = auth()->user();
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        // Sticky notes are personal — each user manages their own.
        return $query->forUser((int) $user->getKey());
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
        $user = auth()->user();
        if ($user === null || ! $user->canAccessNavigationGroup(static::$navigationGroup)) {
            return false;
        }

        return (int) $record->user_id === (int) $user->getKey();
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotes::route('/'),
            'create' => CreateNote::route('/create'),
            'edit' => EditNote::route('/{record}/edit'),
        ];
    }
}
