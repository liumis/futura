<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdminModule;
use App\Filament\Admin\Concerns\ManagesInvoiceUploads;
use App\Filament\Admin\Concerns\PrefillsInvoiceFromOcr;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\ContactCountryResolver;
use App\Services\ContactMerger;
use App\Services\LithuanianCompanyNameNormalizer;
use App\Services\SharepointInvoiceUploader;
use App\Support\UploadLimits;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class NewInvoice extends Page implements HasForms
{
    use InteractsWithForms;
    use ManagesInvoiceUploads;
    use PrefillsInvoiceFromOcr;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'New invoice';

    protected static ?string $navigationLabel = 'New invoice';

    protected string $view = 'filament.admin.pages.new-invoice';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
            'create_contact_if_missing' => true,
            'currency' => Currency::defaultCode(),
            'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
            'paid_amount' => 0,
            'paid' => false,
            'private_provider' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function persistOcrPrefillToLivewire(array $updates): void
    {
        $this->data = array_merge($this->data ?? [], $updates);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('pdf_path')
                    ->label('Upload file')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices')
                    ->required()
                    ->helperText(UploadLimits::withExistingNote('PDF, JPG, or PNG. Readable files are scanned and used to prefill the form. Stored on SharePoint in invoices/{year}/{month}.'))
                    ->columnSpanFull()
                    ->extraAttributes([
                        'class' => 'invoice-fancy-upload',
                    ])
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $this->applyOcrPrefill($state, $set);
                    })
                    ->partiallyRenderAfterStateUpdated(false)
                    ->skipRenderAfterStateUpdated(false)
                    ->downloadable()
                    ->openable(),

                FileUpload::make('additional_documents')
                    ->label('Additional documents')
                    ->multiple()
                    ->acceptedFileTypes(self::additionalDocumentMimeTypes())
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices/additional')
                    ->storeFileNamesIn('additional_document_names')
                    ->helperText(UploadLimits::withExistingNote('Optional CMR for export sales (abroad / 0% VAT). Not needed for LT. Stored on SharePoint with the invoice.'))
                    ->columnSpanFull()
                    ->downloadable()
                    ->openable(),

                Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(fn (Get $get): bool => ! (bool) $get('create_contact_if_missing'))
                    ->helperText('Leave empty to create a contact from the OCR provider name.')
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $contact = Contact::query()->find($state);
                        $set('private_provider', (bool) ($contact?->private_provider));
                        $defaultCategory = $contact?->defaultFinanceTypeValue();
                        if ($defaultCategory !== null) {
                            $set('finance_type', $defaultCategory);
                        }
                    }),

                Checkbox::make('private_provider')
                    ->label('Private provider')
                    ->helperText('Included in GPM312. Copied from the contact when that contact is a private provider.')
                    ->inline(false),

                TextInput::make('provider_company_name')
                    ->label('Provider (OCR)')
                    ->helperText('Auto-detected from uploaded file. You can edit before confirming.')
                    ->maxLength(255),

                Select::make('create_contact_if_missing')
                    ->label('Missing provider handling')
                    ->options([
                        1 => 'Create new contact automatically',
                        0 => 'Do not create contact',
                    ])
                    ->default(1)
                    ->native(false)
                    ->live()
                    ->dehydrated(),

                TextInput::make('provider_company_id')
                    ->label('Provider company id')
                    ->maxLength(255),

                TextInput::make('provider_company_vat')
                    ->label('Provider VAT')
                    ->maxLength(255),

                TextInput::make('provider_company_email')
                    ->label('Provider email')
                    ->email()
                    ->maxLength(255),

                TextInput::make('provider_company_phone')
                    ->label('Provider phone')
                    ->tel()
                    ->maxLength(255),

                TextInput::make('provider_company_address')
                    ->label('Provider address')
                    ->maxLength(1000)
                    ->columnSpanFull(),

                TextInput::make('invoice_number')
                    ->label('Invoice no')
                    ->maxLength(255)
                    ->unique(Invoice::class, 'invoice_number')
                    ->validationMessages([
                        'unique' => 'This invoice number is already in the system.',
                    ]),

                DatePicker::make('invoice_date')
                    ->label('Invoice date')
                    ->required(),

                TextInput::make('sum_without_vat')
                    ->label('Sum without VAT')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required(),

                TextInput::make('vat')
                    ->label('VAT')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required(),

                TextInput::make('sum_inc_vat')
                    ->label('Sum inc. VAT')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        if (Invoice::amountsAreFullyPaid($get('paid_amount'), $state)) {
                            $set('paid', true);
                        }
                        $this->syncInvoiceEuroConversionFields($get, $set);
                    }),

                $this->invoiceCurrencyFormField(),

                ...$this->invoiceEuroConversionFormFields(),

                ...$this->invoicePaymentFormFields(),

                Select::make('finance_type')
                    ->label('Category')
                    ->options(fn (): array => Invoice::financeCategorySelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Saved as the provider default on the first invoice. Savo sąskaita is neither income nor expense.'),

                DatePicker::make('upload_date')
                    ->label('Upload date')
                    ->required(),

                Select::make('uploaded_by')
                    ->label('Uploaded user')
                    ->options(fn (): array => User::optionsByFullName())
                    ->default(fn (): ?int => auth()->id())
                    ->disabled()
                    ->dehydrated()
                    ->required(),
            ])
            ->columns(2);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToList')
                ->label('Back to invoices')
                ->color('gray')
                ->url(Invoices::getUrl()),
            Action::make('save')
                ->label('Upload')
                ->color('primary')
                ->action('create'),
        ];
    }

    public function create(): void
    {
        $existing = Invoice::findByNumber(data_get($this->data, 'invoice_number'));
        if ($existing !== null) {
            $this->notifyDuplicateInvoiceNumber($existing);

            return;
        }

        $state = $this->form->getState();
        $storedPath = (string) ($state['pdf_path'] ?? '');

        if (! SharepointSetting::instance()->isReady()) {
            Notification::make()
                ->title('SharePoint is not configured')
                ->body('Enable SharePoint under System → SharePoint. Invoice files are stored only on SharePoint.')
                ->danger()
                ->send();

            return;
        }

        if ($storedPath === '' || ! Storage::disk('public')->exists($storedPath)) {
            Notification::make()
                ->title('Uploaded file not found')
                ->danger()
                ->send();

            return;
        }

        $binary = Storage::disk('public')->get($storedPath);
        $mime = Storage::disk('public')->mimeType($storedPath) ?: 'application/octet-stream';
        $contactId = $state['contact_id'] ?? null;

        if ($contactId === null && (bool) ($state['create_contact_if_missing'] ?? true)) {
            $name = LithuanianCompanyNameNormalizer::normalize(trim((string) ($state['provider_company_name'] ?? '')));
            if ($name === '') {
                Notification::make()
                    ->title('Provider not detected')
                    ->body('Please select a company or provide provider name to create a new contact.')
                    ->warning()
                    ->send();

                return;
            }

            $existing = ContactMerger::matchExisting($name);
            if ($existing !== null) {
                $existingNorm = ContactMerger::normalizeName((string) $existing->company_name);
                $newNorm = ContactMerger::normalizeName($name);
                if (
                    $newNorm !== ''
                    && (str_starts_with($newNorm, $existingNorm) || strlen($newNorm) > strlen($existingNorm))
                    && mb_strlen($name) > mb_strlen((string) $existing->company_name)
                ) {
                    $existing->forceFill(['company_name' => $name])->save();
                }
                $contactId = $existing->getKey();
            } else {
                $contact = Contact::query()->create([
                    'company_name' => $name,
                    'company_id' => (string) ($state['provider_company_id'] ?? ('AUTO-'.Str::upper(Str::random(8)))),
                    'company_vat' => $state['provider_company_vat'] ?? null,
                    'company_address' => (string) ($state['provider_company_address'] ?? 'Auto-created from OCR'),
                    'company_email' => $state['provider_company_email'] ?? null,
                    'company_phone' => $state['provider_company_phone'] ?? null,
                    'private_provider' => (bool) ($state['private_provider'] ?? false),
                    'company_country' => ContactCountryResolver::infer(
                        name: $name,
                        companyId: (string) ($state['provider_company_id'] ?? ''),
                        vat: isset($state['provider_company_vat']) ? (string) $state['provider_company_vat'] : null,
                        email: isset($state['provider_company_email']) ? (string) $state['provider_company_email'] : null,
                        address: isset($state['provider_company_address']) ? (string) $state['provider_company_address'] : null,
                    ),
                ]);

                $contactId = $contact->getKey();
            }
        }

        if ($contactId === null) {
            Notification::make()
                ->title('Company is required')
                ->warning()
                ->send();

            return;
        }

        $invoiceNumber = filled($state['invoice_number'] ?? null) ? trim((string) $state['invoice_number']) : null;
        $existing = Invoice::findByNumber($invoiceNumber);
        if ($existing !== null) {
            $this->notifyDuplicateInvoiceNumber($existing);

            return;
        }

        $financeType = isset($state['finance_type']) && is_string($state['finance_type'])
            ? $state['finance_type']
            : null;
        if (! filled($financeType)) {
            $financeType = Contact::query()->whereKey($contactId)->value('default_finance_type');
        }
        $category = Invoice::parseFinanceTypeFilterValue(
            is_string($financeType) ? $financeType : null,
        );

        try {
            $invoice = Invoice::query()->create([
                'contact_id' => $contactId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $state['invoice_date'],
                'sum_without_vat' => $state['sum_without_vat'],
                'vat' => $state['vat'],
                'sum_inc_vat' => $state['sum_inc_vat'],
                'currency' => Currency::normalize(isset($state['currency']) && is_string($state['currency']) ? $state['currency'] : null),
                'payment_term' => Invoice::resolvePaymentTerm($state['payment_term'] ?? null),
                'paid_amount' => round((float) ($state['paid_amount'] ?? 0), 2),
                'paid' => (bool) ($state['paid'] ?? false),
                'private_provider' => (bool) ($state['private_provider'] ?? false),
                'income_type_id' => $category['income_type_id'],
                'expense_type_id' => $category['expense_type_id'],
                'is_own_account' => $category['is_own_account'],
                'upload_date' => $state['upload_date'],
                'uploaded_by' => $state['uploaded_by'] ?? auth()->id(),
                'pdf_path' => '',
                'file_name' => basename($storedPath),
                'file_mime' => $mime,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->notifyDuplicateInvoiceNumber(Invoice::findByNumber($invoiceNumber));

            return;
        }

        try {
            SharepointInvoiceUploader::ingestBinary($invoice, (string) $binary, basename($storedPath), $storedPath);
        } catch (Throwable $exception) {
            $invoice->delete();

            Notification::make()
                ->title('Invoice was not uploaded')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Contact::query()->find($contactId)?->rememberDefaultCategoryFromInvoice($invoice);

        $additionalCount = 0;
        $additionalError = null;
        try {
            $additionalCount = $this->storeAdditionalDocuments(
                $invoice,
                $state['additional_documents'] ?? [],
                $state['additional_document_names'] ?? [],
            );
        } catch (Throwable $exception) {
            $additionalError = $exception->getMessage();
        }

        if ($additionalError !== null) {
            Notification::make()
                ->title('Invoice uploaded, but additional documents were not saved')
                ->body($additionalError)
                ->warning()
                ->send();
        } else {
            Notification::make()
                ->title('Invoice uploaded')
                ->body($additionalCount > 0 ? $additionalCount.' additional document(s) uploaded.' : null)
                ->success()
                ->send();
        }

        $this->redirect(Invoices::getUrl(), navigate: true);
    }

    protected function notifyDuplicateInvoiceNumber(?Invoice $existing): void
    {
        $number = $existing?->displayNumber() ?? 'this number';

        Notification::make()
            ->title('Invoice already exists')
            ->body('Invoice '.$number.' is already in the system. It was not imported again.')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('openInvoices')
                    ->label('Open invoices')
                    ->url(Invoices::getUrl())
                    ->button(),
            ])
            ->send();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessModule(AdminModule::Finances) ?? false;
    }
}
