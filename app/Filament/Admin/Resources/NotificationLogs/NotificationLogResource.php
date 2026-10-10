<?php

namespace App\Filament\Admin\Resources\NotificationLogs;

use App\Filament\Admin\Resources\NotificationLogs\Pages\ListNotificationLogs;
use App\Models\NotificationLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Notification logs';

    protected static ?string $modelLabel = 'Notification log';

    protected static ?string $pluralModelLabel = 'Notification logs';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Sent at')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('recipient.email')
                    ->label('Recipient')
                    ->formatStateUsing(fn (NotificationLog $record): string => $record->recipient?->fullName()
                        ?: (string) ($record->recipient?->email ?? '—'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('channel')
                    ->label('Channel')
                    ->formatStateUsing(fn (NotificationLog $record): string => $record->channelLabel())
                    ->badge()
                    ->color(fn (NotificationLog $record): string => match ($record->channel) {
                        'mail' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('notification_type')
                    ->label('Type')
                    ->formatStateUsing(fn (NotificationLog $record): string => $record->typeLabel())
                    ->sortable(),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    ->limit(60),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('Date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return;
                        }

                        $column = $query->getModel()->qualifyColumn('created_at');

                        if (filled($from)) {
                            $query->whereDate($column, '>=', $from);
                        }

                        if (filled($until)) {
                            $query->whereDate($column, '<=', $until);
                        }
                    }),

                SelectFilter::make('channel')
                    ->options([
                        'database' => 'In-app',
                        'mail' => 'Email',
                    ]),

                SelectFilter::make('notification_type')
                    ->label('Type')
                    ->options([
                        'document_confirmation' => 'Document confirmation',
                        'document_expiration' => 'Document expiration',
                    ]),

                SelectFilter::make('recipient_user_id')
                    ->label('Recipient')
                    ->relationship('recipient', 'email', fn (Builder $query) => $query->orderBy('name'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => $record->displayName()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (NotificationLog $record): string => $record->title ?: 'Notification log')
                    ->modalContent(fn (NotificationLog $record): Htmlable => new HtmlString(
                        view('filament.admin.components.notification-log-details', [
                            'record' => $record->loadMissing('recipient'),
                        ])->render()
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationLogs::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('recipient');
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
}
