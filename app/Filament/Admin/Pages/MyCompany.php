<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\CompanyProfileSchema;
use App\Models\CompanySetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use UnitEnum;

class MyCompany extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'My company';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'My company';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $company = CompanySetting::instance();

        $this->form->fill($company->only([
            'company_name',
            'company_country',
            'company_id',
            'company_vat',
            'company_address',
            'company_email',
            'company_phone',
            'company_iban',
            'company_bic',
            'contact_name',
            'contact_email',
            'contact_phone',
        ]));
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return CompanyProfileSchema::configure($schema);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $company = CompanySetting::query()->firstOrCreate([]);

        $company->update([
            'company_name' => $data['company_name'] ?? null,
            'company_country' => $data['company_country'] ?? null,
            'company_id' => $data['company_id'] ?? null,
            'company_vat' => $data['company_vat'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'company_email' => filled($data['company_email'] ?? null) ? $data['company_email'] : null,
            'company_phone' => $data['company_phone'] ?? null,
            'company_iban' => filled($data['company_iban'] ?? null) ? $data['company_iban'] : null,
            'company_bic' => filled($data['company_bic'] ?? null) ? $data['company_bic'] : null,
            'contact_name' => $data['contact_name'] ?? null,
            'contact_email' => filled($data['contact_email'] ?? null) ? $data['contact_email'] : null,
            'contact_phone' => $data['contact_phone'] ?? null,
        ]);

        try {
            $company->fresh()->syncContact();
        } catch (\Throwable) {
            // Contact sync is optional until My company is complete.
        }

        Notification::make()
            ->title('Company details saved')
            ->success()
            ->send();

        $this->fillForm();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    protected function hasFullWidthFormActions(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
