<?php

namespace App\Filament\Admin\Pages;

use App\Enums\CompanyBankAccountType;
use App\Exceptions\BankStatementDuplicateException;
use App\Exceptions\BankStatementXmlException;
use App\Filament\Admin\Support\CompanyProfileSchema;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use App\Models\SharepointSetting;
use App\Services\BankStatementDocumentGenerator;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\BankStatements\BankStatementPaymentMatcher;
use App\Services\CompanyBankAccountSyncer;
use App\Services\SharepointBankStatementUploader;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class MyCompany extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'My company';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'My company';

    protected static ?string $slug = 'my-company';

    protected string $view = 'filament.admin.pages.my-company';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public bool $showCreditCards = true;

    public function mount(): void
    {
        app(CompanyBankAccountSyncer::class)->syncFromKnownSources();
        $this->fillForm();
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return ['form'];
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

    public function form(Schema $schema): Schema
    {
        return CompanyProfileSchema::configure($schema)->statePath('data');
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

        if (filled($company->company_iban)) {
            app(CompanyBankAccountSyncer::class)->ensureAccount(
                (string) $company->company_iban,
                $company->company_bic,
                null,
                CompanyBankAccountType::Other,
                isPrimary: true,
            );
        }

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

    /**
     * @return Collection<string, Collection<int, CompanyBankAccount>>
     */
    public function bankAccountGroups(): Collection
    {
        return app(CompanyBankAccountSyncer::class)->bankAccountsGrouped();
    }

    /**
     * @return Collection<int, CompanyBankAccount>
     */
    public function creditCardAccounts(): Collection
    {
        return app(CompanyBankAccountSyncer::class)->creditCardAccounts();
    }

    public function toggleCreditCards(): void
    {
        $this->showCreditCards = ! $this->showCreditCards;
    }

    public function addBankAccountAction(): Action
    {
        return Action::make('addBankAccount')
            ->label('Add bank account')
            ->icon('heroicon-o-plus')
            ->modalHeading('Add bank account')
            ->modalSubmitActionLabel('Save')
            ->schema($this->bankAccountFormSchema())
            ->action(function (array $data): void {
                $this->storeBankAccount($data);
                Notification::make()->title('Bank account added')->success()->send();
            });
    }

    public function editBankAccountAction(): Action
    {
        return Action::make('editBankAccount')
            ->label('Edit')
            ->modalHeading('Edit bank account')
            ->modalSubmitActionLabel('Save')
            ->schema($this->bankAccountFormSchema())
            ->fillForm(function (array $arguments): array {
                $account = CompanyBankAccount::query()->findOrFail((int) ($arguments['accountId'] ?? 0));

                return [
                    'iban' => $account->iban,
                    'bic' => $account->bic,
                    'bank_name' => $account->bank_name,
                    'name' => $account->name,
                    'type' => $account->type?->value,
                    'credit_limit' => $account->credit_limit,
                    'is_primary' => $account->is_primary,
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $account = CompanyBankAccount::query()->findOrFail((int) ($arguments['accountId'] ?? 0));
                $this->storeBankAccount($data, $account);
                Notification::make()->title('Bank account updated')->success()->send();
            });
    }

    public function deleteBankAccountAction(): Action
    {
        return Action::make('deleteBankAccount')
            ->label('Delete')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Delete bank account?')
            ->action(function (array $arguments): void {
                $account = CompanyBankAccount::query()->findOrFail((int) ($arguments['accountId'] ?? 0));
                $account->delete();
                CompanyBankAccount::ownAccountIdentifierMap(fresh: true);
                Notification::make()->title('Bank account deleted')->success()->send();
            });
    }

    public function importBankDataAction(): Action
    {
        return Action::make('importBankData')
            ->label('Import data')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->modalHeading('Import bank statement data')
            ->modalSubmitActionLabel('Import')
            ->modalWidth('xl')
            ->schema([
                FileUpload::make('file_paths')
                    ->label('Bank statement files')
                    ->helperText(UploadLimits::withExistingNote('ISO 20022 XML, accounting Excel (.xls / .xlsx), or bank PDF statements (e.g. SEB). Duplicate files and lines already in the system are not imported. Stored on SharePoint under bank-statements/{year}/{month}.'))
                    ->acceptedFileTypes([
                        'application/xml',
                        'text/xml',
                        'text/plain',
                        'application/octet-stream',
                        'application/pdf',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        '.xml',
                        '.xls',
                        '.xlsx',
                        '.pdf',
                    ])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->multiple()
                    ->disk('local')
                    ->directory('bank-statements')
                    ->visibility('private')
                    ->required()
                    ->columnSpanFull(),

                Textarea::make('comment')
                    ->label('Import comment')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                $this->importBankFiles($data);
            });
    }

    public function syncAccountsFromStatementsAction(): Action
    {
        return Action::make('syncAccountsFromStatements')
            ->label('Sync from statements')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->action(function (): void {
                $created = app(CompanyBankAccountSyncer::class)->syncFromKnownSources();
                Notification::make()
                    ->title($created > 0 ? "Added {$created} account(s)" : 'Accounts already up to date')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, mixed>
     */
    private function bankAccountFormSchema(): array
    {
        return [
            TextInput::make('iban')
                ->label('IBAN')
                ->required()
                ->maxLength(34)
                ->dehydrateStateUsing(fn (?string $state): ?string => CompanyBankAccount::normalizeIban($state)),

            TextInput::make('bic')
                ->label('BIC')
                ->maxLength(11)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                    ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                    : null),

            TextInput::make('bank_name')
                ->label('Bank')
                ->maxLength(255)
                ->placeholder('SEB'),

            TextInput::make('name')
                ->label('Label')
                ->maxLength(255)
                ->placeholder('Expense / Income / Credit card account'),

            Select::make('type')
                ->label('Type')
                ->options(CompanyBankAccountType::options())
                ->required()
                ->native(false)
                ->live(),

            TextInput::make('credit_limit')
                ->label('Credit limit')
                ->numeric()
                ->minValue(0)
                ->visible(fn ($get): bool => ($get('type') ?? null) === CompanyBankAccountType::CreditCard->value),

            Toggle::make('is_primary')
                ->label('Primary SEPA payer account')
                ->helperText('Used for salary exports and shown as Company IBAN.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeBankAccount(array $data, ?CompanyBankAccount $account = null): void
    {
        $iban = CompanyBankAccount::normalizeIban($data['iban'] ?? null);
        if ($iban === null) {
            throw new \RuntimeException('IBAN is required.');
        }

        $type = CompanyBankAccountType::tryFrom((string) ($data['type'] ?? '')) ?? CompanyBankAccountType::Other;
        $isPrimary = (bool) ($data['is_primary'] ?? false);

        if ($isPrimary) {
            CompanyBankAccount::query()
                ->when($account !== null, fn ($q) => $q->whereKeyNot($account->getKey()))
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $payload = [
            'iban' => $iban,
            'bic' => filled($data['bic'] ?? null) ? (string) $data['bic'] : null,
            'bank_name' => filled($data['bank_name'] ?? null)
                ? trim((string) $data['bank_name'])
                : CompanyBankAccount::bankNameFromIban($iban),
            'name' => filled($data['name'] ?? null) ? trim((string) $data['name']) : null,
            'type' => $type,
            'credit_limit' => $type === CompanyBankAccountType::CreditCard
                ? (filled($data['credit_limit'] ?? null) ? (float) $data['credit_limit'] : 0)
                : null,
            'is_primary' => $isPrimary,
            'is_active' => true,
        ];

        if ($account === null) {
            $payload['sort_order'] = ((int) CompanyBankAccount::query()->max('sort_order')) + 1;
            $account = CompanyBankAccount::query()->create($payload);
        } else {
            $account->update($payload);
        }

        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);

        if ($isPrimary) {
            app(CompanyBankAccountSyncer::class)->syncPrimaryToCompanySettings($iban, $payload['bic']);
            $this->fillForm();
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function importBankFiles(array $state): void
    {
        $paths = $state['file_paths'] ?? [];
        if ($paths instanceof Collection) {
            $paths = $paths->all();
        }
        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths) || $paths === []) {
            Notification::make()->title('No file selected')->warning()->send();

            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            Notification::make()
                ->title('SharePoint is not configured')
                ->body('Enable SharePoint under System → SharePoint before importing bank statements.')
                ->danger()
                ->send();

            return;
        }

        $importer = app(BankStatementImporter::class);
        $imported = 0;
        $skipped = 0;
        $files = 0;
        $errors = [];
        $duplicates = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
                if (is_string($path) && $path !== '') {
                    $errors[] = basename($path).': uploaded file not found.';
                }

                continue;
            }

            $absolutePath = Storage::disk('local')->path($path);
            $binary = (string) Storage::disk('local')->get($path);

            try {
                $result = $importer->importFile(
                    $absolutePath,
                    basename($path),
                    $path,
                    auth()->id(),
                    isset($state['comment']) ? (string) $state['comment'] : null,
                );
            } catch (BankStatementDuplicateException $exception) {
                $duplicates[] = basename($path).': '.$exception->getMessage();

                continue;
            } catch (BankStatementXmlException $exception) {
                $errors[] = basename($path).': '.$exception->getMessage();

                continue;
            }

            try {
                SharepointBankStatementUploader::ingestLocalFile($result['import'], $binary, $path);
            } catch (\Throwable $exception) {
                $errors[] = basename($path).': lines were saved, but SharePoint upload failed: '.$exception->getMessage();
                $files++;
                $imported += $result['imported'];
                $skipped += $result['skipped'];

                continue;
            }

            try {
                BankStatementDocumentGenerator::syncForImport(
                    $result['import']->fresh() ?? $result['import'],
                    auth()->user(),
                );
            } catch (\Throwable $exception) {
                $errors[] = basename($path).': stored on SharePoint, but Documents entry failed: '.$exception->getMessage();
            }

            $files++;
            $imported += $result['imported'];
            $skipped += $result['skipped'];
        }

        app(CompanyBankAccountSyncer::class)->syncFromKnownSources();

        if ($files > 0) {
            $body = $imported.' new line(s) stored';
            if ($skipped > 0) {
                $body .= ', '.$skipped.' already in the system';
            }
            $body .= ' from '.$files.' file(s).';

            $unlinked = BankStatementTransaction::query()
                ->whereNull('invoice_id')
                ->whereNull('payment_id')
                ->get();
            $suggestedInvoices = BankStatementInvoiceMatcher::suggestFor($unlinked)->count();
            $suggestedPayments = BankStatementPaymentMatcher::suggestFor($unlinked)->count();
            if ($suggestedInvoices > 0) {
                $body .= ' '.$suggestedInvoices.' invoice suggestion(s).';
            }
            if ($suggestedPayments > 0) {
                $body .= ' '.$suggestedPayments.' contract suggestion(s).';
            }

            Notification::make()
                ->title('Bank data imported')
                ->body($body)
                ->success()
                ->actions([
                    NotificationAction::make('open_statements')
                        ->label('Open bank statements')
                        ->url(BankStatements::getUrl()),
                ])
                ->send();
        }

        foreach ($duplicates as $duplicate) {
            Notification::make()
                ->title('Duplicate bank statement')
                ->body($duplicate)
                ->warning()
                ->send();
        }

        foreach ($errors as $error) {
            Notification::make()
                ->title('Import failed')
                ->body($error)
                ->danger()
                ->send();
        }
    }

    public function statementsUrlForAccount(CompanyBankAccount $account): string
    {
        return BankStatements::getUrl(['filters' => ['bank_account' => $account->iban]]);
    }

    public function formatMoney(float|int|string|null $amount): string
    {
        return Money::format((float) ($amount ?? 0));
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
