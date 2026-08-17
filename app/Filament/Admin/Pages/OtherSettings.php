<?php

namespace App\Filament\Admin\Pages;

use App\Models\SystemSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use UnitEnum;

class OtherSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Other';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Other';

    protected string $view = 'filament.admin.pages.other-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = SystemSetting::instance();

        $this->form->fill([
            'email_test_mode' => $settings->email_test_mode,
            'low_stock_alert_limit' => $settings->low_stock_alert_limit,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Toggle::make('email_test_mode')
                    ->label('Email test mode')
                    ->helperText('When enabled, no emails are sent anywhere in the system. Use only for testing.'),

                TextInput::make('low_stock_alert_limit')
                    ->label('Low stock alert limit')
                    ->helperText('Products appear in Reports → Low stock when current stock (size × quantity, in meters) is below this value.')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.001)
                    ->suffix('m'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->color('primary')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = SystemSetting::instance();

        $settings->update([
            'email_test_mode' => (bool) ($state['email_test_mode'] ?? false),
            'low_stock_alert_limit' => max(0, (float) ($state['low_stock_alert_limit'] ?? 0)),
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
