<?php

namespace App\Filament\Admin\Pages;

use App\Models\DokobitSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use UnitEnum;

class DokobitSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Dokobit';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Dokobit';

    protected static ?string $slug = 'dokobit-settings';

    protected string $view = 'filament.admin.pages.dokobit-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = DokobitSetting::instance();

        $this->form->fill([
            'active_environment' => $settings->active_environment ?: DokobitSetting::ENVIRONMENT_LIVE,
            'live_access_token' => null,
            'live_api_url' => $settings->live_api_url ?: DokobitSetting::DEFAULT_LIVE_API_URL,
            'prod_access_token' => null,
            'prod_api_url' => $settings->prod_api_url ?: DokobitSetting::DEFAULT_PROD_API_URL,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Active environment')
                    ->description('Choose which Dokobit credentials the system uses for signing and API calls.')
                    ->schema([
                        Select::make('active_environment')
                            ->label('Environment')
                            ->options([
                                DokobitSetting::ENVIRONMENT_LIVE => 'Sandbox',
                                DokobitSetting::ENVIRONMENT_PROD => 'Production',
                            ])
                            ->required()
                            ->native(false)
                            ->helperText('Sandbox rejects real Mobile-ID / Smart-ID credentials. Use Dokobit test numbers only (see note below).'),
                    ]),

                Section::make('Sandbox testing')
                    ->description('Sandbox cannot verify real personal codes or phone numbers. Use Dokobit test identities, or a Smart-ID DEMO app account.')
                    ->schema([
                        Forms\Components\Placeholder::make('sandbox_test_data')
                            ->label('Example test identities')
                            ->content(new HtmlString(
                                '<ul class="list-disc pl-5 space-y-1 text-sm">'
                                .'<li><strong>Mobile-ID:</strong> phone <code>+37200000766</code>, personal code <code>60001019906</code></li>'
                                .'<li><strong>Smart-ID (LT):</strong> personal code <code>30303039914</code>, country <code>LT</code></li>'
                                .'<li>Full list: <a class="underline" href="https://dokobitbysignicat.zendesk.com/hc/en-us/articles/20067528968476-Mobile-ID-and-Smart-ID-test-data" target="_blank" rel="noopener">Dokobit Mobile-ID / Smart-ID test data</a></li>'
                                .'<li>For production signing with real IDs, switch Environment to <strong>Production</strong> and use a production access token.</li>'
                                .'</ul>'
                            )),
                    ]),

                Section::make('Sandbox')
                    ->description('Sandbox / test credentials from dokobit.com (gateway-sandbox).')
                    ->schema([
                        TextInput::make('live_access_token')
                            ->label('Access token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(2000)
                            ->helperText('Leave blank to keep the current token.')
                            ->dehydrated(fn (?string $state): bool => filled($state)),

                        TextInput::make('live_api_url')
                            ->label('API base URL')
                            ->url()
                            ->maxLength(255)
                            ->placeholder(DokobitSetting::DEFAULT_LIVE_API_URL)
                            ->helperText('Default: '.DokobitSetting::DEFAULT_LIVE_API_URL),
                    ]),

                Section::make('Production')
                    ->description('Live production credentials from dokobit.com.')
                    ->schema([
                        TextInput::make('prod_access_token')
                            ->label('Access token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(2000)
                            ->helperText('Leave blank to keep the current token.')
                            ->dehydrated(fn (?string $state): bool => filled($state)),

                        TextInput::make('prod_api_url')
                            ->label('API base URL')
                            ->url()
                            ->maxLength(255)
                            ->placeholder(DokobitSetting::DEFAULT_PROD_API_URL)
                            ->helperText('Default: '.DokobitSetting::DEFAULT_PROD_API_URL),
                    ]),
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
        $settings = DokobitSetting::instance();

        $payload = [
            'active_environment' => ($state['active_environment'] ?? DokobitSetting::ENVIRONMENT_LIVE) === DokobitSetting::ENVIRONMENT_PROD
                ? DokobitSetting::ENVIRONMENT_PROD
                : DokobitSetting::ENVIRONMENT_LIVE,
            'live_api_url' => filled($state['live_api_url'] ?? null)
                ? rtrim((string) $state['live_api_url'], '/')
                : DokobitSetting::DEFAULT_LIVE_API_URL,
            'prod_api_url' => filled($state['prod_api_url'] ?? null)
                ? rtrim((string) $state['prod_api_url'], '/')
                : DokobitSetting::DEFAULT_PROD_API_URL,
        ];

        if (array_key_exists('live_access_token', $state) && filled($state['live_access_token'])) {
            $payload['live_access_token'] = (string) $state['live_access_token'];
        }

        if (array_key_exists('prod_access_token', $state) && filled($state['prod_access_token'])) {
            $payload['prod_access_token'] = (string) $state['prod_access_token'];
        }

        $settings->update($payload);

        Notification::make()
            ->title('Dokobit settings saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
