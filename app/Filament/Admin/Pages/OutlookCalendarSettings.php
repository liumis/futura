<?php

namespace App\Filament\Admin\Pages;

use App\Enums\CalendarConnectionStatus;
use App\Enums\CalendarProvider;
use App\Jobs\RenewMicrosoftCalendarSubscriptions;
use App\Jobs\SyncMicrosoftCalendarChanges;
use App\Models\CalendarConnection;
use App\Services\Calendar\MicrosoftCalendarProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class OutlookCalendarSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Outlook Calendar';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Outlook Calendar';

    protected static ?string $slug = 'outlook-calendar';

    protected string $view = 'filament.admin.pages.outlook-calendar-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $connection = $this->connection();

        $this->form->fill([
            'calendar_id' => $connection?->calendar_id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $connection = $this->connection();
        $options = [];

        if ($connection !== null && $connection->isActive()) {
            try {
                $options = collect(app(MicrosoftCalendarProvider::class)->listCalendars($connection))
                    ->mapWithKeys(fn (array $row): array => [$row['id'] => $row['name'].($row['is_default'] ? ' (default)' : '')])
                    ->all();
            } catch (\Throwable $exception) {
                Notification::make()
                    ->title('Could not load calendars')
                    ->body($exception->getMessage())
                    ->warning()
                    ->send();
            }
        }

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Connection')
                    ->description('Connect your Microsoft Outlook calendar with delegated Calendars.ReadWrite permission. SharePoint file storage continues to use app-only credentials separately.')
                    ->schema([
                        Placeholder::make('status')
                            ->label('Status')
                            ->content(function () use ($connection): string {
                                if (! MicrosoftCalendarProvider::isConfigured()) {
                                    return 'OAuth is not configured. Set MICROSOFT_CALENDAR_* env vars (or SharePoint Entra app credentials as fallback).';
                                }
                                if ($connection === null) {
                                    return 'Not connected.';
                                }

                                return sprintf(
                                    '%s — %s%s',
                                    $connection->status?->value ?? 'unknown',
                                    $connection->account_email ?: 'no email',
                                    $connection->calendar_name ? ' / '.$connection->calendar_name : '',
                                );
                            }),

                        Select::make('calendar_id')
                            ->label('Outlook calendar')
                            ->options($options)
                            ->searchable()
                            ->native(false)
                            ->visible(fn (): bool => $connection !== null && $connection->isActive())
                            ->helperText('Tasks with “Sync to Outlook” enabled are written to this calendar.'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        $connection = $this->connection();

        return [
            Action::make('connect')
                ->label('Connect Outlook')
                ->icon('heroicon-o-link')
                ->url(fn (): string => url('/oauth/microsoft/calendar/redirect'))
                ->visible(fn (): bool => MicrosoftCalendarProvider::isConfigured()
                    && ($connection === null || $connection->status === CalendarConnectionStatus::Disconnected)),

            Action::make('reconnect')
                ->label('Reconnect')
                ->icon('heroicon-o-arrow-path')
                ->url(fn (): string => url('/oauth/microsoft/calendar/redirect'))
                ->visible(fn (): bool => $connection !== null
                    && $connection->status !== CalendarConnectionStatus::Disconnected
                    && MicrosoftCalendarProvider::isConfigured()),

            Action::make('saveCalendar')
                ->label('Save calendar')
                ->action('saveCalendar')
                ->visible(fn (): bool => $connection !== null && $connection->isActive()),

            Action::make('syncNow')
                ->label('Sync now')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $connection = $this->connection();
                    if ($connection === null) {
                        return;
                    }
                    SyncMicrosoftCalendarChanges::dispatch($connection->getKey());
                    Notification::make()->title('Calendar sync queued')->success()->send();
                })
                ->visible(fn (): bool => $connection !== null && $connection->isActive()),

            Action::make('disconnect')
                ->label('Disconnect')
                ->color('danger')
                ->requiresConfirmation()
                ->action('disconnect')
                ->visible(fn (): bool => $connection !== null
                    && $connection->status !== CalendarConnectionStatus::Disconnected),
        ];
    }

    public function saveCalendar(): void
    {
        $connection = $this->connection();
        if ($connection === null) {
            return;
        }

        $calendarId = $this->form->getState()['calendar_id'] ?? null;
        if (! filled($calendarId)) {
            Notification::make()->title('Select a calendar')->warning()->send();

            return;
        }

        $name = null;
        try {
            foreach (app(MicrosoftCalendarProvider::class)->listCalendars($connection) as $row) {
                if ($row['id'] === $calendarId) {
                    $name = $row['name'];
                    break;
                }
            }
        } catch (\Throwable) {
        }

        $connection->forceFill([
            'calendar_id' => $calendarId,
            'calendar_name' => $name,
            'delta_link' => null,
        ])->save();

        RenewMicrosoftCalendarSubscriptions::dispatch();
        SyncMicrosoftCalendarChanges::dispatch($connection->getKey());

        Notification::make()->title('Calendar saved')->success()->send();
    }

    public function disconnect(): void
    {
        $connection = $this->connection();
        if ($connection === null) {
            return;
        }

        $provider = app(MicrosoftCalendarProvider::class);
        if (filled($connection->subscription_id)) {
            $provider->deleteSubscription($connection, (string) $connection->subscription_id);
        }

        $connection->forceFill([
            'status' => CalendarConnectionStatus::Disconnected,
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'subscription_id' => null,
            'subscription_expires_at' => null,
            'subscription_client_state' => null,
            'delta_link' => null,
            'last_error' => null,
        ])->save();

        Notification::make()->title('Outlook disconnected')->success()->send();
    }

    protected function connection(): ?CalendarConnection
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        return CalendarConnection::query()
            ->where('user_id', $user->getKey())
            ->where('provider', CalendarProvider::Microsoft)
            ->first();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
