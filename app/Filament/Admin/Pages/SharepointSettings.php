<?php

namespace App\Filament\Admin\Pages;

use App\Models\SharepointSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class SharepointSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cloud';

    protected static ?string $navigationLabel = 'SharePoint';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'SharePoint';

    protected static ?string $slug = 'sharepoint-settings';

    protected string $view = 'filament.admin.pages.sharepoint-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = SharepointSetting::instance();

        $this->form->fill([
            'is_enabled' => $settings->is_enabled,
            'tenant_id' => $settings->tenant_id,
            'client_id' => $settings->client_id,
            'client_secret' => null,
            'site_url' => $settings->site_url,
            'site_id' => $settings->site_id,
            'drive_id' => $settings->drive_id,
            'document_library' => $settings->document_library,
            'root_folder_path' => $settings->root_folder_path,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Connection')
                    ->description('Enable SharePoint and enter Azure AD app credentials used for document integrations.')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Enable SharePoint integration')
                            ->helperText('When off, document sync to SharePoint stays disabled even if credentials are saved.'),

                        TextInput::make('tenant_id')
                            ->label('Directory (tenant) ID')
                            ->maxLength(255)
                            ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
                            ->helperText('From Microsoft Entra ID → Overview.'),

                        TextInput::make('client_id')
                            ->label('Application (client) ID')
                            ->maxLength(255)
                            ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'),

                        TextInput::make('client_secret')
                            ->label('Client secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->maxLength(2000)
                            ->placeholder(fn (): ?string => filled(SharepointSetting::instance()->client_secret)
                                ? '***************'
                                : null)
                            ->helperText(fn (): string => filled(SharepointSetting::instance()->client_secret)
                                ? 'Secret is already saved. Leave blank to keep it, or enter a new value to replace it.'
                                : 'Enter the client secret from Entra ID → App registrations → Certificates & secrets.')
                            ->dehydrated(fn (?string $state): bool => filled($state)
                                && $state !== '***************'),
                    ]),

                Section::make('Site & library')
                    ->description('Target site/library. New documents are stored as documents/{document type}/{file}.')
                    ->schema([
                        TextInput::make('site_url')
                            ->label('Site URL')
                            ->url()
                            ->maxLength(500)
                            ->placeholder('https://contoso.sharepoint.com/sites/Documents')
                            ->helperText('Full SharePoint site URL. Preferred when Site ID is unknown.'),

                        TextInput::make('site_id')
                            ->label('Site ID')
                            ->maxLength(255)
                            ->helperText('Optional Microsoft Graph site ID. Used when set.'),

                        TextInput::make('document_library')
                            ->label('Document library name')
                            ->maxLength(255)
                            ->placeholder('Documents')
                            ->helperText('Display name of the library (e.g. Documents).'),

                        TextInput::make('drive_id')
                            ->label('Drive ID')
                            ->maxLength(255)
                            ->helperText('Optional Graph drive ID. Overrides library name when set.'),

                        TextInput::make('root_folder_path')
                            ->label('Root folder path')
                            ->maxLength(500)
                            ->placeholder('/SS Documents')
                            ->helperText('Optional prefix inside the library. Final path: {root}/documents/{document type}/{file}.'),
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
        $settings = SharepointSetting::instance();

        $payload = [
            'is_enabled' => (bool) ($state['is_enabled'] ?? false),
            'tenant_id' => filled($state['tenant_id'] ?? null) ? trim((string) $state['tenant_id']) : null,
            'client_id' => filled($state['client_id'] ?? null) ? trim((string) $state['client_id']) : null,
            'site_url' => filled($state['site_url'] ?? null) ? rtrim(trim((string) $state['site_url']), '/') : null,
            'site_id' => filled($state['site_id'] ?? null) ? trim((string) $state['site_id']) : null,
            'drive_id' => filled($state['drive_id'] ?? null) ? trim((string) $state['drive_id']) : null,
            'document_library' => filled($state['document_library'] ?? null) ? trim((string) $state['document_library']) : null,
            'root_folder_path' => filled($state['root_folder_path'] ?? null) ? trim((string) $state['root_folder_path']) : null,
        ];

        if (array_key_exists('client_secret', $state) && filled($state['client_secret'])) {
            $payload['client_secret'] = (string) $state['client_secret'];
        }

        $settings->update($payload);

        $this->form->fill([
            'is_enabled' => $settings->is_enabled,
            'tenant_id' => $settings->tenant_id,
            'client_id' => $settings->client_id,
            'client_secret' => null,
            'site_url' => $settings->site_url,
            'site_id' => $settings->site_id,
            'drive_id' => $settings->drive_id,
            'document_library' => $settings->document_library,
            'root_folder_path' => $settings->root_folder_path,
        ]);

        Notification::make()
            ->title('SharePoint settings saved')
            ->success()
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
