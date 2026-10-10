<?php

namespace App\Filament\Admin\Resources\DocumentConfirmations;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Support\DocumentConfirmationActions;
use App\Models\DocumentConfirmation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DocumentConfirmationResource extends Resource
{
    protected static ?string $model = DocumentConfirmation::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Confirmations';

    protected static ?string $modelLabel = 'Confirmation';

    protected static ?string $pluralModelLabel = 'Confirmations';

    protected static string|UnitEnum|null $navigationGroup = 'Documents';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.name')
                    ->label('Document')
                    ->searchable()
                    ->sortable()
                    ->url(fn (DocumentConfirmation $record): ?string => $record->document
                        ? DocumentResource::getUrl('edit', ['record' => $record->document])
                        : null),

                Tables\Columns\TextColumn::make('document.documentType.name')
                    ->label('Type')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.email')
                    ->label('Assignee')
                    ->formatStateUsing(fn (DocumentConfirmation $record): string => $record->user?->fullName()
                        ?: (string) ($record->user?->email ?? '—'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('cycle_index')
                    ->label('Cycle')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('due_at')
                    ->label('Due')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('notified_at')
                    ->label('Notified')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confirmed')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('attachment_path')
                    ->label('Attachment')
                    ->boolean()
                    ->trueIcon('heroicon-o-paper-clip')
                    ->falseIcon('heroicon-o-minus')
                    ->alignCenter()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->state(function (DocumentConfirmation $record): string {
                        if ($record->isConfirmed()) {
                            return 'Confirmed';
                        }

                        if ($record->isOverdue()) {
                            return 'Overdue';
                        }

                        return 'Pending';
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Confirmed' => 'success',
                        'Overdue' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('due_at')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'overdue' => 'Overdue',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'pending' => $query->whereNull('confirmed_at'),
                            'confirmed' => $query->whereNotNull('confirmed_at'),
                            'overdue' => $query
                                ->whereNull('confirmed_at')
                                ->whereDate('due_at', '<', now()->toDateString()),
                            default => $query,
                        };
                    }),

                SelectFilter::make('user_id')
                    ->label('Assignee')
                    ->relationship('user', 'email')
                    ->getOptionLabelFromRecordUsing(fn ($record): string => filled(trim($record->fullName()))
                        ? $record->fullName()
                        : (string) ($record->email ?? ''))
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                DocumentConfirmationActions::configureConfirmAction(
                    Action::make('confirm')
                        ->label('Confirm')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (DocumentConfirmation $record): bool => $record->isPending()
                            && (int) $record->user_id === (int) auth()->id())
                ),

                Action::make('viewAttachment')
                    ->label('Attachment')
                    ->icon('heroicon-o-document-text')
                    ->url(fn (DocumentConfirmation $record): ?string => DocumentConfirmationActions::attachmentUrl($record))
                    ->openUrlInNewTab()
                    ->visible(fn (DocumentConfirmation $record): bool => $record->hasAttachment()),

                Action::make('openDocument')
                    ->label('Open')
                    ->icon('heroicon-o-document')
                    ->url(fn (DocumentConfirmation $record): ?string => $record->document
                        ? DocumentResource::getUrl('edit', ['record' => $record->document])
                        : null)
                    ->openUrlInNewTab(false),
            ])
            ->toolbarActions([]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentConfirmations::route('/'),
        ];
    }
}
