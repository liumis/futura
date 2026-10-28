<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementDuplicateException;
use App\Exceptions\BankStatementXmlException;
use App\Filament\Admin\Concerns\PrefillsInvoiceFromOcr;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Document;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeePaymentReport;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BankStatementDocumentGenerator;
use App\Services\BankStatements\BankStatementBusinessTripMatcher;
use App\Services\BankStatements\BankStatementDirectionResolver;
use App\Services\BankStatements\BankStatementDocumentMatcher;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BankStatements\BankStatementInvoiceCreator;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\BankStatements\BankStatementMissingDocumentsPdfGenerator;
use App\Services\BankStatements\BankStatementPaymentMatcher;
use App\Services\BankStatements\BankStatementSalaryMatcher;
use App\Models\BusinessTripRequest;
use App\Services\SharepointBankStatementUploader;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Renderless;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class BankStatements extends Page implements HasForms
{
    use InteractsWithForms;
    use PrefillsInvoiceFromOcr;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Bank statements';

    protected static ?string $title = 'Bank statements';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'bank-statements';

    protected string $view = 'filament.admin.pages.bank-statements';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $filters = [];

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'form',
            'filtersForm',
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanSuggestedLinks')
                ->label('Scan for links')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('primary')
                ->tooltip('Find invoice, contract, and salary (DU) matches for unlinked bank payments')
                ->action(fn () => $this->scanSuggestedLinks()),

            Action::make('confirmAllSuggestedLinks')
                ->label('Confirm all suggested')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->tooltip('Link every currently suggested invoice, contract, or salary payment')
                ->requiresConfirmation()
                ->modalHeading('Confirm all suggested links')
                ->modalDescription('This will link every bank payment that currently has a suggested invoice, contract, or salary payslip (respecting active filters).')
                ->modalSubmitActionLabel('Confirm all')
                ->action(fn () => $this->confirmAllSuggestedLinks()),

            Action::make('exportMissingExcel')
                ->label('Export missing (Excel)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportMissingDocumentsExcel()),

            Action::make('exportMissingPdf')
                ->label('Export missing (PDF)')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->action(function () {
                    $transactions = $this->missingDocumentTransactions();
                    $fileName = 'bank-statements-missing-'.now()->format('Ymd-His').'.pdf';

                    ActivityLogger::logReportDownloaded('Bank statements missing documents PDF', 'pdf', properties: [
                        'file_name' => $fileName,
                        'count' => $transactions->count(),
                    ]);

                    return response()->streamDownload(
                        function () use ($transactions): void {
                            echo BankStatementMissingDocumentsPdfGenerator::generate($transactions);
                        },
                        $fileName,
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }

    public function mount(): void
    {
        $this->form->fill([]);
        // Month starts empty in the UI; list soft-defaults to the latest month until
        // the user picks a month or widens via any other filter.
        $this->filtersForm->fill([]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Grid::make(12)
                    ->schema([
                        Select::make('counterpart')
                            ->label('Counterpart')
                            ->options(fn (): array => $this->counterpartOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->counterpartOptions($search))
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (string) $value : null)
                            ->searchable()
                            ->preload()
                            ->placeholder('All counterparts')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 12, 'md' => 4]),

                        TextInput::make('description')
                            ->label('Search')
                            ->placeholder('Payment details, invoice no…')
                            ->live(debounce: 400)
                            ->columnSpan(['default' => 12, 'md' => 5]),

                        Select::make('month')
                            ->label('Month')
                            ->options(fn (): array => $this->monthFilterOptions())
                            ->placeholder('This month')
                            ->native(false)
                            ->live()
                            ->helperText('Empty = this month. Any other filter without a month looks across all months.')
                            ->columnSpan(['default' => 12, 'md' => 3]),
                    ]),

                Grid::make(12)
                    ->schema([
                        Select::make('bank_account')
                            ->label('Bank account')
                            ->options(fn (): array => $this->bankAccountOptions())
                            ->getSearchResultsUsing(fn (string $search): array => $this->bankAccountOptions($search))
                            ->getOptionLabelUsing(function ($value): ?string {
                                if (! filled($value)) {
                                    return null;
                                }

                                return $this->bankAccountOptions()[(string) $value] ?? (string) $value;
                            })
                            ->searchable()
                            ->preload()
                            ->placeholder('All accounts')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 12, 'md' => 4]),

                        Select::make('direction')
                            ->label('Direction')
                            ->options([
                                BankStatementDirection::Credit->value => BankStatementDirection::Credit->label(),
                                BankStatementDirection::Debit->value => BankStatementDirection::Debit->label(),
                                'own' => 'Between own accounts',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 4]),

                        Select::make('link_status')
                            ->label('Document')
                            ->options([
                                'suggested' => 'Suggested matches',
                                'linked' => 'Linked to document',
                                'no_document' => 'Own accounts / bank fee / no document / historical',
                                'historical' => 'Historical data',
                                'missing' => 'Needs document',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 4]),
                    ]),

                Grid::make(12)
                    ->schema([
                        Select::make('payment_status')
                            ->label('Payment')
                            ->options([
                                'paid' => 'Paid',
                                'partly_paid' => 'Partly paid',
                                'overpaid' => 'Overpayment',
                                'settled' => 'Settled',
                                'underpaid' => 'Sum mismatch',
                                'unpaid' => 'Not paid',
                                'refund' => 'Refund',
                                'refunded' => 'Refunded',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 4]),

                        Select::make('comment')
                            ->label('Comment')
                            ->options([
                                'with' => 'With comment',
                                'without' => 'No comment',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 4]),
                    ]),
            ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('file_paths')
                    ->label('Upload bank statements')
                    ->helperText(UploadLimits::withExistingNote('ISO 20022 XML (camt.053 / camt.052), accounting Excel (.xls / .xlsx, all sheets), or bank PDF statements (e.g. SEB sąskaitos išrašas — multi-account PDFs are split by account). Long scanned PDFs may hit the OCR page limit; if import warns about missing pages, upload one account (or fewer pages) per file. Duplicate files and lines already in the system are not imported. Files are stored on SharePoint and registered as Documents (Banko išrašas). Multiple files are merged into the matching month.'))
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
                    ->label('Comment')
                    ->rows(2)
                    ->maxLength(1000)
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }

    public function import(): void
    {
        $state = $this->form->getState();
        $paths = $state['file_paths'] ?? $state['xml_paths'] ?? [];

        if (is_string($paths)) {
            $paths = [$paths];
        }

        if (! is_array($paths) || $paths === []) {
            Notification::make()
                ->title('No file selected')
                ->warning()
                ->send();

            return;
        }

        if (! SharepointSetting::instance()->isReady()) {
            Notification::make()
                ->title('SharePoint is not configured')
                ->body('Enable SharePoint under System → SharePoint. Bank statements are stored only in the bank-statements folder there.')
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
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (! Storage::disk('local')->exists($path)) {
                $errors[] = basename($path).': uploaded file not found.';

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

        if ($files > 0) {
            $body = $imported.' new line(s) stored';
            if ($skipped > 0) {
                $body .= ', '.$skipped.' already in the system';
            }
            $body .= ' from '.$files.' file(s).';
            $unlinked = BankStatementTransaction::query()
                ->withoutLinkedDocument()
                ->get();
            $suggestedInvoices = BankStatementInvoiceMatcher::suggestFor($unlinked)->count();
            $suggestedPayments = BankStatementPaymentMatcher::suggestFor($unlinked)->count();
            $suggestedSalaries = BankStatementSalaryMatcher::suggestFor($unlinked)->count();
            if ($suggestedInvoices > 0) {
                $body .= ' '.$suggestedInvoices.' existing invoice(s) can be confirmed.';
            }
            if ($suggestedPayments > 0) {
                $body .= ' '.$suggestedPayments.' existing contract(s) can be confirmed.';
            }
            if ($suggestedSalaries > 0) {
                $body .= ' '.$suggestedSalaries.' salary payment(s) can be confirmed.';
            }

            Notification::make()
                ->title('Bank statement imported')
                ->body($body)
                ->success()
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
                ->title('Bank statement could not be imported')
                ->body($error)
                ->danger()
                ->send();
        }

        $this->form->fill([]);
    }

    #[Renderless]
    public function updateCounterpart(int $transactionId, ?string $value): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $counterpart = filled($value) ? trim($value) : null;

        if ((string) ($transaction->counterpart_name ?? '') === (string) ($counterpart ?? '')) {
            return;
        }

        $fingerprint = $this->fingerprintForCounterpartChange($transaction, $counterpart);

        $transaction->forceFill([
            'counterpart_name' => $counterpart,
            'fingerprint' => $fingerprint,
        ])->save();
    }

    #[Renderless]
    public function updateDescription(int $transactionId, ?string $value): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $description = filled($value) ? trim($value) : null;

        if ((string) ($transaction->description ?? '') === (string) ($description ?? '')) {
            return;
        }

        $transaction->update([
            'description' => $description,
        ]);
    }

    #[Renderless]
    public function updateComment(int $transactionId, ?string $value): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $comment = filled($value) ? trim($value) : null;

        if ((string) ($transaction->comment ?? '') === (string) ($comment ?? '')) {
            return;
        }

        $transaction->update([
            'comment' => $comment,
        ]);
    }

    public function updateDirection(int $transactionId, string $direction): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $next = BankStatementDirection::tryFrom($direction);

        if ($next === null || $transaction->direction === $next) {
            return;
        }

        $fingerprint = $this->fingerprintForCounterpartChange(
            $transaction->forceFill(['direction' => $next]),
            $transaction->counterpart_name,
        );

        // forceFill above mutated in-memory direction for fingerprint; persist both.
        $transaction->forceFill([
            'direction' => $next,
            'fingerprint' => $fingerprint,
        ])->save();
    }

    public function updateFinanceCategory(int $invoiceId, ?string $value): void
    {
        Invoice::query()->findOrFail($invoiceId)->applyFinanceCategory($value);
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function financeCategorySelectOptions(): array
    {
        return Invoice::financeCategorySelectOptions();
    }

    private function fingerprintForCounterpartChange(BankStatementTransaction $transaction, ?string $counterpart): string
    {
        $payload = [
            'counterpart_name' => $counterpart,
            'direction' => $transaction->direction,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'account_iban' => $transaction->account_iban,
            'booking_date' => optional($transaction->booking_date)?->toDateString() ?? (string) $transaction->booking_date,
            'account_servicer_ref' => $transaction->account_servicer_ref,
            'end_to_end_id' => $transaction->end_to_end_id,
            'transaction_id' => $transaction->transaction_id,
            'counterpart_iban' => $transaction->counterpart_iban,
        ];

        $newFingerprint = app(BankStatementImporter::class)->fingerprint($payload);

        if ($newFingerprint === $transaction->fingerprint) {
            return $transaction->fingerprint;
        }

        $collision = BankStatementTransaction::query()
            ->where('fingerprint', $newFingerprint)
            ->whereKeyNot($transaction->getKey())
            ->exists();

        return $collision ? $transaction->fingerprint : $newFingerprint;
    }

    public function createInvoiceAction(): Action
    {
        return Action::make('createInvoice')
            ->modalHeading(function (array $arguments): string {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $label = trim(implode(' · ', array_filter([
                    optional($transaction->booking_date)->format('Y-m-d'),
                    $transaction->counterpart_name,
                    Money::format($transaction->amount),
                ])));

                return $label !== '' ? 'Create invoice — '.$label : 'Create invoice';
            })
            ->modalSubmitActionLabel('Create invoice')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('4xl')
            ->schema([
                Hidden::make('is_income'),
                Hidden::make('is_own_account'),

                FileUpload::make('pdf_path')
                    ->label('Invoice file')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices')
                    ->required()
                    ->helperText(UploadLimits::withExistingNote('PDF, JPG, or PNG. Readable files are scanned and used to prefill the form. Stored on SharePoint in invoices/{year}/{month}.'))
                    ->columnSpanFull()
                    ->live()
                    ->afterStateUpdated(function (mixed $state, callable $set): void {
                        $this->applyOcrPrefill($state, $set);
                    })
                    ->downloadable()
                    ->openable(),

                Grid::make(2)->schema([
                    Select::make('contact_id')
                        ->label('Company')
                        ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                            $contact = Contact::query()->find($state);
                            $parsed = Invoice::parseFinanceTypeFilterValue($contact?->defaultFinanceTypeValue());
                            if (filter_var($get('is_own_account'), FILTER_VALIDATE_BOOLEAN)) {
                                return;
                            }
                            if (filter_var($get('is_income'), FILTER_VALIDATE_BOOLEAN)) {
                                if ($parsed['income_type_id'] !== null) {
                                    $set('income_type_id', $parsed['income_type_id']);
                                }

                                return;
                            }
                            if ($parsed['expense_type_id'] !== null) {
                                $set('expense_type_id', $parsed['expense_type_id']);
                            }
                        }),

                    TextInput::make('provider_company_name')
                        ->label('Provider / company name')
                        ->maxLength(255),

                    Select::make('create_contact_if_missing')
                        ->label('Missing company handling')
                        ->options([
                            1 => 'Create new contact automatically',
                            0 => 'Do not create contact',
                        ])
                        ->native(false)
                        ->dehydrated(),

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
                        ->required(),

                    Select::make('currency')
                        ->label('Currency')
                        ->options(fn (Get $get): array => Currency::selectOptions(
                            is_string($get('currency') ?? null) ? (string) $get('currency') : null,
                        ))
                        ->default(Currency::defaultCode())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->native(false),

                    Select::make('income_type_id')
                        ->label('Income type')
                        ->options(fn (): array => IncomeType::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(fn (Get $get): bool => ! filter_var($get('is_own_account'), FILTER_VALIDATE_BOOLEAN)
                            && filter_var($get('is_income'), FILTER_VALIDATE_BOOLEAN)),

                    Select::make('expense_type_id')
                        ->label('Expense type')
                        ->options(fn (): array => ExpenseType::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->visible(fn (Get $get): bool => ! filter_var($get('is_own_account'), FILTER_VALIDATE_BOOLEAN)
                            && ! filter_var($get('is_income'), FILTER_VALIDATE_BOOLEAN)),

                    Placeholder::make('own_account_category')
                        ->label('Category')
                        ->content(Invoice::OWN_ACCOUNT_LABEL.' (neither income nor expense)')
                        ->visible(fn (Get $get): bool => filter_var($get('is_own_account'), FILTER_VALIDATE_BOOLEAN)),

                    TextInput::make('provider_company_id')
                        ->label('Company id')
                        ->maxLength(255),

                    TextInput::make('provider_company_vat')
                        ->label('VAT code')
                        ->maxLength(255),

                    TextInput::make('provider_company_email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),

                    TextInput::make('provider_company_phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(255),

                    TextInput::make('provider_company_address')
                        ->label('Address')
                        ->maxLength(1000)
                        ->columnSpanFull(),

                    DatePicker::make('upload_date')
                        ->label('Upload date')
                        ->required(),

                    Select::make('uploaded_by')
                        ->label('Uploaded user')
                        ->options(fn (): array => User::optionsByFullName())
                        ->disabled()
                        ->dehydrated()
                        ->required(),
                ]),
            ])
            ->fillForm(function (array $arguments): array {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);

                return BankStatementInvoiceCreator::prefill($transaction);
            })
            ->action(function (array $data, array $arguments): void {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $this->clearConflictingDocumentLinks($transaction, keep: 'invoice');
                $transaction = $transaction->fresh() ?? $transaction;

                try {
                    $invoice = app(BankStatementInvoiceCreator::class)->create(
                        $transaction,
                        $data,
                        (int) (auth()->id() ?? 0),
                    );
                } catch (\Throwable $exception) {
                    $isDuplicate = str_contains(strtolower($exception->getMessage()), 'already exists');

                    $notification = Notification::make()
                        ->title($isDuplicate ? 'Invoice already exists' : 'Invoice was not created')
                        ->body($exception->getMessage());

                    if ($isDuplicate) {
                        $notification->warning();
                    } else {
                        $notification->danger();
                    }

                    $notification->send();

                    return;
                }

                Notification::make()
                    ->title('Invoice created and linked')
                    ->body(filled($invoice->invoice_number) ? $invoice->invoice_number : 'Invoice #'.$invoice->getKey())
                    ->success()
                    ->send();
            });
    }

    public function linkDocumentAction(): Action
    {
        return Action::make('linkDocument')
            ->modalHeading(function (array $arguments): string {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $label = trim(implode(' · ', array_filter([
                    optional($transaction->booking_date)->format('Y-m-d'),
                    $transaction->counterpart_name,
                    Money::format($transaction->amount),
                ])));
                $prefix = filter_var($arguments['appendInvoices'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    ? 'Add invoice(s)'
                    : 'Link document';

                return $label !== '' ? $prefix.' — '.$label : $prefix;
            })
            ->modalSubmitActionLabel('Link')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('xl')
            ->schema([
                Radio::make('document_type')
                    ->label('Document type')
                    ->options([
                        'invoice' => 'Invoice',
                        'contract' => 'Contract',
                        'payslip' => 'Payslip (DU)',
                        'business_trip' => 'Business trip (dienpinigiai)',
                        'report' => 'Payment report',
                        'document' => 'Other document',
                    ])
                    ->default('invoice')
                    ->required()
                    ->inline()
                    ->live()
                    ->columnSpanFull(),

                Select::make('invoice_ids')
                    ->label('Invoice(s)')
                    ->helperText('Search by number or company. You can select several invoices for one bank payment.')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'invoice')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'invoice')
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementInvoiceMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementInvoiceMatcher::searchInvoices($search))
                    ->getOptionLabelsUsing(function (array $values): array {
                        return Invoice::query()
                            ->with('contact')
                            ->whereIn('id', $values)
                            ->get()
                            ->mapWithKeys(fn (Invoice $invoice): array => [
                                $invoice->getKey() => BankStatementInvoiceMatcher::pickerLabel($invoice),
                            ])
                            ->all();
                    })
                    ->columnSpanFull(),

                Select::make('payment_id')
                    ->label('Contract / agreement')
                    ->helperText('Search by name or company. Amount-matching contracts are listed first.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'contract')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'contract')
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementPaymentMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementPaymentMatcher::searchPayments($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $payment = Payment::query()->with('contact')->find((int) $value);

                        return $payment ? BankStatementPaymentMatcher::pickerLabel($payment) : null;
                    })
                    ->columnSpanFull(),

                Select::make('employee_monthly_payment_id')
                    ->label('Payslip / salary payment')
                    ->helperText('Search by employee name. Use this for DU remittances. VMI GPM (1311) → GPM313; VMI PVM (1001) → FR0600; Sodra (252) → SAM.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'payslip')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'payslip')
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementSalaryMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementSalaryMatcher::searchPayments($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $payment = EmployeeMonthlyPayment::query()->with('employee')->find((int) $value);

                        return $payment ? BankStatementSalaryMatcher::pickerLabel($payment) : null;
                    })
                    ->columnSpanFull(),

                Select::make('business_trip_request_id')
                    ->label('Business trip')
                    ->helperText('Search by employee or destination. Matches dienpinigiai bank remittances.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'business_trip')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'business_trip')
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementBusinessTripMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementBusinessTripMatcher::searchTrips($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $trip = BusinessTripRequest::query()->with('employee')->find((int) $value);

                        return $trip ? BankStatementBusinessTripMatcher::pickerLabel($trip) : null;
                    })
                    ->columnSpanFull(),

                Select::make('employee_payment_report_id')
                    ->label('Payment report')
                    ->helperText('Links the bank line to the signed payment report document.')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'report')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'report')
                    ->options(fn (): array => BankStatementDocumentMatcher::reportPickerOptions())
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementDocumentMatcher::searchReports($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $report = EmployeePaymentReport::query()->with('document')->find((int) $value);

                        return $report ? BankStatementDocumentMatcher::reportLabel($report) : null;
                    })
                    ->columnSpanFull(),

                Select::make('document_id')
                    ->label('Document')
                    ->helperText('Any document from Documents (contracts, declarations, etc.).')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('document_type') === 'document')
                    ->visible(fn (Get $get): bool => $get('document_type') === 'document')
                    ->options(fn (): array => BankStatementDocumentMatcher::documentPickerOptions())
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementDocumentMatcher::searchDocuments($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $document = Document::query()->with('documentType')->find((int) $value);

                        return $document ? BankStatementDocumentMatcher::documentLabel($document) : null;
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $type = $arguments['documentType'] ?? null;
                $append = filter_var($arguments['appendInvoices'] ?? false, FILTER_VALIDATE_BOOLEAN);

                if (! is_string($type) || $type === '') {
                    if (BankStatementDocumentMatcher::isVmiGpmTransfer($transaction)
                        || BankStatementDocumentMatcher::isSodraSamTransfer($transaction)
                        || BankStatementDocumentMatcher::isVmiPvmTransfer($transaction)) {
                        $type = 'document';
                    } elseif (BankStatementBusinessTripMatcher::isBusinessTripTransfer($transaction)) {
                        $type = 'business_trip';
                    } elseif (BankStatementSalaryMatcher::isSalaryTransfer($transaction)) {
                        $type = 'payslip';
                    } else {
                        $type = 'invoice';
                    }
                }

                if ($append) {
                    $type = 'invoice';
                }

                $suggestedInvoiceIds = [];
                if (! $append) {
                    $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);
                    if ($batch->isEmpty()) {
                        BankStatementInvoiceMatcher::suggestionFor($transaction);
                        $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);
                    }
                    $suggestedInvoiceIds = $batch->isNotEmpty()
                        ? $batch->map(fn (Invoice $invoice): int => (int) $invoice->getKey())->all()
                        : array_filter([
                            BankStatementInvoiceMatcher::suggestionFor($transaction)?->getKey(),
                        ]);
                }

                $suggestedDocument = null;
                if (! $append) {
                    $suggestedDocument = BankStatementDocumentMatcher::suggestionGpm313For($transaction)
                        ?? BankStatementDocumentMatcher::suggestionSamFor($transaction)
                        ?? BankStatementDocumentMatcher::suggestionFr0600For($transaction);
                }

                return [
                    'document_type' => $type,
                    'invoice_ids' => $suggestedInvoiceIds,
                    'payment_id' => $append ? null : BankStatementPaymentMatcher::suggestionFor($transaction)?->getKey(),
                    'employee_monthly_payment_id' => $append ? null : BankStatementSalaryMatcher::suggestionFor($transaction)?->getKey(),
                    'business_trip_request_id' => $append ? null : BankStatementBusinessTripMatcher::suggestionFor($transaction)?->getKey(),
                    'document_id' => $suggestedDocument?->getKey(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $type = (string) ($data['document_type'] ?? '');

                try {
                    match ($type) {
                        'invoice' => $this->linkDocumentAsInvoice($transaction, $data, $arguments),
                        'contract' => $this->linkDocumentAsContract($transaction, $data),
                        'payslip' => $this->linkDocumentAsPayslip($transaction, $data),
                        'business_trip' => $this->linkDocumentAsBusinessTrip($transaction, $data),
                        'report' => $this->linkDocumentAsReport($transaction, $data),
                        'document' => $this->linkDocumentAsDocument($transaction, $data),
                        default => throw new \RuntimeException('Select a document type.'),
                    };
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Document was not linked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    private function linkDocumentAsInvoice(BankStatementTransaction $transaction, array $data, array $arguments = []): void
    {
        $append = filter_var($arguments['appendInvoices'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $append) {
            $this->clearConflictingDocumentLinks($transaction, keep: 'invoice');
        }

        $ids = collect($data['invoice_ids'] ?? [])
            ->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new \RuntimeException('Select at least one invoice.');
        }

        $invoices = Invoice::query()->whereIn('id', $ids->all())->get();
        if ($invoices->count() !== $ids->count()) {
            throw new \RuntimeException('Invoice not found.');
        }

        $ordered = $ids
            ->map(fn (int $id): ?Invoice => $invoices->firstWhere('id', $id))
            ->filter()
            ->values();

        BankStatementInvoiceMatcher::linkMany($transaction, $ordered, append: $append);

        $numbers = $ordered->map(fn (Invoice $invoice): string => $invoice->displayNumber())->implode(', ');

        Notification::make()
            ->title($append
                ? ($ordered->count() > 1 ? 'Invoices added' : 'Invoice added')
                : ($ordered->count() > 1 ? 'Invoices linked' : 'Invoice linked'))
            ->body($numbers)
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkDocumentAsContract(BankStatementTransaction $transaction, array $data): void
    {
        $this->clearConflictingDocumentLinks($transaction, keep: 'contract');

        $payment = Payment::query()->find($data['payment_id'] ?? null);
        if ($payment === null) {
            throw new \RuntimeException('Contract not found.');
        }

        BankStatementPaymentMatcher::link($transaction, $payment);

        Notification::make()
            ->title('Contract linked')
            ->body($payment->name)
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkDocumentAsPayslip(BankStatementTransaction $transaction, array $data): void
    {
        $this->clearConflictingDocumentLinks($transaction, keep: 'payslip');

        $payment = EmployeeMonthlyPayment::query()->find($data['employee_monthly_payment_id'] ?? null);
        if ($payment === null) {
            throw new \RuntimeException('Salary payment not found.');
        }

        BankStatementSalaryMatcher::link($transaction, $payment);

        Notification::make()
            ->title('Payslip linked')
            ->body(BankStatementSalaryMatcher::pickerLabel($payment))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkDocumentAsBusinessTrip(BankStatementTransaction $transaction, array $data): void
    {
        $this->clearConflictingDocumentLinks($transaction, keep: 'business_trip');

        $trip = BusinessTripRequest::query()->find($data['business_trip_request_id'] ?? null);
        if ($trip === null) {
            throw new \RuntimeException('Business trip not found.');
        }

        BankStatementBusinessTripMatcher::link($transaction, $trip);

        Notification::make()
            ->title('Business trip linked')
            ->body(BankStatementBusinessTripMatcher::pickerLabel($trip))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkDocumentAsReport(BankStatementTransaction $transaction, array $data): void
    {
        $this->clearConflictingDocumentLinks($transaction, keep: 'document');

        $report = EmployeePaymentReport::query()->find($data['employee_payment_report_id'] ?? null);
        if ($report === null) {
            throw new \RuntimeException('Payment report not found.');
        }

        $document = BankStatementDocumentMatcher::documentFromReport($report);
        BankStatementDocumentMatcher::link($transaction, $document);

        Notification::make()
            ->title('Payment report linked')
            ->body(BankStatementDocumentMatcher::reportLabel($report))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkDocumentAsDocument(BankStatementTransaction $transaction, array $data): void
    {
        $this->clearConflictingDocumentLinks($transaction, keep: 'document');

        $document = Document::query()->find($data['document_id'] ?? null);
        if ($document === null) {
            throw new \RuntimeException('Document not found.');
        }

        BankStatementDocumentMatcher::link($transaction, $document);

        Notification::make()
            ->title('Document linked')
            ->body(BankStatementDocumentMatcher::documentLabel($document))
            ->success()
            ->send();
    }

    private function clearConflictingDocumentLinks(BankStatementTransaction $transaction, string $keep): void
    {
        if (
            (bool) $transaction->no_document_needed
            || (bool) $transaction->is_refund
            || (bool) $transaction->is_historical
        ) {
            $transaction->forceFill([
                'no_document_needed' => false,
                'is_refund' => false,
                'is_historical' => false,
            ])->save();
        }

        if ($keep !== 'invoice') {
            BankStatementInvoiceMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($keep !== 'contract') {
            BankStatementPaymentMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($keep !== 'payslip') {
            BankStatementSalaryMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($keep !== 'business_trip') {
            BankStatementBusinessTripMatcher::unlink($transaction);
            $transaction->refresh();
        }

        if ($keep !== 'document') {
            BankStatementDocumentMatcher::unlink($transaction);
            $transaction->refresh();
        }
    }

    public function linkExistingInvoiceAction(): Action
    {
        return Action::make('linkExistingInvoice')
            ->modalHeading(function (array $arguments): string {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $label = trim(implode(' · ', array_filter([
                    optional($transaction->booking_date)->format('Y-m-d'),
                    $transaction->counterpart_name,
                    Money::format($transaction->amount),
                ])));

                return $label !== '' ? 'Link invoice(s) — '.$label : 'Link invoice(s)';
            })
            ->modalSubmitActionLabel('Link')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('xl')
            ->schema([
                Select::make('invoice_ids')
                    ->label('Invoices')
                    ->helperText('Select one or more invoices. For several, their outstanding totals must match the payment. Search by number, company, or amount.')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementInvoiceMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementInvoiceMatcher::searchInvoices($search))
                    ->getOptionLabelsUsing(function (array $values): array {
                        $ids = collect($values)
                            ->filter(fn (mixed $value): bool => is_numeric($value))
                            ->map(fn (mixed $value): int => (int) $value)
                            ->all();

                        if ($ids === []) {
                            return [];
                        }

                        return Invoice::query()
                            ->with('contact')
                            ->whereIn('id', $ids)
                            ->get()
                            ->mapWithKeys(fn (Invoice $invoice): array => [
                                $invoice->getKey() => BankStatementInvoiceMatcher::pickerLabel($invoice),
                            ])
                            ->all();
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $suggestions = BankStatementInvoiceMatcher::suggestFor(collect([$transaction]));
                $suggested = $suggestions->get($transaction->getKey());
                $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);

                if ($batch->count() >= 2) {
                    return [
                        'invoice_ids' => $batch
                            ->map(fn (Invoice $invoice): int => (int) $invoice->getKey())
                            ->values()
                            ->all(),
                    ];
                }

                return [
                    'invoice_ids' => $suggested !== null ? [(int) $suggested->getKey()] : [],
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $ids = collect($data['invoice_ids'] ?? [])
                    ->filter(fn (mixed $value): bool => is_numeric($value))
                    ->map(fn (mixed $value): int => (int) $value)
                    ->unique()
                    ->values();

                if ($ids->isEmpty()) {
                    Notification::make()
                        ->title('Select at least one invoice')
                        ->danger()
                        ->send();

                    return;
                }

                $invoices = Invoice::query()
                    ->whereIn('id', $ids->all())
                    ->get();

                if ($invoices->count() !== $ids->count()) {
                    Notification::make()
                        ->title('Invoice not found')
                        ->danger()
                        ->send();

                    return;
                }

                // Preserve picker order for stable primary selection when amounts tie.
                $ordered = $ids
                    ->map(fn (int $id): ?Invoice => $invoices->firstWhere('id', $id))
                    ->filter()
                    ->values();

                try {
                    BankStatementInvoiceMatcher::linkMany($transaction, $ordered);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Invoice(s) were not linked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $numbers = $ordered->map(fn (Invoice $invoice): string => $invoice->displayNumber())->implode(', ');

                Notification::make()
                    ->title($ordered->count() > 1 ? 'Invoices linked' : 'Invoice linked')
                    ->body($numbers)
                    ->success()
                    ->send();
            });
    }

    public function linkExistingPaymentAction(): Action
    {
        return Action::make('linkExistingPayment')
            ->modalHeading(function (array $arguments): string {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $label = trim(implode(' · ', array_filter([
                    optional($transaction->booking_date)->format('Y-m-d'),
                    $transaction->counterpart_name,
                    Money::format($transaction->amount),
                ])));

                return $label !== '' ? 'Link contract — '.$label : 'Link contract';
            })
            ->modalSubmitActionLabel('Link contract')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('xl')
            ->schema([
                Select::make('payment_id')
                    ->label('Contract / agreement')
                    ->helperText('Search by name or company. Amount-matching contracts are listed first.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementPaymentMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementPaymentMatcher::searchPayments($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $payment = Payment::query()->with('contact')->find((int) $value);

                        return $payment ? BankStatementPaymentMatcher::pickerLabel($payment) : null;
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);

                return [
                    'payment_id' => BankStatementPaymentMatcher::suggestionFor($transaction)?->getKey(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $payment = Payment::query()->find($data['payment_id'] ?? null);

                if ($payment === null) {
                    Notification::make()
                        ->title('Contract not found')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    BankStatementPaymentMatcher::link($transaction, $payment);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Contract was not linked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Contract linked')
                    ->body($payment->name)
                    ->success()
                    ->send();
            });
    }

    public function linkExistingSalaryPaymentAction(): Action
    {
        return Action::make('linkExistingSalaryPayment')
            ->modalHeading(function (array $arguments): string {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $label = trim(implode(' · ', array_filter([
                    optional($transaction->booking_date)->format('Y-m-d'),
                    $transaction->counterpart_name,
                    Money::format($transaction->amount),
                ])));

                return $label !== '' ? 'Link salary payment — '.$label : 'Link salary payment';
            })
            ->modalSubmitActionLabel('Link payslip')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('xl')
            ->schema([
                Select::make('employee_monthly_payment_id')
                    ->label('Salary payment')
                    ->helperText('Search by employee name. Matching DU payments from nearby dates are listed first.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->options(function (): array {
                        $transactionId = $this->getMountedAction()?->getArguments()['transactionId'] ?? null;
                        $transaction = $this->resolveTransaction($transactionId);

                        return BankStatementSalaryMatcher::pickerOptionsFor($transaction);
                    })
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementSalaryMatcher::searchPayments($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $payment = EmployeeMonthlyPayment::query()->with('employee')->find((int) $value);

                        return $payment ? BankStatementSalaryMatcher::pickerLabel($payment) : null;
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);

                return [
                    'employee_monthly_payment_id' => BankStatementSalaryMatcher::suggestionFor($transaction)?->getKey(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $transaction = $this->resolveTransaction($arguments['transactionId'] ?? null);
                $payment = EmployeeMonthlyPayment::query()->find($data['employee_monthly_payment_id'] ?? null);

                if ($payment === null) {
                    Notification::make()
                        ->title('Salary payment not found')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    BankStatementSalaryMatcher::link($transaction, $payment);
                } catch (\Throwable $exception) {
                    Notification::make()
                        ->title('Salary payment was not linked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Salary payment linked')
                    ->body(BankStatementSalaryMatcher::pickerLabel($payment))
                    ->success()
                    ->send();
            });
    }

    public function confirmSuggestedInvoice(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $invoice = BankStatementInvoiceMatcher::suggestionFor($transaction);

        if ($invoice === null) {
            Notification::make()
                ->title('No matching invoice to confirm')
                ->body('The suggestion is no longer available. Link an existing invoice or create a new one.')
                ->warning()
                ->send();

            return;
        }

        try {
            BankStatementInvoiceMatcher::link($transaction, $invoice);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Invoice was not linked')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $linkedCount = $transaction->fresh()?->invoices()->count() ?? 1;

        Notification::make()
            ->title($linkedCount > 1 ? 'Invoices linked' : 'Invoice linked')
            ->body($linkedCount > 1
                ? $linkedCount.' invoices · '.$this->formatMoney((float) $transaction->amount)
                : $invoice->displayNumber())
            ->success()
            ->send();
    }

    public function confirmSuggestedPayment(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $payment = BankStatementPaymentMatcher::suggestionFor($transaction);

        if ($payment === null) {
            Notification::make()
                ->title('No matching contract to confirm')
                ->body('The suggestion is no longer available. Link an existing contract or invoice.')
                ->warning()
                ->send();

            return;
        }

        try {
            BankStatementPaymentMatcher::link($transaction, $payment);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Contract was not linked')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Contract linked')
            ->body($payment->name)
            ->success()
            ->send();
    }

    public function confirmSuggestedSalaryPayment(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $payment = BankStatementSalaryMatcher::suggestionFor($transaction);

        if ($payment === null) {
            Notification::make()
                ->title('No matching salary payment to confirm')
                ->body('The suggestion is no longer available. Link an existing salary payment from Payments.')
                ->warning()
                ->send();

            return;
        }

        try {
            BankStatementSalaryMatcher::link($transaction, $payment);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Salary payment was not linked')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Salary payment linked')
            ->body(BankStatementSalaryMatcher::pickerLabel($payment))
            ->success()
            ->send();
    }

    public function confirmSuggestedBusinessTrip(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $trip = BankStatementBusinessTripMatcher::suggestionFor($transaction);

        if ($trip === null) {
            Notification::make()
                ->title('No matching business trip to confirm')
                ->body('The suggestion is no longer available. Link an existing trip from Documents.')
                ->warning()
                ->send();

            return;
        }

        try {
            BankStatementBusinessTripMatcher::link($transaction, $trip);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Business trip was not linked')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Business trip linked')
            ->body(BankStatementBusinessTripMatcher::pickerLabel($trip))
            ->success()
            ->send();
    }

    public function confirmAllSuggestedInvoices(string $monthKey): void
    {
        $transactions = BankStatementTransaction::query()
            ->where('month_key', $monthKey)
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->get();

        $linked = $this->linkSuggestedMatches($transactions);

        if ($linked === 0) {
            Notification::make()
                ->title('Nothing was linked')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Suggested links saved')
            ->body($linked.' payment(s) linked.')
            ->success()
            ->send();
    }

    public function scanSuggestedLinks(): void
    {
        $query = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->orderBy('booking_date')
            ->orderBy('id');

        // Ignore link_status so a previous "Suggested matches" filter does not hide candidates.
        $filtersBackup = $this->filters;
        $scanFilters = $this->filters ?? [];
        unset($scanFilters['link_status'], $scanFilters['invoice_status']);
        $this->filters = $scanFilters;
        $this->applyFilters($query);
        $this->filters = $filtersBackup;

        $unlinked = $query->get();
        $invoiceSuggestions = BankStatementInvoiceMatcher::suggestFor($unlinked);
        $remaining = $unlinked->reject(
            fn (BankStatementTransaction $row): bool => $invoiceSuggestions->has($row->getKey()),
        )->values();
        $paymentSuggestions = BankStatementPaymentMatcher::suggestFor($remaining);
        $afterContracts = $remaining->reject(
            fn (BankStatementTransaction $row): bool => $paymentSuggestions->has($row->getKey()),
        )->values();
        $salarySuggestions = BankStatementSalaryMatcher::suggestFor($afterContracts);
        $afterSalary = $afterContracts->reject(
            fn (BankStatementTransaction $row): bool => $salarySuggestions->has($row->getKey()),
        )->values();
        $tripSuggestions = BankStatementBusinessTripMatcher::suggestFor($afterSalary);

        $invoiceCount = $invoiceSuggestions->count();
        $paymentCount = $paymentSuggestions->count();
        $salaryCount = $salarySuggestions->count();
        $tripCount = $tripSuggestions->count();
        $total = $invoiceCount + $paymentCount + $salaryCount + $tripCount;

        $nextFilters = [
            ...($this->filters ?? []),
            'link_status' => $total > 0 ? 'suggested' : ($this->filters['link_status'] ?? null),
        ];
        $this->filters = $nextFilters;
        $this->filtersForm->fill($nextFilters);

        if ($total === 0) {
            Notification::make()
                ->title('No suggested links found')
                ->body('No confident invoice, contract, salary, or business-trip matches for the current filters.')
                ->warning()
                ->send();

            return;
        }

        $parts = [];
        if ($invoiceCount > 0) {
            $parts[] = $invoiceCount.' invoice'.($invoiceCount === 1 ? '' : 's');
        }
        if ($paymentCount > 0) {
            $parts[] = $paymentCount.' contract'.($paymentCount === 1 ? '' : 's');
        }
        if ($salaryCount > 0) {
            $parts[] = $salaryCount.' salary payment'.($salaryCount === 1 ? '' : 's');
        }
        if ($tripCount > 0) {
            $parts[] = $tripCount.' business trip'.($tripCount === 1 ? '' : 's');
        }

        Notification::make()
            ->title('Suggested links ready')
            ->body('Found '.implode(' and ', $parts).'. Review each row or use Confirm all suggested.')
            ->success()
            ->send();
    }

    public function confirmAllSuggestedLinks(): void
    {
        $groups = $this->getMonthGroups();
        $candidates = $groups
            ->flatten(1)
            ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved()
                && ($row->relationLoaded('suggestedInvoice') && $row->getRelation('suggestedInvoice') !== null
                    || $row->relationLoaded('suggestedPayment') && $row->getRelation('suggestedPayment') !== null
                    || $row->relationLoaded('suggestedSalaryPayment') && $row->getRelation('suggestedSalaryPayment') !== null
                    || $row->relationLoaded('suggestedBusinessTrip') && $row->getRelation('suggestedBusinessTrip') !== null))
            ->values();

        if ($candidates->isEmpty()) {
            // Fall back to a fresh scan of unlinked lines under current filters.
            $query = BankStatementTransaction::query()
                ->withoutLinkedDocument()
                ->where('no_document_needed', false)
                ->where('is_historical', false);
            $this->applyFilters($query);
            $candidates = $query->get();
        }

        $linked = $this->linkSuggestedMatches($candidates);

        if ($linked === 0) {
            Notification::make()
                ->title('Nothing was linked')
                ->body('Run Scan for links first, or confirm suggestions one by one.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Suggested links saved')
            ->body($linked.' payment(s) linked.')
            ->success()
            ->send();
    }

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     */
    private function linkSuggestedMatches(Collection $transactions): int
    {
        $transactions = $transactions
            ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())
            ->values();

        if ($transactions->isEmpty()) {
            return 0;
        }

        $invoiceSuggestions = BankStatementInvoiceMatcher::suggestFor($transactions);
        $linked = 0;

        foreach ($invoiceSuggestions as $transactionId => $invoice) {
            $transaction = $transactions->firstWhere('id', $transactionId);
            if ($transaction === null || $transaction->isResolved()) {
                continue;
            }

            try {
                BankStatementInvoiceMatcher::link($transaction, $invoice);
                $transaction->invoice_id = $invoice->getKey();
                $linked++;
            } catch (\Throwable) {
                continue;
            }
        }

        $stillUnlinked = $transactions->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())->values();
        $paymentSuggestions = BankStatementPaymentMatcher::suggestFor($stillUnlinked);

        foreach ($paymentSuggestions as $transactionId => $payment) {
            $transaction = $transactions->firstWhere('id', $transactionId);
            if ($transaction === null || $transaction->isResolved()) {
                continue;
            }

            try {
                BankStatementPaymentMatcher::link($transaction, $payment);
                $transaction->payment_id = $payment->getKey();
                $linked++;
            } catch (\Throwable) {
                continue;
            }
        }

        $stillUnlinked = $transactions->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())->values();
        $salarySuggestions = BankStatementSalaryMatcher::suggestFor($stillUnlinked);

        foreach ($salarySuggestions as $transactionId => $salaryPayment) {
            $transaction = $transactions->firstWhere('id', $transactionId);
            if ($transaction === null || $transaction->isResolved()) {
                continue;
            }

            try {
                BankStatementSalaryMatcher::link($transaction, $salaryPayment);
                $transaction->employee_monthly_payment_id = $salaryPayment->getKey();
                $linked++;
            } catch (\Throwable) {
                continue;
            }
        }

        $stillUnlinked = $transactions->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved())->values();
        $tripSuggestions = BankStatementBusinessTripMatcher::suggestFor($stillUnlinked);

        foreach ($tripSuggestions as $transactionId => $trip) {
            $transaction = $transactions->firstWhere('id', $transactionId);
            if ($transaction === null || $transaction->isResolved()) {
                continue;
            }

            try {
                BankStatementBusinessTripMatcher::link($transaction, $trip);
                $transaction->business_trip_request_id = $trip->getKey();
                $linked++;
            } catch (\Throwable) {
                continue;
            }
        }

        return $linked;
    }

    public function unlink(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $wasInvoice = (bool) $transaction->invoice_id || $transaction->invoices()->exists();
        $wasSalary = (bool) $transaction->employee_monthly_payment_id;
        $wasBusinessTrip = (bool) $transaction->business_trip_request_id;
        $wasDocument = (bool) $transaction->document_id;
        BankStatementInvoiceMatcher::unlink($transaction);
        BankStatementPaymentMatcher::unlink($transaction->fresh() ?? $transaction);
        BankStatementSalaryMatcher::unlink($transaction->fresh() ?? $transaction);
        BankStatementBusinessTripMatcher::unlink($transaction->fresh() ?? $transaction);
        BankStatementDocumentMatcher::unlink($transaction->fresh() ?? $transaction);

        $title = 'Contract unlinked';
        if ($wasInvoice) {
            $title = 'Invoice unlinked';
        } elseif ($wasSalary) {
            $title = 'Salary payment unlinked';
        } elseif ($wasBusinessTrip) {
            $title = 'Business trip unlinked';
        } elseif ($wasDocument) {
            $title = 'Document unlinked';
        }

        Notification::make()
            ->title($title)
            ->success()
            ->send();
    }

    public function unlinkInvoice(int $transactionId, int $invoiceId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $invoice = Invoice::query()->findOrFail($invoiceId);

        BankStatementInvoiceMatcher::unlinkInvoice($transaction, $invoice);

        Notification::make()
            ->title('Invoice unlinked')
            ->body($invoice->displayNumber())
            ->success()
            ->send();
    }

    public function markNoDocumentNeeded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);

        if ($transaction->isLinked()) {
            Notification::make()
                ->title('Unlink the document first')
                ->warning()
                ->send();

            return;
        }

        $transaction->update([
            'no_document_needed' => true,
            'is_historical' => false,
            'is_refund' => false,
        ]);

        Notification::make()
            ->title('Marked as no invoice needed')
            ->success()
            ->send();
    }

    public function clearNoDocumentNeeded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $transaction->update([
            'no_document_needed' => false,
            'is_refund' => false,
        ]);

        Notification::make()
            ->title('Mark removed')
            ->success()
            ->send();
    }

    public function markHistorical(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);

        if ($transaction->isLinked()) {
            Notification::make()
                ->title('Unlink the document first')
                ->warning()
                ->send();

            return;
        }

        $transaction->update([
            'is_historical' => true,
            'is_refund' => false,
            'no_document_needed' => false,
        ]);

        Notification::make()
            ->title('Marked as historical data')
            ->body('Kept on the statement; no invoice linking required.')
            ->success()
            ->send();
    }

    public function clearHistorical(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $transaction->update(['is_historical' => false]);

        Notification::make()
            ->title('Historical mark removed')
            ->success()
            ->send();
    }

    public function markAsRefunded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);

        if ($transaction->isLinked()) {
            BankStatementInvoiceMatcher::unlink($transaction);
            BankStatementPaymentMatcher::unlink($transaction->fresh() ?? $transaction);
            BankStatementSalaryMatcher::unlink($transaction->fresh() ?? $transaction);
            BankStatementBusinessTripMatcher::unlink($transaction->fresh() ?? $transaction);
            BankStatementDocumentMatcher::unlink($transaction->fresh() ?? $transaction);
            $transaction = $transaction->fresh() ?? $transaction;
        }

        $transaction->update([
            'is_refund' => true,
            'no_document_needed' => true,
            'is_historical' => false,
            'overpayment_refunded' => false,
        ]);

        Notification::make()
            ->title('Marked as refunded')
            ->body('No document needed.')
            ->success()
            ->send();
    }

    public function markOverpaymentRefunded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $summary = $transaction->settlementSummary();

        if (($summary['status'] ?? null) !== 'overpaid' && ! (bool) $transaction->overpayment_refunded) {
            Notification::make()
                ->title('No overpayment to settle')
                ->body('Link invoices that leave an overpayment first, or use Refunded to mark the whole payment.')
                ->warning()
                ->send();

            return;
        }

        $transaction->update([
            'overpayment_refunded' => true,
            'is_refund' => false,
            'no_document_needed' => false,
        ]);

        Notification::make()
            ->title('Overpayment marked as refunded')
            ->body('Invoice links kept. Status is Settled.')
            ->success()
            ->send();
    }

    public function clearOverpaymentRefunded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $transaction->update([
            'overpayment_refunded' => false,
        ]);

        Notification::make()
            ->title('Overpayment refund mark removed')
            ->success()
            ->send();
    }

    public function clearRefunded(int $transactionId): void
    {
        $transaction = $this->resolveTransaction($transactionId);
        $transaction->update([
            'is_refund' => false,
            'no_document_needed' => false,
        ]);

        Notification::make()
            ->title('Refunded mark removed')
            ->success()
            ->send();
    }

    public function linkedDocumentDate(BankStatementTransaction $transaction): ?string
    {
        if ($transaction->invoice?->invoice_date) {
            return $transaction->invoice->invoice_date->format('Y-m-d');
        }

        if ($transaction->payment) {
            return BankStatementPaymentMatcher::closestOccurrence($transaction, $transaction->payment)?->format('Y-m-d');
        }

        if ($transaction->employeeMonthlyPayment?->payment_date) {
            return $transaction->employeeMonthlyPayment->payment_date->format('Y-m-d');
        }

        if ($transaction->document?->document_date) {
            return $transaction->document->document_date->format('Y-m-d');
        }

        return null;
    }

    public function contractUrl(Payment $payment): string
    {
        return $payment->firstDocumentUrl() ?? PaymentResource::getUrl('edit', ['record' => $payment]);
    }

    public function invoiceEditUrl(Invoice $invoice): string
    {
        return Invoices::getUrl([
            'edit_invoice' => $invoice->getKey(),
            'filters' => [
                'month' => 'any',
                'invoice_number' => filled($invoice->invoice_number)
                    ? (string) $invoice->invoice_number
                    : (string) $invoice->getKey(),
            ],
        ]);
    }

    public function documentEditUrl(Document $document): string
    {
        return DocumentResource::getUrl('edit', ['record' => $document]);
    }

    public function linkedDocumentUrl(Document $document): ?string
    {
        return $document->displayFileUrl()
            ?? DocumentResource::getUrl('edit', ['record' => $document]);
    }

    public function salaryPaymentUrl(EmployeeMonthlyPayment $payment): ?string
    {
        $payment->loadMissing('payslipDocument');

        return $payment->payslipDocument?->displayFileUrl()
            ?? ($payment->payslip_document_id
                ? DocumentResource::getUrl('edit', ['record' => $payment->payslip_document_id])
                : null);
    }

    public function salaryPaymentLabel(EmployeeMonthlyPayment $payment): string
    {
        return BankStatementSalaryMatcher::pickerLabel($payment);
    }

    public function businessTripUrl(BusinessTripRequest $trip): ?string
    {
        return \App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource::getUrl('edit', ['record' => $trip]);
    }

    public function businessTripLabel(BusinessTripRequest $trip): string
    {
        return BankStatementBusinessTripMatcher::pickerLabel($trip);
    }

    public function viewBusinessTripPaymentsAction(): Action
    {
        return Action::make('viewBusinessTripPayments')
            ->modalHeading(function (array $arguments): string {
                $trip = $this->resolveBusinessTrip($arguments['tripId'] ?? null);
                $name = $trip->employee?->fullName() ?: 'Business trip';

                return 'Linked bank payments — '.$name;
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('3xl')
            ->modalContent(function (array $arguments): Htmlable {
                $trip = $this->resolveBusinessTrip($arguments['tripId'] ?? null);
                $trip->loadMissing(['employee', 'bankStatementTransactions']);

                $payments = $trip->bankStatementTransactions
                    ->sortBy([
                        fn (BankStatementTransaction $row) => optional($row->booking_date)?->format('Y-m-d') ?? '',
                        fn (BankStatementTransaction $row) => (int) $row->getKey(),
                    ])
                    ->values();

                $currentId = is_numeric($arguments['transactionId'] ?? null)
                    ? (int) $arguments['transactionId']
                    : null;

                return new HtmlString(view('filament.admin.pages.partials.business-trip-linked-payments', [
                    'trip' => $trip,
                    'payments' => $payments,
                    'currentTransactionId' => $currentId,
                ])->render());
            });
    }

    private function resolveBusinessTrip(mixed $tripId): BusinessTripRequest
    {
        if (! is_numeric($tripId)) {
            throw new \RuntimeException('Business trip not found.');
        }

        return BusinessTripRequest::query()
            ->with(['employee', 'bankStatementTransactions'])
            ->findOrFail((int) $tripId);
    }

    private function resolveTransaction(mixed $transactionId): BankStatementTransaction
    {
        if (! is_numeric($transactionId)) {
            throw new \RuntimeException('Bank statement line not found.');
        }

        return BankStatementTransaction::query()
            ->with('import')
            ->findOrFail((int) $transactionId);
    }

    /**
     * @return Collection<string, Collection<int, BankStatementTransaction>>
     */
    public function getMonthGroups(): Collection
    {
        $query = BankStatementTransaction::query()
            ->with([
                'import',
                'invoice.contact',
                'invoice.incomeType',
                'invoice.expenseType',
                'invoices.contact',
                'invoices.incomeType',
                'invoices.expenseType',
                'payment.contact',
                'employeeMonthlyPayment.employee',
                'employeeMonthlyPayment.payslipDocument',
                'businessTripRequest.employee',
                'businessTripRequest.documents',
                'businessTripRequest.bankStatementTransactions',
                'document.documentType',
            ])
            ->orderByDesc('booking_date')
            ->orderByDesc('id');

        $this->applyFilters($query);

        $transactions = $query->get();

        $invoiceSuggestions = BankStatementInvoiceMatcher::suggestFor($transactions);
        $paymentSuggestions = BankStatementPaymentMatcher::suggestFor($transactions);
        $salarySuggestions = BankStatementSalaryMatcher::suggestFor($transactions);
        $tripSuggestions = BankStatementBusinessTripMatcher::suggestFor($transactions);

        foreach ($transactions as $transaction) {
            $transaction->setRelation(
                'suggestedInvoice',
                $invoiceSuggestions->get($transaction->getKey()),
            );
            $batch = BankStatementInvoiceMatcher::batchInvoicesFor($transaction);
            $transaction->setRelation(
                'suggestedInvoiceBatch',
                $batch->isNotEmpty() ? $batch : collect(),
            );
            $transaction->setRelation(
                'suggestedPayment',
                $paymentSuggestions->get($transaction->getKey()),
            );
            $transaction->setRelation(
                'suggestedSalaryPayment',
                $salarySuggestions->get($transaction->getKey()),
            );
            $transaction->setRelation(
                'suggestedBusinessTrip',
                $tripSuggestions->get($transaction->getKey()),
            );
        }

        $linkStatus = $this->filters['link_status'] ?? $this->filters['invoice_status'] ?? null;
        if ($linkStatus === 'suggested') {
            $transactions = $transactions
                ->filter(fn (BankStatementTransaction $row): bool => ! $row->isResolved()
                    && ($invoiceSuggestions->has($row->getKey())
                        || $paymentSuggestions->has($row->getKey())
                        || $salarySuggestions->has($row->getKey())
                        || $tripSuggestions->has($row->getKey())))
                ->values();
        }

        if (in_array($this->filters['payment_status'] ?? null, ['overpaid', 'settled', 'underpaid', 'refund', 'refunded'], true)) {
            $wanted = (string) $this->filters['payment_status'];
            $transactions = $transactions
                ->filter(fn (BankStatementTransaction $row): bool => ($row->settlementSummary()['status'] ?? null) === $wanted)
                ->values();
        }

        return $transactions
            ->groupBy('month_key')
            ->sortKeysDesc();
    }

    /**
     * @param  Collection<int, BankStatementTransaction>  $transactions
     * @return array{income: float, outcome: float, net: float, count: int, banks: string}
     */
    public function monthSummary(Collection $transactions): array
    {
        $income = round($transactions->sum(fn (BankStatementTransaction $row): float => $row->incomeAmount()), 2);
        $outcome = round($transactions->sum(fn (BankStatementTransaction $row): float => $row->outcomeAmount()), 2);
        $banks = $transactions
            ->map(fn (BankStatementTransaction $row): string => trim((string) ($row->bank_name ?: $row->account_iban ?: '')))
            ->filter()
            ->unique()
            ->values();

        return [
            'income' => $income,
            'outcome' => $outcome,
            'net' => round($income - $outcome, 2),
            'count' => $transactions->count(),
            'banks' => $banks->implode(', '),
        ];
    }

    public function monthTitle(string $monthKey): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $monthKey)->format('F Y');
        } catch (\Throwable) {
            return $monthKey;
        }
    }

    public function formatMoney(float $amount): string
    {
        return Money::format($amount);
    }

    public function refreshList(): void
    {
        // Re-render with current filters (Livewire round-trip reloads query results).
    }

    public function resetFilters(): void
    {
        $filters = [];

        $this->filters = $filters;
        $this->filtersForm->fill($filters);
    }

    public function hasActiveFilters(): bool
    {
        $filters = $this->filters ?? [];

        return filled($filters['bank_account'] ?? null)
            || filled($filters['direction'] ?? null)
            || filled($filters['link_status'] ?? null)
            || filled($filters['invoice_status'] ?? null)
            || filled($filters['payment_status'] ?? null)
            || filled($filters['counterpart'] ?? null)
            || filled($filters['description'] ?? null)
            || filled($filters['comment'] ?? null)
            || filled($filters['month'] ?? null);
    }

    /**
     * @return array<string, string>
     */
    private function monthFilterOptions(): array
    {
        $keys = $this->availableMonthKeys();

        if ($keys === []) {
            $keys = [$this->defaultMonthKey()];
        }

        $options = ['any' => 'All months'];
        foreach ($keys as $key) {
            $options[(string) $key] = $this->monthTitle((string) $key);
        }

        return $options;
    }

    public function defaultMonthKey(): string
    {
        $current = now()->format('Y-m');
        $available = $this->availableMonthKeys();

        if ($available === []) {
            return $current;
        }

        // Prefer the calendar month when it has statements; otherwise latest imported month.
        if (in_array($current, $available, true)) {
            return $current;
        }

        return (string) $available[0];
    }

    /**
     * @return list<string>
     */
    private function availableMonthKeys(): array
    {
        return BankStatementTransaction::query()
            ->whereNotNull('month_key')
            ->where('month_key', '!=', '')
            ->distinct()
            ->orderByDesc('month_key')
            ->pluck('month_key')
            ->filter(fn ($key): bool => is_string($key) && preg_match('/^\d{4}-\d{2}$/', $key) === 1)
            ->values()
            ->all();
    }

    private function selectedMonthKey(): ?string
    {
        $month = trim((string) ($this->filters['month'] ?? ''));

        if ($month === 'any') {
            return null;
        }

        $available = $this->availableMonthKeys();

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            if ($available === [] || in_array($month, $available, true)) {
                return $month;
            }
        }

        // Soft default: current (or latest available) month while month is empty and no other filters are set.
        if ($this->filtersWidenBeyondMonth()) {
            return null;
        }

        return $this->defaultMonthKey();
    }

    private function filtersWidenBeyondMonth(): bool
    {
        $filters = $this->filters ?? [];

        foreach (['counterpart', 'description', 'bank_account', 'direction', 'link_status', 'invoice_status', 'payment_status', 'comment'] as $key) {
            if (trim((string) ($filters[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    public function isUsingSoftMonthDefault(): bool
    {
        return blank($this->filters['month'] ?? null) && ! $this->filtersWidenBeyondMonth();
    }

    /**
     * @param  Builder<BankStatementTransaction>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $filters = $this->filters ?? [];

        $monthKey = $this->selectedMonthKey();
        if ($monthKey !== null) {
            $query->where('month_key', $monthKey);
        }

        if (filled($filters['bank_account'] ?? null)) {
            $selected = (string) $filters['bank_account'];
            $companyAccount = CompanyBankAccount::resolveFromStatementAccount($selected);
            $identifiers = $companyAccount?->allIdentifiers() ?? [$selected];
            $query->whereIn('account_iban', $identifiers);
        }

        $direction = $filters['direction'] ?? null;
        if ($direction === 'own') {
            $query->ownTransfer();
        } elseif (filled($direction) && BankStatementDirection::tryFrom((string) $direction) !== null) {
            $query
                ->where('direction', (string) $direction)
                ->notOwnTransfer();
        }

        $linkStatus = $filters['link_status'] ?? $filters['invoice_status'] ?? null;

        if ($linkStatus === 'linked') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('invoice_id')
                    ->orWhereNotNull('payment_id')
                    ->orWhereNotNull('employee_monthly_payment_id')
                    ->orWhere(function (Builder $statement): void {
                        $statement
                            ->withoutLinkedDocument()
                            ->where(function (Builder $documented): void {
                                $documented->where('no_document_needed', true)
                                    ->orWhere('is_historical', true);

                                foreach (array_merge(
                                    BankStatementDirectionResolver::ownTransferLikePatterns(),
                                    BankStatementDirectionResolver::bankServiceFeeLikePatterns(),
                                ) as $pattern) {
                                    $documented
                                        ->orWhere('description', 'like', $pattern)
                                        ->orWhere('counterpart_name', 'like', $pattern);
                                }
                            })
                            ->whereHas('import', function (Builder $import): void {
                                $import->where(function (Builder $sharepoint): void {
                                    $sharepoint
                                        ->whereNotNull('sharepoint_web_url')
                                        ->where('sharepoint_web_url', '!=', '');
                                });
                            });
                    });
            });
        } elseif ($linkStatus === 'suggested') {
            $query
                ->withoutLinkedDocument()
                ->where('no_document_needed', false)
                ->where('is_historical', false);
        } elseif ($linkStatus === 'historical') {
            $query
                ->withoutLinkedDocument()
                ->where('is_historical', true);
        } elseif ($linkStatus === 'no_document') {
            $query
                ->withoutLinkedDocument()
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('no_document_needed', true)
                        ->orWhere('is_historical', true);

                    foreach (array_merge(
                        BankStatementDirectionResolver::ownTransferLikePatterns(),
                        BankStatementDirectionResolver::bankServiceFeeLikePatterns(),
                    ) as $pattern) {
                        $builder
                            ->orWhere('description', 'like', $pattern)
                            ->orWhere('counterpart_name', 'like', $pattern);
                    }
                });
        } elseif ($linkStatus === 'missing') {
            $query
                ->withoutLinkedDocument()
                ->where('no_document_needed', false)
                ->where('is_historical', false)
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNull('description')
                        ->orWhere(function (Builder $inner): void {
                            foreach (array_merge(
                                BankStatementDirectionResolver::ownTransferLikePatterns(),
                                BankStatementDirectionResolver::bankServiceFeeLikePatterns(),
                            ) as $pattern) {
                                $inner
                                    ->where('description', 'not like', $pattern)
                                    ->where('counterpart_name', 'not like', $pattern);
                            }
                        });
                });
        }

        $paymentStatus = $filters['payment_status'] ?? null;
        if ($paymentStatus === 'paid') {
            $query->where(function (Builder $builder): void {
                $builder
                    // Outgoing sums are paid by definition (money already left the account).
                    ->where('direction', BankStatementDirection::Debit->value)
                    ->orWhere(function (Builder $incomePaid): void {
                        $incomePaid
                            ->where(function (Builder $unlinked): void {
                                $unlinked->whereNull('invoice_id')->whereNull('payment_id');
                            })
                            ->orWhereHas('invoice', fn (Builder $invoiceQuery): Builder => $this->scopeFullyPaidInvoices($invoiceQuery))
                            ->orWhereHas('payment', fn (Builder $paymentQuery): Builder => $paymentQuery->where('paid', true));
                    });
            });
        } elseif ($paymentStatus === 'partly_paid') {
            $query
                ->where('direction', BankStatementDirection::Credit->value)
                ->whereHas('invoice', fn (Builder $invoiceQuery): Builder => $this->scopePartlyPaidInvoices($invoiceQuery));
        } elseif ($paymentStatus === 'settled') {
            $query
                ->where('overpayment_refunded', true)
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereNotNull('invoice_id')
                        ->orWhereHas('invoices');
                });
        } elseif ($paymentStatus === 'overpaid' || $paymentStatus === 'underpaid') {
            // Computed in getMonthGroups() from bank amount vs linked invoice coverage.
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('invoice_id')
                    ->orWhereHas('invoices');
            });
        } elseif ($paymentStatus === 'refund' || $paymentStatus === 'refunded') {
            $direction = $paymentStatus === 'refund'
                ? BankStatementDirection::Debit
                : BankStatementDirection::Credit;

            $query
                ->where('direction', $direction->value)
                ->where(function (Builder $builder): void {
                    foreach (['%refund%', '%grąžinim%', '%grazinim%'] as $pattern) {
                        $builder
                            ->orWhere('description', 'like', $pattern)
                            ->orWhere('counterpart_name', 'like', $pattern)
                            ->orWhere('comment', 'like', $pattern);
                    }
                });
        } elseif ($paymentStatus === 'unpaid') {
            $query
                ->where('direction', BankStatementDirection::Credit->value)
                ->where(function (Builder $builder): void {
                    $builder
                        ->whereHas('invoice', fn (Builder $invoiceQuery): Builder => $this->scopeUnpaidInvoices($invoiceQuery))
                        ->orWhereHas('payment', fn (Builder $paymentQuery): Builder => $paymentQuery->where(function (Builder $payment): void {
                            $payment->where('paid', false)->orWhereNull('paid');
                        }));
                });
        }

        if (filled($filters['counterpart'] ?? null)) {
            $query->where('counterpart_name', (string) $filters['counterpart']);
        }

        $description = trim((string) ($filters['description'] ?? ''));
        if ($description !== '') {
            $query->where('description', 'like', '%'.$description.'%');
        }

        $commentFilter = $filters['comment'] ?? null;
        if ($commentFilter === 'with') {
            $query->whereNotNull('comment')->where('comment', '!=', '');
        } elseif ($commentFilter === 'without') {
            $query->where(function (Builder $builder): void {
                $builder
                    ->whereNull('comment')
                    ->orWhere('comment', '');
            });
        }
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function scopeFullyPaidInvoices(Builder $query): Builder
    {
        return $query->where(function (Builder $builder): void {
            $builder
                ->where('paid', true)
                ->orWhereRaw('ABS(COALESCE(paid_amount, 0) - sum_inc_vat) < 0.005 AND sum_inc_vat > 0');
        });
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function scopePartlyPaidInvoices(Builder $query): Builder
    {
        return $query
            ->whereRaw('COALESCE(paid_amount, 0) > 0.004')
            ->where(function (Builder $builder): void {
                $builder
                    ->where('paid', false)
                    ->orWhereNull('paid');
            })
            ->whereRaw('ABS(COALESCE(paid_amount, 0) - sum_inc_vat) >= 0.005');
    }

    /**
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    private function scopeUnpaidInvoices(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $builder): void {
                $builder
                    ->where('paid', false)
                    ->orWhereNull('paid');
            })
            ->whereRaw('COALESCE(paid_amount, 0) <= 0.004');
    }

    /**
     * @return array<string, string>
     */
    private function bankAccountOptions(?string $search = null): array
    {
        $query = BankStatementTransaction::query()
            ->whereNotNull('account_iban')
            ->where('account_iban', '!=', '');

        $monthKey = $this->selectedMonthKey();
        if ($monthKey !== null) {
            $query->where('month_key', $monthKey);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('account_iban', 'like', '%'.$search.'%')
                    ->orWhere('bank_name', 'like', '%'.$search.'%');
            });
        }

        return $query
            ->selectRaw('account_iban, MAX(bank_name) as bank_name')
            ->groupBy('account_iban')
            ->orderBy('account_iban')
            ->limit(80)
            ->get()
            ->mapWithKeys(function (BankStatementTransaction $row): array {
                $iban = (string) $row->account_iban;
                $companyAccount = CompanyBankAccount::resolveFromStatementAccount($iban);
                if ($companyAccount !== null) {
                    return [$companyAccount->iban => $companyAccount->displayLabel()];
                }

                $bank = trim((string) ($row->bank_name ?? ''));
                $label = $bank !== '' ? $bank.' · '.$iban : $iban;

                return [$iban => $label];
            })
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function counterpartOptions(?string $search = null): array
    {
        $query = BankStatementTransaction::query()
            ->whereNotNull('counterpart_name')
            ->where('counterpart_name', '!=', '')
            ->orderBy('counterpart_name');

        $monthKey = $this->selectedMonthKey();
        if ($monthKey !== null) {
            $query->where('month_key', $monthKey);
        }

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where('counterpart_name', 'like', '%'.$search.'%');
        }

        return $query
            ->select('counterpart_name')
            ->distinct()
            ->limit(80)
            ->pluck('counterpart_name', 'counterpart_name')
            ->all();
    }

    /**
     * Lines that still need an invoice/contract (same rules as Link = Needs document).
     * Other active filters (account, direction, counterpart, description) are still applied.
     *
     * @return Collection<int, BankStatementTransaction>
     */
    private function missingDocumentTransactions(): Collection
    {
        $previous = $this->filters;
        $filters = is_array($previous) ? $previous : [];
        $filters['link_status'] = 'missing';
        $this->filters = $filters;

        try {
            $query = BankStatementTransaction::query()->with('import');
            $this->applyFilters($query);

            return $query
                ->orderBy('booking_date')
                ->orderBy('id')
                ->get();
        } finally {
            $this->filters = $previous;
        }
    }

    private function exportMissingDocumentsExcel(): StreamedResponse
    {
        $transactions = $this->missingDocumentTransactions();
        $fileName = 'bank-statements-missing-'.now()->format('Ymd-His').'.xls';

        ActivityLogger::logReportDownloaded('Bank statements missing documents XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => $transactions->count(),
        ]);

        return response()->streamDownload(function () use ($transactions): void {
            echo '<table border="1"><tr>';
            foreach ([
                'Payment date',
                'From',
                'To',
                'Description',
                'Direction',
                'Income',
                'Outcome',
            ] as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            $incomeTotal = 0.0;
            $outcomeTotal = 0.0;

            foreach ($transactions as $transaction) {
                $isIncome = (bool) $transaction->direction?->isIncome();
                $amount = (float) $transaction->amount;
                if ($isIncome) {
                    $incomeTotal += $amount;
                } else {
                    $outcomeTotal += $amount;
                }

                echo '<tr>';
                echo '<td>'.e($transaction->booking_date?->format('Y-m-d') ?? '').'</td>';
                echo '<td>'.e($transaction->partyLine($transaction->fromParty())).'</td>';
                echo '<td>'.e($transaction->partyLine($transaction->toParty())).'</td>';
                echo '<td>'.e((string) ($transaction->description ?? '')).'</td>';
                echo '<td>'.e($transaction->direction?->label() ?? '').'</td>';
                echo '<td>'.e($isIncome ? number_format($amount, 2, '.', '') : '').'</td>';
                echo '<td>'.e(! $isIncome ? number_format($amount, 2, '.', '') : '').'</td>';
                echo '</tr>';
            }

            echo '<tr>';
            echo '<th colspan="5">Total</th>';
            echo '<th>'.e(number_format($incomeTotal, 2, '.', '')).'</th>';
            echo '<th>'.e(number_format($outcomeTotal, 2, '.', '')).'</th>';
            echo '</tr></table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }
}
