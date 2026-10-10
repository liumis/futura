<?php

namespace App\Filament\Admin\Pages;

use App\Enums\InvoiceLanguage;
use App\Filament\Admin\Concerns\ManagesInvoiceUploads;
use App\Models\BankStatementTransaction;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceFinanceLine;
use App\Models\OriginCountry;
use App\Services\ActivityLogger;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\InvoiceDefaultFinanceLines;
use App\Services\InvoiceMailer;
use App\Services\InvoicePdfGenerator;
use App\Services\InvoiceSepaExporter;
use App\Support\Money;
use App\Support\ReportDateRange;
use App\Support\UploadLimits;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class Invoices extends Page implements HasForms
{
    use InteractsWithForms;
    use ManagesInvoiceUploads;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.invoices';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $filters = [];

    /**
     * @var list<string>
     */
    public array $selectedInvoiceIds = [];

    public bool $selectAllInvoices = false;

    public string $sortColumn = 'upload_date';

    public string $sortDirection = 'desc';

    public function mount(): void
    {
        // Month starts empty in the UI; the list soft-defaults to this month until
        // the user picks a month or widens the search (counterpart / invoice no).
        $filters = [];

        $contactId = request()->query('contact_id')
            ?? data_get(request()->query('filters'), 'contact_id');

        if (filled($contactId) && is_numeric($contactId)) {
            $filters['contact_id'] = (int) $contactId;
        }

        $queryFilters = request()->query('filters');
        if (is_array($queryFilters)) {
            if (array_key_exists('month', $queryFilters) && $queryFilters['month'] !== null && $queryFilters['month'] !== '') {
                $filters['month'] = (string) $queryFilters['month'];
            }
            if (filled($queryFilters['invoice_number'] ?? null)) {
                $filters['invoice_number'] = trim((string) $queryFilters['invoice_number']);
            }
        }

        $editInvoiceId = request()->query('edit_invoice');
        if (is_numeric($editInvoiceId)) {
            $filters['month'] = 'any';
            $invoiceNumber = Invoice::query()->whereKey((int) $editInvoiceId)->value('invoice_number');
            $filters['invoice_number'] = filled($invoiceNumber)
                ? (string) $invoiceNumber
                : (string) $editInvoiceId;
        }

        $this->filters = $filters;
        $this->filtersForm->fill($filters);

        if (is_numeric($editInvoiceId)) {
            $this->mountAction('invoiceDetails', ['invoiceId' => (int) $editInvoiceId]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'filtersForm',
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('scanSuggestedPayments')
                ->label('Scan for payments')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->color('primary')
                ->tooltip('Find unlinked bank payments that match unpaid invoices in the current month')
                ->action(fn () => $this->scanSuggestedPayments()),

            Action::make('confirmAllSuggestedPayments')
                ->label('Confirm all suggested')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->tooltip('Link every currently suggested bank payment for the filtered invoices')
                ->requiresConfirmation()
                ->modalHeading('Confirm all suggested payments')
                ->modalDescription('This will link every invoice that currently has a suggested bank payment (respecting active filters).')
                ->modalSubmitActionLabel('Confirm all')
                ->action(fn () => $this->confirmAllSuggestedPayments()),

            Action::make('newInvoice')
                ->label('New invoice')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->url(NewInvoice::getUrl()),
            $this->makeBankPaymentAction(),
            $this->exportSelectedExcelAction(),
            $this->massMarkAsPaidAction(),
            $this->massSendEmailAction(),
            $this->massChangeCategoryAction(),
        ];
    }

    public function exportSelectedExcelAction(): Action
    {
        return Action::make('exportSelectedExcel')
            ->label(fn (): string => $this->selectedInvoiceCount() > 0
                ? 'Export to Excel ('.$this->selectedInvoiceCount().')'
                : 'Export to Excel')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->disabled(fn (): bool => $this->selectedInvoiceCount() < 1)
            ->action(fn (): mixed => $this->exportSelectedExcel());
    }

    public function makeBankPaymentAction(): Action
    {
        return Action::make('makeBankPayment')
            ->label(fn (): string => $this->selectedInvoiceCount() > 0
                ? 'Make bank payment ('.$this->selectedInvoiceCount().')'
                : 'Make bank payment')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->disabled(fn (): bool => $this->selectedInvoiceCount() < 1)
            ->action(fn (): mixed => $this->makeBankPayment());
    }

    public function massMarkAsPaidAction(): Action
    {
        return Action::make('massMarkAsPaid')
            ->label(fn (): string => $this->selectedInvoiceCount() > 0
                ? 'Mark as paid ('.$this->selectedInvoiceCount().')'
                : 'Mark as paid')
            ->icon('heroicon-o-check-circle')
            ->color('gray')
            ->disabled(fn (): bool => $this->selectedInvoiceCount() < 1)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => 'Mark '.$this->selectedInvoiceCount().' invoice(s) as paid')
            ->modalDescription('Paid amount will be set to each invoice total.')
            ->modalSubmitActionLabel('Mark as paid')
            ->action(function (): void {
                $invoices = $this->selectedInvoices();
                if ($invoices->isEmpty()) {
                    Notification::make()
                        ->title('No invoice selected')
                        ->warning()
                        ->send();

                    return;
                }

                $updated = 0;
                foreach ($invoices as $invoice) {
                    if ($invoice->isFullyPaid()) {
                        continue;
                    }

                    $invoice->markFullyPaid();
                    $updated++;
                }

                if ($updated === 0) {
                    Notification::make()
                        ->title('Already paid')
                        ->body('All selected invoices are already marked as paid.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Marked as paid')
                    ->body($updated.' invoice(s) updated.')
                    ->success()
                    ->send();
            });
    }

    public function massSendEmailAction(): Action
    {
        return Action::make('massSendEmail')
            ->label(fn (): string => $this->selectedInvoiceCount() > 0
                ? 'Send email ('.$this->selectedInvoiceCount().')'
                : 'Send email')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->disabled(fn (): bool => $this->selectedInvoiceCount() < 1)
            ->modalHeading(fn (): string => 'Send email to '.$this->selectedInvoiceCount().' invoice(s)')
            ->modalDescription('Each invoice is sent to its own default recipient (order customer or contact email), unless you override the address below. Use {number} in subject/body for the invoice number.')
            ->modalSubmitActionLabel('Send emails')
            ->schema([
                TextInput::make('email')
                    ->label('Override email (optional)')
                    ->email()
                    ->maxLength(255)
                    ->helperText('Leave empty to use each invoice’s default recipient.'),

                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(255)
                    ->default('Invoice {number}'),

                Select::make('invoice_language')
                    ->label('Invoice language')
                    ->options(InvoiceLanguage::options())
                    ->default(InvoiceLanguage::Lithuanian->value)
                    ->native(false)
                    ->required(),

                Textarea::make('body')
                    ->label('Text')
                    ->rows(10)
                    ->required()
                    ->default("Hello,\n\nPlease find your invoice {number}.\n\nThank you for your business.")
                    ->columnSpanFull(),

                Checkbox::make('attach_invoice')
                    ->label('Attach invoice')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $invoices = $this->selectedInvoices(['order.user', 'contact']);
                if ($invoices->isEmpty()) {
                    Notification::make()
                        ->title('No invoice selected')
                        ->warning()
                        ->send();

                    return;
                }

                $overrideEmail = trim((string) ($data['email'] ?? ''));
                $subjectTemplate = (string) ($data['subject'] ?? '');
                $bodyTemplate = (string) ($data['body'] ?? '');
                $attach = (bool) ($data['attach_invoice'] ?? false);
                $language = InvoiceLanguage::normalize($data['invoice_language'] ?? null);

                $sent = 0;
                $skipped = [];
                $failed = [];

                foreach ($invoices as $invoice) {
                    $email = $overrideEmail !== ''
                        ? $overrideEmail
                        : (InvoiceMailer::defaultRecipientEmail($invoice) ?? '');

                    if ($email === '') {
                        $skipped[] = $invoice->displayNumber();

                        continue;
                    }

                    $number = filled($invoice->invoice_number)
                        ? (string) $invoice->invoice_number
                        : '#'.$invoice->getKey();

                    try {
                        InvoiceMailer::send(
                            $invoice,
                            $email,
                            str_replace('{number}', $number, $subjectTemplate),
                            str_replace('{number}', $number, $bodyTemplate),
                            $attach,
                            $language,
                        );
                        $sent++;
                    } catch (Throwable $exception) {
                        $failed[] = $invoice->displayNumber().': '.$exception->getMessage();
                    }
                }

                if ($sent > 0) {
                    Notification::make()
                        ->title($sent.' email(s) sent')
                        ->body(trim(
                            ($skipped !== [] ? 'Skipped (no email): '.implode(', ', $skipped).'. ' : '')
                            .($failed !== [] ? 'Failed: '.implode(' · ', array_slice($failed, 0, 5)) : '')
                        ) ?: null)
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('No emails were sent')
                    ->body(trim(
                        ($skipped !== [] ? 'Missing recipient: '.implode(', ', $skipped).'. ' : '')
                        .($failed !== [] ? implode(' · ', array_slice($failed, 0, 5)) : '')
                    ) ?: 'Check recipients and try again.')
                    ->warning()
                    ->send();
            });
    }

    public function massChangeCategoryAction(): Action
    {
        return Action::make('massChangeCategory')
            ->label(fn (): string => $this->selectedInvoiceCount() > 0
                ? 'Set category ('.$this->selectedInvoiceCount().')'
                : 'Set category')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->disabled(fn (): bool => $this->selectedInvoiceCount() < 1)
            ->modalHeading(fn (): string => 'Set category for '.$this->selectedInvoiceCount().' invoice(s)')
            ->modalSubmitActionLabel('Apply')
            ->schema([
                Select::make('finance_type')
                    ->label('Category')
                    ->options(fn (): array => Invoice::financeCategorySelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Leave empty to clear the category (Missing type).'),
            ])
            ->action(function (array $data): void {
                $invoices = $this->selectedInvoices();
                if ($invoices->isEmpty()) {
                    Notification::make()
                        ->title('No invoice selected')
                        ->warning()
                        ->send();

                    return;
                }

                $value = isset($data['finance_type']) && is_string($data['finance_type'])
                    ? $data['finance_type']
                    : null;
                if ($value === '') {
                    $value = null;
                }

                foreach ($invoices as $invoice) {
                    $invoice->applyFinanceCategory($value);
                }

                Notification::make()
                    ->title('Category updated')
                    ->body($invoices->count().' invoice(s) updated.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @param  list<string>  $with
     * @return Collection<int, Invoice>
     */
    private function selectedInvoices(array $with = []): Collection
    {
        $ids = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return new Collection;
        }

        $query = Invoice::query()->whereIn('id', $ids)->orderBy('id');
        if ($with !== []) {
            $query->with($with);
        }

        return $query->get();
    }

    private function selectedInvoiceCount(): int
    {
        return collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();
    }

    public function makeBankPayment(): ?StreamedResponse
    {
        $ids = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('No invoice selected')
                ->body('Select at least one invoice, then try again.')
                ->warning()
                ->send();

            return null;
        }

        $invoices = Invoice::query()
            ->with('contact')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $invoice->outstandingAmount() > 0)
            ->values();

        if ($invoices->isEmpty()) {
            Notification::make()
                ->title('Nothing left to pay')
                ->body('Selected invoices are already paid.')
                ->warning()
                ->send();

            return null;
        }

        try {
            $xml = app(InvoiceSepaExporter::class)->buildXml($invoices);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not make bank payment')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $filename = 'sepa-invoices-'.now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded('SEPA invoice payment', 'xml', properties: [
            'file_name' => $filename,
            'invoice_ids' => $invoices->modelKeys(),
            'count' => $invoices->count(),
        ]);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function exportSelectedExcel(): ?StreamedResponse
    {
        $ids = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('No invoice selected')
                ->body('Select at least one invoice, then try again.')
                ->warning()
                ->send();

            return null;
        }

        $invoices = $this->selectedInvoices([
            'contact',
            'order.user',
            'incomeType',
            'expenseType',
        ])->sortBy(fn (Invoice $invoice): int => array_search((int) $invoice->getKey(), $ids, true) ?: 0)
            ->values();

        if ($invoices->isEmpty()) {
            Notification::make()
                ->title('No invoice selected')
                ->warning()
                ->send();

            return null;
        }

        $fileName = 'invoices-'.now()->format('Ymd-His').'.xls';

        ActivityLogger::logReportDownloaded('Invoices Excel export', 'xls', properties: [
            'file_name' => $fileName,
            'invoice_ids' => $invoices->modelKeys(),
            'count' => $invoices->count(),
        ]);

        $headers = [
            'ID',
            'Counterpart',
            'Country',
            'Invoice no',
            'Invoice date',
            'Type',
            'Category',
            'Currency',
            'Sum without VAT',
            'VAT',
            'Sum inc. VAT',
            'Paid amount',
            'Outstanding',
            'Payment status',
            'CMR',
            'Comment',
        ];

        $rows = $invoices->map(function (Invoice $invoice): array {
            $kind = $invoice->financeCategoryKind();
            $typeLabel = match ($kind) {
                'income' => 'Income',
                'expense' => 'Expense',
                Invoice::OWN_ACCOUNT_FILTER => Invoice::OWN_ACCOUNT_LABEL,
                default => '',
            };
            $category = Invoice::financeCategoryLabel($invoice->storedFinanceTypeValue())
                ?? $invoice->incomeType?->name
                ?? $invoice->expenseType?->name
                ?? '';

            $paymentStatus = $invoice->isFullyPaid()
                ? 'Paid'
                : ($invoice->isPartlyPaid() ? 'Partly paid' : 'Unpaid');

            $cmr = match (true) {
                $invoice->isHistoricalRecord() => 'History',
                $invoice->isMissingCmrDocument() => 'Missing',
                $invoice->requiresCmrDocument() => 'Attached',
                default => 'Not required',
            };

            $country = trim((string) (
                $invoice->contact?->company_country
                ?: $invoice->order?->user?->company_country
                ?: ''
            ));

            return [
                (string) $invoice->getKey(),
                $invoice->counterpartLabel(),
                $country,
                $invoice->displayNumber(),
                optional($invoice->invoice_date)->format('Y-m-d') ?? '',
                $typeLabel,
                $category,
                $invoice->currencyCode(),
                number_format((float) $invoice->sum_without_vat, 2, '.', ''),
                number_format((float) $invoice->vat, 2, '.', ''),
                number_format((float) $invoice->sum_inc_vat, 2, '.', ''),
                number_format((float) ($invoice->paid_amount ?? 0), 2, '.', ''),
                number_format($invoice->outstandingAmount(), 2, '.', ''),
                $paymentStatus,
                $cmr,
                (string) ($invoice->comment ?? ''),
            ];
        })->all();

        return response()->streamDownload(function () use ($headers, $rows): void {
            echo "\xEF\xBB\xBF";
            echo '<table border="1"><thead><tr>';
            foreach ($headers as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td>'.e((string) $cell).'</td>';
                }
                echo '</tr>';
            }

            echo '</tbody></table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                Grid::make(12)
                    ->schema([
                        Select::make('contact_id')
                            ->label('Counterpart')
                            ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->placeholder('All counterparts')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 12, 'md' => 5]),

                        TextInput::make('invoice_number')
                            ->label('Invoice no')
                            ->placeholder('Number or ID…')
                            ->live(debounce: 400)
                            ->columnSpan(['default' => 12, 'md' => 4]),

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
                        Select::make('finance_kind')
                            ->label('Type')
                            ->options([
                                'income' => 'Income',
                                'expense' => 'Expense',
                                Invoice::OWN_ACCOUNT_FILTER => Invoice::OWN_ACCOUNT_LABEL,
                                'missing' => 'Missing type',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                if ($state === 'missing') {
                                    $set('finance_type', null);

                                    return;
                                }

                                if ($state === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_type', Invoice::OWN_ACCOUNT_FILTER);

                                    return;
                                }

                                $type = $get('finance_type');
                                if (! filled($type) || $state === null) {
                                    return;
                                }

                                if ($type === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_type', null);

                                    return;
                                }

                                $prefix = str_starts_with((string) $type, 'income:') ? 'income' : 'expense';
                                if ($state !== $prefix) {
                                    $set('finance_type', null);
                                }
                            })
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('finance_type')
                            ->label('Category')
                            ->options(fn (): array => Invoice::financeCategorySelectOptions())
                            ->searchable()
                            ->preload()
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->visible(fn (Get $get): bool => ($get('finance_kind') ?? null) !== 'missing')
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! filled($state)) {
                                    return;
                                }

                                if ($state === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_kind', Invoice::OWN_ACCOUNT_FILTER);

                                    return;
                                }

                                $set(
                                    'finance_kind',
                                    str_starts_with($state, 'income:') ? 'income' : 'expense',
                                );
                            })
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('customer_market')
                            ->label('Market')
                            ->options([
                                'lithuania' => 'Lithuania',
                                'export' => 'Export',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('customer_country')
                            ->label('Country')
                            ->options(fn (): array => OriginCountry::options())
                            ->searchable()
                            ->preload()
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),
                    ]),

                Grid::make(12)
                    ->schema([
                        Select::make('payment_status')
                            ->label('Payment')
                            ->options([
                                'paid' => 'Paid',
                                'partly_paid' => 'Partly paid',
                                'unpaid' => 'Not paid',
                                'suggested' => 'Suggested bank payment',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('cmr')
                            ->label('CMR')
                            ->options([
                                'missing' => 'Missing (export)',
                                'attached' => 'Attached (export)',
                                'history' => 'History (no CMR needed)',
                                'not_required' => 'Not required (LT)',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('comment')
                            ->label('Comment')
                            ->options([
                                'with' => 'With comment',
                                'without' => 'No comment',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),

                        Select::make('private_provider')
                            ->label('Private provider')
                            ->options([
                                '1' => 'Yes',
                                '0' => 'No',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->columnSpan(['default' => 6, 'md' => 3]),
                    ]),
            ]);
    }

    public function regeneratePdf(int $invoiceId): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        try {
            InvoicePdfGenerator::refreshSalesInvoicePdf($invoice);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('PDF could not be generated')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Invoice PDF regenerated')
            ->success()
            ->send();
    }

    public function sendInvoiceEmailAction(): Action
    {
        return Action::make('sendInvoiceEmail')
            ->modalHeading('Send invoice email')
            ->modalSubmitActionLabel('Send email')
            ->schema([
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255),

                TextInput::make('subject')
                    ->label('Subject')
                    ->required()
                    ->maxLength(255),

                Select::make('invoice_language')
                    ->label('Invoice language')
                    ->options(InvoiceLanguage::options())
                    ->native(false)
                    ->required(),

                Textarea::make('body')
                    ->label('Text')
                    ->rows(10)
                    ->required()
                    ->columnSpanFull(),

                Checkbox::make('attach_invoice')
                    ->label('Attach invoice')
                    ->default(true),
            ])
            ->fillForm(function (array $arguments): array {
                $invoice = $this->resolveInvoiceForEmail($arguments['invoiceId'] ?? null);

                return [
                    'email' => InvoiceMailer::defaultRecipientEmail($invoice) ?? '',
                    'subject' => InvoiceMailer::defaultSubject($invoice),
                    'invoice_language' => InvoiceLanguage::normalize($invoice->order?->user?->invoice_language)->value,
                    'body' => InvoiceMailer::defaultBody($invoice),
                    'attach_invoice' => true,
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $invoice = $this->resolveInvoiceForEmail($arguments['invoiceId'] ?? null);

                try {
                    InvoiceMailer::send(
                        $invoice,
                        (string) ($data['email'] ?? ''),
                        (string) ($data['subject'] ?? ''),
                        (string) ($data['body'] ?? ''),
                        (bool) ($data['attach_invoice'] ?? false),
                        InvoiceLanguage::normalize($data['invoice_language'] ?? null),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Email was not sent')
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Invoice email sent')
                    ->success()
                    ->send();
            });
    }

    public function linkBankPaymentAction(): Action
    {
        return Action::make('linkBankPayment')
            ->modalHeading(function (array $arguments): string {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);

                return 'Link bank payment — '.$invoice->displayNumber();
            })
            ->modalSubmitActionLabel('Link')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('xl')
            ->schema([
                Select::make('transaction_id')
                    ->label('Bank payment')
                    ->helperText('Same links as on Bank statements. Search by counterpart, description, or amount.')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->options(function (): array {
                        $invoiceId = $this->getMountedAction()?->getArguments()['invoiceId'] ?? null;
                        $invoice = $this->resolveInvoiceRecord($invoiceId);

                        return BankStatementInvoiceMatcher::pickerOptionsForInvoice($invoice);
                    })
                    ->getSearchResultsUsing(function (string $search): array {
                        $invoiceId = $this->getMountedAction()?->getArguments()['invoiceId'] ?? null;
                        $invoice = is_numeric($invoiceId) ? Invoice::query()->find((int) $invoiceId) : null;

                        return BankStatementInvoiceMatcher::searchUnlinkedTransactions($search, $invoice);
                    })
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $transaction = BankStatementTransaction::query()->find((int) $value);

                        return $transaction
                            ? BankStatementInvoiceMatcher::transactionPickerLabel($transaction)
                            : null;
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                $suggested = BankStatementInvoiceMatcher::suggestTransactionsForInvoice($invoice, 1)->first();

                return [
                    'transaction_id' => $suggested?->getKey(),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                $transactionId = $data['transaction_id'] ?? null;

                if (! is_numeric($transactionId)) {
                    Notification::make()
                        ->title('Select a bank payment')
                        ->danger()
                        ->send();

                    return;
                }

                $transaction = BankStatementTransaction::query()->find((int) $transactionId);
                if ($transaction === null) {
                    Notification::make()
                        ->title('Bank payment not found')
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    BankStatementInvoiceMatcher::link($transaction, $invoice);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Bank payment was not linked')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Bank payment linked')
                    ->body(BankStatementInvoiceMatcher::transactionPickerLabel($transaction->fresh() ?? $transaction))
                    ->success()
                    ->send();
            });
    }

    public function unlinkBankPayment(int $invoiceId, int $transactionId): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);
        $transaction = BankStatementTransaction::query()
            ->with('invoices')
            ->find($transactionId);

        if ($transaction === null) {
            Notification::make()
                ->title('Bank payment not found')
                ->danger()
                ->send();

            return;
        }

        BankStatementInvoiceMatcher::unlinkInvoice($transaction, $invoice);

        Notification::make()
            ->title('Bank payment unlinked')
            ->success()
            ->send();
    }

    public function confirmSuggestedBankPayment(int $invoiceId): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);
        $suggested = BankStatementInvoiceMatcher::suggestTransactionsForInvoice($invoice, 1)->first();

        if ($suggested === null) {
            Notification::make()
                ->title('No suggested bank payment')
                ->warning()
                ->send();

            return;
        }

        try {
            BankStatementInvoiceMatcher::link($suggested, $invoice);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Bank payment was not linked')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Bank payment linked')
            ->body(BankStatementInvoiceMatcher::transactionPickerLabel($suggested->fresh() ?? $suggested))
            ->success()
            ->send();
    }

    public function scanSuggestedPayments(): void
    {
        $filtersBackup = $this->filters;
        $scanFilters = $this->filters ?? [];
        unset($scanFilters['payment_status']);
        $this->filters = $scanFilters;

        $invoices = $this->getInvoices();
        $this->filters = $filtersBackup;

        $suggestedCount = $invoices
            ->filter(fn (Invoice $invoice): bool => $invoice->relationLoaded('suggestedBankPayment')
                && $invoice->getRelation('suggestedBankPayment') !== null)
            ->count();

        $nextFilters = [
            ...($this->filters ?? []),
            'payment_status' => $suggestedCount > 0
                ? 'suggested'
                : ($this->filters['payment_status'] ?? null),
        ];
        $this->filters = $nextFilters;
        $this->filtersForm->fill($nextFilters);

        if ($suggestedCount === 0) {
            Notification::make()
                ->title('No suggested payments found')
                ->body('No confident bank-payment matches for unpaid invoices in the current month/filters.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Suggested payments ready')
            ->body($suggestedCount.' invoice'.($suggestedCount === 1 ? '' : 's').' can be linked. Review each row or use Confirm all suggested.')
            ->success()
            ->send();
    }

    public function confirmAllSuggestedPayments(): void
    {
        $invoices = $this->getInvoices()
            ->filter(fn (Invoice $invoice): bool => $invoice->relationLoaded('suggestedBankPayment')
                && $invoice->getRelation('suggestedBankPayment') !== null)
            ->values();

        if ($invoices->isEmpty()) {
            Notification::make()
                ->title('Nothing was linked')
                ->body('Run Scan for payments first, or confirm suggestions one by one.')
                ->warning()
                ->send();

            return;
        }

        $linked = 0;

        foreach ($invoices as $invoice) {
            /** @var BankStatementTransaction|null $transaction */
            $transaction = $invoice->getRelation('suggestedBankPayment');
            if ($transaction === null || $invoice->isFullyPaid()) {
                continue;
            }

            try {
                BankStatementInvoiceMatcher::link($transaction, $invoice);
                $linked++;
            } catch (Throwable) {
                // Skip conflicts; remaining suggestions stay available after refresh.
            }
        }

        if ($linked === 0) {
            Notification::make()
                ->title('Nothing was linked')
                ->body('Suggestions may already be linked or no longer match.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Suggested payments saved')
            ->body($linked.' invoice(s) linked to bank payments.')
            ->success()
            ->send();
    }

    public function markInvoicePaid(int $invoiceId): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);

        if ($invoice->isFullyPaid()) {
            return;
        }

        $invoice->markFullyPaid();

        Notification::make()
            ->title('Marked as paid')
            ->body($invoice->displayNumber().' · '.\App\Support\Money::format(
                (float) $invoice->paid_amount,
                2,
                $invoice->currencyCode(),
            ))
            ->success()
            ->send();
    }

    public function markInvoiceUnpaid(int $invoiceId): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);

        if (! $invoice->isFullyPaid() && ! $invoice->isPartlyPaid()) {
            return;
        }

        $invoice->markUnpaid();

        Notification::make()
            ->title('Marked as unpaid')
            ->body($invoice->displayNumber())
            ->success()
            ->send();
    }

    public function toggleInvoiceHistory(int $invoiceId): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);
        $next = ! $invoice->isHistoricalRecord();
        $invoice->forceFill(['is_history' => $next])->save();

        Notification::make()
            ->title($next ? 'Marked as history' : 'History cleared')
            ->body($next
                ? $invoice->displayNumber().' — CMR not required'
                : $invoice->displayNumber())
            ->success()
            ->send();
    }

    public function uploadAdditionalDocumentsAction(): Action
    {
        return Action::make('uploadAdditionalDocuments')
            ->modalHeading(function (array $arguments): string {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                $label = $invoice->requiresCmrDocument() ? 'CMR' : 'Additional documents';

                return $label.' — '.$invoice->displayNumber();
            })
            ->modalDescription(function (array $arguments): string {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                if ($invoice->requiresCmrDocument()) {
                    return 'Upload CMR for this export sale (customer abroad, 0% VAT). Files are stored on SharePoint with this invoice.';
                }

                return 'Upload CMR or other files the customer sent after receiving the goods. Files are stored on SharePoint with this invoice. Not required for LT customers.';
            })
            ->modalSubmitActionLabel('Upload')
            ->schema([
                Placeholder::make('existing_additional_documents')
                    ->label('Current files')
                    ->content(function (): HtmlString {
                        $invoiceId = $this->mountedInvoiceId();
                        if ($invoiceId === null) {
                            return new HtmlString('<span class="text-sm text-gray-500">No files yet.</span>');
                        }

                        return $this->additionalDocumentsListHtml($this->resolveInvoiceRecord($invoiceId));
                    })
                    ->columnSpanFull(),

                FileUpload::make('additional_documents')
                    ->label(fn (): string => ($this->mountedInvoiceId() !== null
                        && $this->resolveInvoiceRecord($this->mountedInvoiceId())->requiresCmrDocument())
                        ? 'CMR file(s)'
                        : 'Additional documents')
                    ->multiple()
                    ->required()
                    ->acceptedFileTypes(self::additionalDocumentMimeTypes())
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices/additional')
                    ->storeFileNamesIn('additional_document_names')
                    ->helperText(UploadLimits::withExistingNote('PDF or images. Stored on SharePoint. Existing files are kept.'))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, array $arguments): void {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);

                try {
                    $count = $this->storeAdditionalDocuments(
                        $invoice,
                        $data['additional_documents'] ?? [],
                        $data['additional_document_names'] ?? [],
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('CMR / documents were not uploaded')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                if ($count < 1) {
                    Notification::make()
                        ->title('No files selected')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($count.' document(s) uploaded to SharePoint')
                    ->success()
                    ->send();
            });
    }

    public function invoiceDetailsAction(): Action
    {
        return Action::make('invoiceDetails')
            ->modalHeading(function (array $arguments): string {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);

                return 'Edit invoice'.(filled($invoice->invoice_number) ? ' — '.$invoice->invoice_number : ' #'.$invoice->getKey());
            })
            ->modalSubmitActionLabel('Save')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('5xl')
            ->extraModalFooterActions(fn (): array => [
                Action::make('deleteInvoice')
                    ->label('Delete')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->requiresConfirmation()
                    ->modalHeading('Delete invoice?')
                    ->modalDescription('The invoice will be removed from lists. Linked bank payments will be unlinked.')
                    ->modalSubmitActionLabel('Delete')
                    ->cancelParentActions()
                    ->action(function (): void {
                        $invoiceId = $this->mountedInvoiceId();
                        if ($invoiceId === null) {
                            return;
                        }

                        $this->softDeleteInvoice($invoiceId);

                        // Close edit + confirmation modals so Livewire does not
                        // re-resolve the soft-deleted invoice (findOrFail → 404).
                        $this->mountedActions = [];
                        $this->cachedMountedActions = null;
                    }),
            ])
            ->schema([
                View::make('filament.admin.components.invoice-details-header')
                    ->viewData(function (self $livewire): array {
                        try {
                            return $livewire->invoiceDetailsHeaderData();
                        } catch (\Throwable) {
                            return [
                                'issuer' => [
                                    'name' => null,
                                    'companyId' => null,
                                    'vat' => null,
                                    'address' => null,
                                    'email' => null,
                                    'phone' => null,
                                ],
                                'customer' => [
                                    'name' => null,
                                    'companyName' => null,
                                    'companyId' => null,
                                    'vat' => null,
                                    'address' => null,
                                    'email' => null,
                                    'phone' => null,
                                ],
                                'invoiceNumber' => '',
                                'invoiceDate' => '',
                                'orderNumber' => null,
                                'lines' => [],
                                'subtotal' => 0,
                                'shipping' => 0,
                                'sumWithoutVat' => 0,
                                'vat' => 0,
                                'sumIncVat' => 0,
                                'hasOrderTotals' => false,
                                'currency' => 'EUR',
                            ];
                        }
                    })
                    ->columnSpanFull(),

                Select::make('contact_id')
                    ->label('Company / supplier')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (mixed $state, Set $set): void {
                        $contact = Contact::query()->find($state);
                        $set('private_provider', (bool) ($contact?->private_provider));
                        $defaultCategory = $contact?->defaultFinanceTypeValue();
                        if ($defaultCategory !== null) {
                            $set('finance_type', $defaultCategory);
                        }
                    })
                    ->columnSpanFull(),

                TextInput::make('invoice_number')
                    ->label('Invoice no')
                    ->maxLength(255),

                DatePicker::make('invoice_date')
                    ->label('Invoice date')
                    ->required()
                    ->native(false),

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

                Placeholder::make('existing_additional_documents')
                    ->label('Additional documents')
                    ->content(function (): HtmlString {
                        $invoiceId = $this->mountedInvoiceId();
                        if ($invoiceId === null) {
                            return new HtmlString('<span class="text-sm text-gray-500">No files yet.</span>');
                        }

                        return $this->additionalDocumentsListHtml($this->resolveInvoiceRecord($invoiceId));
                    })
                    ->columnSpanFull(),

                FileUpload::make('additional_documents')
                    ->label('Add additional documents')
                    ->multiple()
                    ->acceptedFileTypes(self::additionalDocumentMimeTypes())
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices/additional')
                    ->storeFileNamesIn('additional_document_names')
                    ->helperText(UploadLimits::withExistingNote('CMR for export sales (abroad). Not required for LT. Existing files are kept on SharePoint.'))
                    ->columnSpanFull(),

                ...$this->invoicePaymentFormFields(
                    fn (Get $get): float => round((float) ($get('sum_inc_vat') ?? $this->mountedInvoiceTotalToPay()), 2),
                ),

                Select::make('finance_type')
                    ->label('Category')
                    ->options(fn (): array => Invoice::financeCategorySelectOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Sales invoices default to Income · Prekės. Own-account transfers use Savo sąskaita (neither income nor expense).'),

                Checkbox::make('private_provider')
                    ->label('Private provider')
                    ->helperText('Include this invoice in GPM312.')
                    ->inline(false),

                Textarea::make('comment')
                    ->label('Comment')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Actions::make([
                    Action::make('addDefaultLines')
                        ->label('Add default lines')
                        ->icon('heroicon-o-queue-list')
                        ->color('gray')
                        ->button()
                        ->action(function (Get $get, Set $set): void {
                            $invoice = $this->resolveInvoiceRecord($this->mountedInvoiceId());
                            $set('lines', InvoiceDefaultFinanceLines::buildForForm($invoice));

            Notification::make()
                                ->title('Default lines added')
                                ->success()
                ->send();
                        }),

                    Action::make('addExtraLine')
                        ->label('Add line')
                        ->icon('heroicon-o-plus')
                        ->color('gray')
                        ->button()
                        ->action(function (Get $get, Set $set): void {
                            $lines = $get('lines') ?? [];

                            if (! is_array($lines)) {
                                $lines = [];
                            }

                            $lines[(string) Str::uuid()] = [
                                'name' => '',
                                'invoice_code_id' => null,
                                'name_select' => null,
                                'debit' => 0,
                                'credit' => 0,
                            ];

                            $set('lines', $lines);
                        }),
                ])->columnSpanFull(),

                Repeater::make('lines')
                    ->label('Lines')
                    ->table([
                        TableColumn::make('Code')
                            ->width('9rem'),
                        TableColumn::make('Name'),
                        TableColumn::make('Debit')
                            ->alignment(Alignment::End)
                            ->width('9rem'),
                        TableColumn::make('Credit')
                            ->alignment(Alignment::End)
                            ->width('9rem'),
                    ])
                    ->schema([
                        Select::make('invoice_code_id')
                            ->label('Code')
                            ->options(fn (): array => $this->invoiceCodeOptionsByCode())
                            ->getSearchResultsUsing(fn (string $search): array => $this->invoiceCodeOptionsByCode($search))
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value)
                                ? (string) InvoiceCode::query()->whereKey($value)->value('code')
                                : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->extraAttributes(function (Get $get): array {
                                $id = $get('invoice_code_id');
                                $name = filled($id)
                                    ? InvoiceCode::query()->whereKey($id)->value('name')
                                    : null;

                                return filled($name)
                                    ? ['class' => 'invoice-code-select', 'title' => (string) $name]
                                    : ['class' => 'invoice-code-select'];
                            })
                            ->afterStateUpdated(function (?int $state, Set $set): void {
                                $this->syncInvoiceCodeSelections($state, $set);
                            }),

                        Select::make('name_select')
                            ->label('Name')
                            ->options(fn (): array => $this->invoiceCodeOptionsByName())
                            ->getSearchResultsUsing(fn (string $search): array => $this->invoiceCodeOptionsByName($search))
                            ->getOptionLabelUsing(fn ($value): ?string => filled($value)
                                ? (string) InvoiceCode::query()->whereKey($value)->value('name')
                                : null)
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->live()
                            ->dehydrated(false)
                            ->extraAttributes(function (Get $get): array {
                                $id = $get('name_select') ?: $get('invoice_code_id');
                                $code = filled($id)
                                    ? InvoiceCode::query()->whereKey($id)->value('code')
                                    : null;

                                return filled($code)
                                    ? ['class' => 'invoice-name-select', 'title' => (string) $code]
                                    : ['class' => 'invoice-name-select'];
                            })
                            ->afterStateUpdated(function (?int $state, Set $set): void {
                                $this->syncInvoiceCodeSelections($state, $set);
                            }),

                        TextInput::make('debit')
                            ->label('Debit')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->live(onBlur: true)
                            ->extraInputAttributes(['class' => 'text-right']),

                        TextInput::make('credit')
                            ->label('Credit')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->live(onBlur: true)
                            ->extraInputAttributes(['class' => 'text-right']),
                    ])
                    ->defaultItems(1)
                    ->reorderable()
                    ->addable()
                    ->deletable()
                    ->addActionLabel('Add line')
                    ->columnSpanFull(),

                Placeholder::make('totals')
                    ->hiddenLabel()
                    ->content(function (Get $get): HtmlString {
                        [$creditTotal, $debitTotal, $balanced] = $this->financeLineTotals($get('lines') ?? []);
                        $valid = $balanced;

                        $debitLabel = number_format($debitTotal, 2, ',', ' ');
                        $creditLabel = number_format($creditTotal, 2, ',', ' ');
                        $rowClass = $valid
                            ? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100'
                            : 'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300';

                        $messages = [];
                        if (! $balanced) {
                            $messages[] = 'Debit and credit totals must be equal.';
                        }

                        return new HtmlString(
                            '<div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">'
                            .'<table class="w-full text-sm">'
                            .'<tr class="'.$rowClass.'">'
                            .'<td class="w-9 px-3 py-2"></td>'
                            .'<td class="w-36 px-3 py-2 font-semibold"></td>'
                            .'<td class="px-3 py-2 font-semibold">TOTAL</td>'
                            .'<td class="w-36 px-3 py-2 text-right font-semibold tabular-nums">'.$debitLabel.'</td>'
                            .'<td class="w-36 px-3 py-2 text-right font-semibold tabular-nums">'.$creditLabel.'</td>'
                            .'<td class="w-10 px-2 py-2"></td>'
                            .'</tr>'
                            .'</table>'
                            .($messages === []
                                ? ''
                                : '<div class="border-t border-danger-200 px-3 py-2 text-xs font-medium text-danger-600 dark:border-danger-800 dark:text-danger-400">'
                                    .implode(' ', $messages)
                                    .'</div>')
                            .'</div>'
                        );
                    })
                    ->columnSpanFull(),
            ])
            ->fillForm(function (array $arguments): array {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                $invoice->loadMissing('financeLines');

                $lines = $invoice->financeLines
                    ->map(fn (InvoiceFinanceLine $line): array => [
                        'name' => $line->name,
                        'invoice_code_id' => $line->invoice_code_id,
                        'name_select' => $line->invoice_code_id,
                        'credit' => (float) $line->credit,
                        'debit' => (float) $line->debit,
                    ])
                    ->values()
                    ->all();

                return [
                    'contact_id' => $invoice->contact_id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => optional($invoice->invoice_date)?->toDateString(),
                    'sum_without_vat' => (float) $invoice->sum_without_vat,
                    'vat' => (float) $invoice->vat,
                    'sum_inc_vat' => (float) $invoice->sum_inc_vat,
                    'payment_term' => $invoice->payment_term,
                    'paid_amount' => (float) $invoice->paid_amount,
                    'paid' => (bool) $invoice->paid,
                    'currency' => $invoice->currencyCode(),
                    'currency_rate' => $invoice->currency_rate !== null ? (float) $invoice->currency_rate : null,
                    'sum_inc_vat_eur' => $invoice->sum_inc_vat_eur !== null ? (float) $invoice->sum_inc_vat_eur : $invoice->amountInEur(),
                    'private_provider' => (bool) $invoice->private_provider,
                    'comment' => $invoice->comment,
                    'finance_type' => $invoice->financeTypeFilterValue(),
                    'lines' => $lines !== [] ? $lines : [
                        [
                            'name' => '',
                            'invoice_code_id' => null,
                            'name_select' => null,
                            'credit' => 0,
                            'debit' => 0,
                        ],
                    ],
                ];
            })
            ->action(function (array $data, array $arguments): void {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);
                $lines = array_values(array_filter(
                    $data['lines'] ?? [],
                    fn (array $line): bool => filled($line['invoice_code_id'] ?? null),
                ));

                [$creditTotal, $debitTotal, $balanced] = $this->financeLineTotals($lines);

                $category = Invoice::parseFinanceTypeFilterValue(
                    isset($data['finance_type']) && is_string($data['finance_type'])
                        ? $data['finance_type']
                        : null,
                );

                $invoiceNumber = filled($data['invoice_number'] ?? null) ? trim((string) $data['invoice_number']) : null;
                if ($invoiceNumber !== null) {
                    $duplicate = Invoice::query()
                        ->where('invoice_number', $invoiceNumber)
                        ->whereKeyNot($invoice->getKey())
                        ->exists();
                    if ($duplicate) {
                Notification::make()
                            ->title('Invoice number already exists')
                            ->body('Choose a different invoice number.')
                            ->danger()
                    ->send();

                return;
                    }
                }

                try {
                    DB::transaction(function () use ($invoice, $lines, $data, $category, $invoiceNumber): void {
                        $invoice->forceFill([
                            'contact_id' => (int) ($data['contact_id'] ?? $invoice->contact_id),
                            'invoice_number' => $invoiceNumber,
                            'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
                            'sum_without_vat' => round((float) ($data['sum_without_vat'] ?? 0), 2),
                            'vat' => round((float) ($data['vat'] ?? 0), 2),
                            'sum_inc_vat' => round((float) ($data['sum_inc_vat'] ?? 0), 2),
                            'payment_term' => Invoice::resolvePaymentTerm($data['payment_term'] ?? null),
                            'paid_amount' => round((float) ($data['paid_amount'] ?? 0), 2),
                            'paid' => (bool) ($data['paid'] ?? false),
                            'currency' => Currency::normalize(isset($data['currency']) && is_string($data['currency']) ? $data['currency'] : null),
                            'private_provider' => (bool) ($data['private_provider'] ?? false),
                            'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
                            'income_type_id' => $category['income_type_id'],
                            'expense_type_id' => $category['expense_type_id'],
                            'is_own_account' => $category['is_own_account'],
                        ])->save();

                        $invoice->financeLines()->delete();

                        foreach ($lines as $index => $line) {
                            $codeId = (int) ($line['invoice_code_id'] ?? 0);
                            $name = (string) (
                                InvoiceCode::query()->whereKey($codeId)->value('name')
                                ?? ($line['name'] ?? '')
                            );

                            $invoice->financeLines()->create([
                                'name' => $name,
                                'invoice_code_id' => $codeId,
                                'credit' => round((float) ($line['credit'] ?? 0), 2),
                                'debit' => round((float) ($line['debit'] ?? 0), 2),
                                'sort_order' => $index,
                            ]);
                        }
                    });
                } catch (UniqueConstraintViolationException) {
                    Notification::make()
                        ->title('Invoice number already exists')
                        ->danger()
                        ->send();

                    return;
                }
                $uploadedCount = 0;
                try {
                    $uploadedCount = $this->storeAdditionalDocuments(
                        $invoice,
                        $data['additional_documents'] ?? [],
                        $data['additional_document_names'] ?? [],
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Invoice saved, but additional documents were not uploaded')
                        ->body($exception->getMessage())
                        ->warning()
                        ->send();
                }

                if (! $balanced) {
            Notification::make()
                        ->title('Invoice saved with warnings')
                        ->body(trim('Debit and credit totals are not equal.'.($uploadedCount > 0
                            ? ' '.$uploadedCount.' additional document(s) uploaded.'
                            : '')))
                ->warning()
                ->send();

            return;
        }

                Notification::make()
                    ->title('Invoice saved')
                    ->body($uploadedCount > 0 ? $uploadedCount.' additional document(s) uploaded.' : null)
                    ->success()
                    ->send();
            });
    }

    public function updateFinanceCategory(int $invoiceId, ?string $value): void
    {
        $this->resolveInvoiceRecord($invoiceId)->applyFinanceCategory($value);
    }

    #[Renderless]
    public function updateComment(int $invoiceId, ?string $value): void
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);
        $comment = filled($value) ? trim((string) $value) : null;

        if ((string) ($invoice->comment ?? '') === (string) ($comment ?? '')) {
            return;
        }

        $invoice->forceFill([
            'comment' => $comment,
        ])->save();
    }

    #[Renderless]
    public function updateInvoiceNumber(int $invoiceId, ?string $value): bool
    {
        $invoice = $this->resolveInvoiceRecord($invoiceId);
        $invoiceNumber = filled($value) ? trim((string) $value) : null;

        if ((string) ($invoice->invoice_number ?? '') === (string) ($invoiceNumber ?? '')) {
            return true;
        }

        if ($invoiceNumber !== null) {
            $duplicate = Invoice::query()
                ->where('invoice_number', $invoiceNumber)
                ->whereKeyNot($invoice->getKey())
                ->exists();

            if ($duplicate) {
                Notification::make()
                    ->title('Invoice number already exists')
                    ->body('Choose a different invoice number.')
                    ->danger()
                    ->send();

                return false;
            }
        }

        try {
            $invoice->forceFill([
                'invoice_number' => $invoiceNumber,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            Notification::make()
                ->title('Invoice number already exists')
                ->body('Choose a different invoice number.')
                ->danger()
                ->send();

            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function financeCategorySelectOptions(): array
    {
        return Invoice::financeCategorySelectOptions();
    }

    public function mountedInvoiceTotalToPay(): float
    {
        $invoiceId = $this->mountedInvoiceId();

        if ($invoiceId === null) {
            return 0.0;
        }

        return round((float) (Invoice::query()->whereKey($invoiceId)->value('sum_inc_vat') ?? 0), 2);
    }

    private function mountedInvoiceId(): ?int
    {
        $rootArguments = $this->getMountedAction(0)?->getArguments() ?? [];
        $invoiceId = $rootArguments['invoiceId'] ?? null;

        if (is_numeric($invoiceId)) {
            return (int) $invoiceId;
        }

        foreach ($this->mountedActions ?? [] as $mounted) {
            $invoiceId = $mounted['arguments']['invoiceId'] ?? null;

            if (is_numeric($invoiceId)) {
                return (int) $invoiceId;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function invoiceListEagerLoads(): array
    {
        $with = [
            'contact',
            'order.user',
            'incomeType',
            'expenseType',
            'financeLines',
            'bankStatementTransactions',
            'bankStatementTransactionLinks',
        ];

        if (DbSchema::hasTable('invoice_offset_links')) {
            $with[] = 'salesOffsetLinks.purchaseInvoice';
            $with[] = 'purchaseOffsetLinks.salesInvoice';
        }

        return $with;
    }

    private function softDeleteInvoice(int $invoiceId): void
    {
        $invoice = Invoice::query()
            ->with($this->invoiceListEagerLoads())
            ->find($invoiceId);

        if ($invoice === null) {
            Notification::make()
                ->title('Invoice not found')
                ->warning()
                ->send();

            return;
        }

        $transactionIds = $invoice->bankStatementTransactions
            ->pluck('id')
            ->merge($invoice->bankStatementTransactionLinks->pluck('id'))
            ->unique()
            ->values();

        foreach ($transactionIds as $transactionId) {
            $transaction = BankStatementTransaction::query()
                ->with('invoices')
                ->find((int) $transactionId);

            if ($transaction === null) {
                continue;
            }

            BankStatementInvoiceMatcher::unlinkInvoice($transaction, $invoice);
        }

        $invoice->delete();

        Notification::make()
            ->title('Invoice deleted')
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceDetailsHeaderData(): array
    {
        $invoice = $this->resolveInvoiceRecord($this->mountedInvoiceId());
        $invoice->loadMissing(['contact', 'order.user', 'order.orderItems.product.color.collection', 'order.package']);

        if ($invoice->order !== null) {
            $data = InvoicePdfGenerator::buildOrderInvoiceData($invoice->order, $invoice);

            return [
                'issuer' => $data['issuer'],
                'customer' => $data['customer'],
                'invoiceNumber' => $data['invoiceNumber'],
                'invoiceDate' => $data['invoiceDate'],
                'orderNumber' => $data['orderNumber'],
                'lines' => $data['lines'],
                'subtotal' => $data['subtotal'],
                'shipping' => $data['shipping'],
                'sumWithoutVat' => $data['sumWithoutVat'],
                'vat' => $data['vat'],
                'sumIncVat' => $data['sumIncVat'],
                'hasOrderTotals' => true,
                'currency' => $invoice->currencyCode(),
            ];
        }

        $company = CompanySetting::instance();
        $contact = $invoice->contact;

        return [
            'issuer' => [
                'name' => $company->company_name,
                'companyId' => $company->company_id,
                'vat' => $company->company_vat,
                'address' => $company->company_address,
                'email' => $company->company_email,
                'phone' => $company->company_phone,
            ],
            'customer' => [
                'name' => $contact?->company_name ?: ($contact?->contact_name ?: '—'),
                'companyName' => $contact?->company_name,
                'companyId' => $contact?->company_id,
                'vat' => $contact?->company_vat,
                'address' => $contact?->company_address,
                'email' => $contact?->company_email ?: $contact?->contact_email,
                'phone' => $contact?->company_phone ?: $contact?->contact_phone,
            ],
            'invoiceNumber' => $invoice->invoice_number ?? ('#'.$invoice->getKey()),
            'invoiceDate' => optional($invoice->invoice_date)->format('Y-m-d') ?? '—',
            'orderNumber' => null,
            'lines' => [],
            'subtotal' => 0,
            'shipping' => 0,
            'sumWithoutVat' => (float) $invoice->sum_without_vat,
            'vat' => (float) $invoice->vat,
            'sumIncVat' => (float) $invoice->sum_inc_vat,
            'hasOrderTotals' => false,
            'currency' => $invoice->currencyCode(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{0: float, 1: float, 2: bool}
     */
    private function financeLineTotals(array $lines): array
    {
        $creditTotal = 0.0;
        $debitTotal = 0.0;

        foreach ($lines as $line) {
            $creditTotal += (float) ($line['credit'] ?? 0);
            $debitTotal += (float) ($line['debit'] ?? 0);
        }

        $creditTotal = round($creditTotal, 2);
        $debitTotal = round($debitTotal, 2);

        return [$creditTotal, $debitTotal, abs($creditTotal - $debitTotal) < 0.005];
    }

    /**
     * @return array<int, string>
     */
    private function invoiceCodeOptionsByCode(?string $search = null): array
    {
        return $this->invoiceCodeQuery($search)
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function invoiceCodeOptionsByName(?string $search = null): array
    {
        return $this->invoiceCodeQuery($search)
            ->pluck('name', 'id')
            ->all();
    }

    private function invoiceCodeQuery(?string $search = null)
    {
        return InvoiceCode::query()
            ->when(filled($search), function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('code')
            ->limit(filled($search) ? 50 : 1000);
    }

    private function syncInvoiceCodeSelections(?int $state, Set $set): void
    {
        $set('invoice_code_id', $state);
        $set('name_select', $state);

        if ($state === null) {
            $set('name', '');

            return;
        }

        $set('name', (string) (InvoiceCode::query()->whereKey($state)->value('name') ?? ''));
    }

    private function resolveInvoiceRecord(mixed $invoiceId): Invoice
    {
        if (! is_numeric($invoiceId)) {
            throw new \RuntimeException('Invoice not found.');
        }

        $invoice = Invoice::query()->find((int) $invoiceId);

        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found.');
        }

        return $invoice;
    }

    private function resolveInvoiceForEmail(mixed $invoiceId): Invoice
    {
        if (! is_numeric($invoiceId)) {
            throw new \RuntimeException('Invoice not found.');
        }

        $invoice = Invoice::query()
            ->with(['order.user', 'contact'])
            ->find((int) $invoiceId);

        if ($invoice === null) {
            throw new \RuntimeException('Invoice not found.');
        }

        return $invoice;
    }

    private function additionalDocumentsListHtml(Invoice $invoice): HtmlString
    {
        $files = $invoice->additionalDocuments();
        if ($files === []) {
            return new HtmlString('<span class="text-sm text-gray-500">No additional documents yet.</span>');
        }

        $items = [];
        foreach ($files as $file) {
            $name = e($invoice->additionalDocumentLabel($file));
            $url = $invoice->additionalDocumentUrl($file);
            $items[] = $url !== null
                ? '<li><a href="'.e($url).'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                : '<li>'.$name.'</li>';
        }

        return new HtmlString(
            '<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>'
        );
    }

    public function getInvoices(): Collection
    {
        $query = Invoice::query()
            ->with($this->invoiceListEagerLoads());

        $filters = $this->filters ?? [];

        $monthKey = $this->selectedMonthKey();
        if ($monthKey !== null) {
            $bounds = ReportDateRange::boundsForMonthKey($monthKey);
            $query
                ->whereDate('invoice_date', '>=', $bounds['from'])
                ->whereDate('invoice_date', '<=', $bounds['until']);
        }

        $invoiceSearch = trim((string) ($filters['invoice_number'] ?? ''));
        if ($invoiceSearch !== '') {
            $like = '%'.$invoiceSearch.'%';
            $query->where(function ($builder) use ($invoiceSearch, $like): void {
                $builder->where('invoice_number', 'like', $like);

                $idCandidate = ltrim($invoiceSearch, '#');
                if ($idCandidate !== '' && ctype_digit($idCandidate)) {
                    $builder
                        ->orWhere('id', (int) $idCandidate)
                        ->orWhereRaw('CAST(id AS CHAR) like ?', ['%'.$idCandidate.'%']);
                } elseif (preg_match('/\d+/', $invoiceSearch) === 1) {
                    $builder->orWhereRaw('CAST(id AS CHAR) like ?', [$like]);
                }
            });
        }

        if (filled($filters['contact_id'] ?? null)) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (filled($filters['customer_country'] ?? null)) {
            $country = trim((string) $filters['customer_country']);
            $query->where(function ($builder) use ($country): void {
                $builder
                    ->whereHas('contact', function ($contact) use ($country): void {
                        $contact->whereRaw('LOWER(TRIM(company_country)) = ?', [mb_strtolower($country)]);
                    })
                    ->orWhereHas('order.user', function ($user) use ($country): void {
                        $user->whereRaw('LOWER(TRIM(company_country)) = ?', [mb_strtolower($country)]);
                    });
            });
        }

        $customerMarket = $filters['customer_market'] ?? null;
        if ($customerMarket === 'lithuania' || $customerMarket === 'export') {
            $this->applyInvoiceCustomerMarketFilter($query, $customerMarket);
        }

        $financeKind = $filters['finance_kind'] ?? null;
        $financeType = $filters['finance_type'] ?? null;

        if (filled($financeType) && is_string($financeType)) {
            if ($financeType === Invoice::OWN_ACCOUNT_FILTER) {
                $query->where('is_own_account', true);
            } elseif (str_starts_with($financeType, 'income:')) {
                $query->where('income_type_id', (int) substr($financeType, strlen('income:')))
                    ->where('is_own_account', false);
            } elseif (str_starts_with($financeType, 'expense:')) {
                $query->where('expense_type_id', (int) substr($financeType, strlen('expense:')))
                    ->where('is_own_account', false);
            }
        } elseif ($financeKind === 'income') {
            $query->whereNotNull('income_type_id')->where('is_own_account', false);
        } elseif ($financeKind === 'expense') {
            $query->whereNotNull('expense_type_id')->where('is_own_account', false);
        } elseif ($financeKind === Invoice::OWN_ACCOUNT_FILTER) {
            $query->where('is_own_account', true);
        } elseif ($financeKind === 'missing') {
            $query
                ->where('is_own_account', false)
                ->whereNull('income_type_id')
                ->whereNull('expense_type_id');
        }

        if (filled($filters['private_provider'] ?? null)) {
            $query->where('private_provider', (bool) (int) $filters['private_provider']);
        }

        if (filled($filters['payment_status'] ?? null)) {
            $status = $filters['payment_status'];

            $fullyPaid = function ($builder): void {
                $builder
                    ->where('paid', true)
                    ->orWhereRaw('COALESCE(sum_inc_vat, 0) > 0.004 AND ABS(COALESCE(paid_amount, 0) - sum_inc_vat) < 0.005');
            };

            if ($status === 'paid') {
                $query->where(function ($builder) use ($fullyPaid): void {
                    $fullyPaid($builder);
                });
            } elseif ($status === 'partly_paid') {
                $query
                    ->whereRaw('COALESCE(paid_amount, 0) > 0.004')
                    ->where(function ($builder) use ($fullyPaid): void {
                        $builder->whereNot(fn ($inner) => $fullyPaid($inner));
                    });
            } elseif ($status === 'unpaid') {
                $query
                    ->whereRaw('COALESCE(paid_amount, 0) <= 0.004')
                    ->where(function ($builder) use ($fullyPaid): void {
                        $builder->whereNot(fn ($inner) => $fullyPaid($inner));
                    });
            } elseif ($status === 'suggested') {
                $query->where(function ($builder) use ($fullyPaid): void {
                    $builder->whereNot(fn ($inner) => $fullyPaid($inner));
                });
            }
        }

        $commentFilter = $filters['comment'] ?? null;
        if ($commentFilter === 'with') {
            $query->whereNotNull('comment')->where('comment', '!=', '');
        } elseif ($commentFilter === 'without') {
            $query->where(function ($builder): void {
                $builder
                    ->whereNull('comment')
                    ->orWhere('comment', '');
            });
        }

        $this->applyInvoiceSorting($query);

        $invoices = $query->get();
        $this->attachSuggestedBankPayments($invoices);

        if (($filters['payment_status'] ?? null) === 'suggested') {
            $invoices = $invoices
                ->filter(fn (Invoice $invoice): bool => $invoice->relationLoaded('suggestedBankPayment')
                    && $invoice->getRelation('suggestedBankPayment') !== null)
                ->values();
        }

        $cmrFilter = $filters['cmr'] ?? null;
        if ($cmrFilter === 'missing') {
            $invoices = $invoices
                ->filter(fn (Invoice $invoice): bool => $invoice->isMissingCmrDocument())
                ->values();
        } elseif ($cmrFilter === 'attached') {
            $invoices = $invoices
                ->filter(fn (Invoice $invoice): bool => $invoice->requiresCmrDocument() && $invoice->hasAdditionalDocuments())
                ->values();
        } elseif ($cmrFilter === 'history') {
            $invoices = $invoices
                ->filter(fn (Invoice $invoice): bool => $invoice->isHistoricalRecord())
                ->values();
        } elseif ($cmrFilter === 'not_required') {
            $invoices = $invoices
                ->filter(fn (Invoice $invoice): bool => ! $invoice->isHistoricalRecord() && ! $invoice->requiresCmrDocument())
                ->values();
        }

        return $invoices;
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, $this->sortableInvoiceColumns(), true)) {
            return;
        }

        if ($this->sortColumn === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';

            return;
        }

        $this->sortColumn = $column;
        $this->sortDirection = in_array($column, [
            'invoice_date',
            'sum_without_vat',
            'sum_inc_vat',
            'paid_amount',
            'upload_date',
        ], true) ? 'desc' : 'asc';
    }

    public function isSortedBy(string $column): bool
    {
        return $this->sortColumn === $column;
    }

    public function sortDirectionFor(string $column): ?string
    {
        return $this->isSortedBy($column) ? $this->sortDirection : null;
    }

    /**
     * @return list<string>
     */
    private function sortableInvoiceColumns(): array
    {
        return [
            'buyer',
            'invoice_number',
            'invoice_date',
            'sum_without_vat',
            'sum_inc_vat',
            'paid_amount',
            'paid',
            'private_provider',
            'category',
            'upload_date',
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Invoice>  $query
     */
    private function applyInvoiceSorting($query): void
    {
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';
        $column = in_array($this->sortColumn, $this->sortableInvoiceColumns(), true)
            ? $this->sortColumn
            : 'upload_date';

        match ($column) {
            'buyer' => $query
                ->leftJoin('contacts', 'contacts.id', '=', 'invoices.contact_id')
                ->leftJoin('orders', 'orders.id', '=', 'invoices.order_id')
                ->leftJoin('users', 'users.id', '=', 'orders.user_id')
                ->orderByRaw(
                    "CASE
                        WHEN LOWER(COALESCE(contacts.company_name, '')) LIKE '%futura textiles%'
                            THEN LOWER(COALESCE(NULLIF(TRIM(users.company_name), ''), NULLIF(TRIM(users.name), ''), 'zzz'))
                        ELSE LOWER(COALESCE(contacts.company_name, 'zzz'))
                    END ".$direction
                )
                ->orderBy('invoices.id', 'desc')
                ->select('invoices.*'),
            'invoice_number' => $query
                ->orderBy('invoices.invoice_number', $direction)
                ->orderBy('invoices.id', 'desc'),
            'invoice_date' => $query
                ->orderBy('invoices.invoice_date', $direction)
                ->orderBy('invoices.id', 'desc'),
            'sum_without_vat' => $query
                ->orderBy('invoices.sum_without_vat', $direction)
                ->orderBy('invoices.id', 'desc'),
            'sum_inc_vat' => $query
                ->orderBy('invoices.sum_inc_vat', $direction)
                ->orderBy('invoices.id', 'desc'),
            'paid_amount' => $query
                ->orderBy('invoices.paid_amount', $direction)
                ->orderBy('invoices.id', 'desc'),
            'paid' => $query
                ->orderBy('invoices.paid', $direction)
                ->orderBy('invoices.paid_amount', $direction)
                ->orderBy('invoices.id', 'desc'),
            'private_provider' => $query
                ->orderBy('invoices.private_provider', $direction)
                ->orderBy('invoices.id', 'desc'),
            'category' => $query
                ->leftJoin('income_types', 'income_types.id', '=', 'invoices.income_type_id')
                ->leftJoin('expense_types', 'expense_types.id', '=', 'invoices.expense_type_id')
                ->orderByRaw(
                    'CASE
                        WHEN invoices.is_own_account = 1 THEN 3
                        WHEN invoices.income_type_id IS NOT NULL THEN 1
                        WHEN invoices.expense_type_id IS NOT NULL THEN 2
                        ELSE 0
                    END '.$direction
                )
                ->orderByRaw('COALESCE(income_types.name, expense_types.name) '.$direction)
                ->orderBy('invoices.id', 'desc')
                ->select('invoices.*'),
            default => $query
                ->orderBy('invoices.upload_date', $direction)
                ->orderBy('invoices.id', 'desc'),
        };
    }

    /**
     * @return array{
     *     count: int,
     *     sum_without_vat: float,
     *     vat: float,
     *     sum_inc_vat: float,
     *     paid_amount: float,
     *     outstanding: float,
     *     income_sum: float,
     *     expense_sum: float
     * }
     */
    public function summary(): array
    {
        $invoices = $this->getInvoices();

        $sumWithoutVat = 0.0;
        $vat = 0.0;
        $sumIncVat = 0.0;
        $paidAmount = 0.0;
        $outstanding = 0.0;
        $incomeSum = 0.0;
        $expenseSum = 0.0;
        $missingCmr = 0;

        foreach ($invoices as $invoice) {
            $amount = (float) $invoice->sum_without_vat;
            $sumWithoutVat += $amount;
            $vat += (float) $invoice->vat;
            $sumIncVat += (float) $invoice->sum_inc_vat;
            $paidAmount += (float) ($invoice->paid_amount ?? 0);
            $outstanding += $invoice->outstandingAmount();

            if ($invoice->isMissingCmrDocument()) {
                $missingCmr++;
            }

            match ($invoice->financeCategoryKind()) {
                'income' => $incomeSum += $amount,
                'expense' => $invoice->isSupplierCreditNote()
                    ? $incomeSum += $amount
                    : $expenseSum += $amount,
                default => null,
            };
        }

        return [
            'count' => $invoices->count(),
            'sum_without_vat' => round($sumWithoutVat, 2),
            'vat' => round($vat, 2),
            'sum_inc_vat' => round($sumIncVat, 2),
            'paid_amount' => round($paidAmount, 2),
            'outstanding' => round($outstanding, 2),
            'income_sum' => round($incomeSum, 2),
            'expense_sum' => round($expenseSum, 2),
            'missing_cmr' => $missingCmr,
        ];
    }

    /**
     * @return list<int>
     */
    public function listedInvoiceIds(): array
    {
        return array_map(
            intval(...),
            $this->getInvoices()->modelKeys(),
        );
    }

    public function toggleSelectAllInvoices(): void
    {
        $listedIds = $this->listedInvoiceIds();

        if ($listedIds === []) {
            $this->selectAllInvoices = false;
            $this->selectedInvoiceIds = [];

            return;
        }

        $selected = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($selected === collect($listedIds)->sort()->values()->all()) {
            $this->selectAllInvoices = false;
            $this->selectedInvoiceIds = [];

            return;
        }

        $this->selectedInvoiceIds = array_map(
            static fn (int $id): string => (string) $id,
            $listedIds,
        );
        $this->selectAllInvoices = true;
    }

    public function updatedSelectAllInvoices(mixed $value): void
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->selectedInvoiceIds = array_map(
                static fn (int $id): string => (string) $id,
                $this->listedInvoiceIds(),
            );

            return;
        }

        $this->selectedInvoiceIds = [];
    }

    public function updatedSelectedInvoiceIds(): void
    {
        $listedIds = $this->listedInvoiceIds();
        $listedLookup = array_flip($listedIds);

        $this->selectedInvoiceIds = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => isset($listedLookup[$id]))
            ->unique()
            ->values()
            ->map(fn (int $id): string => (string) $id)
            ->all();

        $this->selectAllInvoices = $listedIds !== []
            && count($this->selectedInvoiceIds) === count($listedIds);
    }

    public function updatedFilters(): void
    {
        $this->updatedSelectedInvoiceIds();
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
        $this->selectedInvoiceIds = [];
        $this->selectAllInvoices = false;
    }

    public function hasActiveInvoiceFilters(): bool
    {
        $filters = $this->filters ?? [];

        return filled($filters['invoice_number'] ?? null)
            || filled($filters['contact_id'] ?? null)
            || filled($filters['customer_country'] ?? null)
            || filled($filters['customer_market'] ?? null)
            || filled($filters['finance_kind'] ?? null)
            || filled($filters['finance_type'] ?? null)
            || filled($filters['private_provider'] ?? null)
            || filled($filters['payment_status'] ?? null)
            || filled($filters['comment'] ?? null)
            || filled($filters['cmr'] ?? null);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\Invoice>  $query
     */
    private function applyInvoiceCustomerMarketFilter($query, string $market): void
    {
        $isExport = $market === 'export';

        $query->where(function ($builder) use ($isExport): void {
            $builder
                ->whereHas(
                    'order.user',
                    fn ($user) => $isExport ? $user->exportMarket() : $user->lithuaniaMarket(),
                )
                ->orWhere(function ($withoutOrderUser) use ($isExport): void {
                    $withoutOrderUser
                        ->whereDoesntHave('order.user')
                        ->whereHas(
                            'contact',
                            fn ($contact) => $isExport ? $contact->exportMarket() : $contact->lithuaniaMarket(),
                        );
                });
        });
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     */
    private function attachSuggestedBankPayments(Collection $invoices): void
    {
        foreach ($invoices as $invoice) {
            $invoice->setRelation('suggestedBankPayment', null);
        }

        $unpaid = $invoices
            ->filter(fn (Invoice $invoice): bool => ! $invoice->isFullyPaid() && $invoice->invoice_date !== null)
            ->values();

        if ($unpaid->isEmpty()) {
            return;
        }

        /** @var Carbon $earliest */
        $earliest = $unpaid->min('invoice_date');
        /** @var Carbon $latest */
        $latest = $unpaid->max('invoice_date');

        $from = $earliest->copy()->subDays(BankStatementInvoiceMatcher::DATE_WINDOW_AFTER_DAYS);
        $until = $latest->copy()->addDays(BankStatementInvoiceMatcher::DATE_WINDOW_BEFORE_DAYS);

        $transactions = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->where('no_document_needed', false)
            ->where('is_historical', false)
            ->whereDoesntHave('invoices')
            ->whereBetween('booking_date', [$from->toDateString(), $until->toDateString()])
            ->orderByDesc('booking_date')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        if ($transactions->isEmpty()) {
            return;
        }

        $suggestions = BankStatementInvoiceMatcher::suggestFor($transactions);
        if ($suggestions->isEmpty()) {
            return;
        }

        $unpaidById = $unpaid->keyBy(fn (Invoice $invoice): int => (int) $invoice->getKey());
        $assignedInvoiceIds = [];

        foreach ($suggestions as $transactionId => $suggestedInvoice) {
            $invoiceId = (int) $suggestedInvoice->getKey();
            if (isset($assignedInvoiceIds[$invoiceId]) || ! $unpaidById->has($invoiceId)) {
                continue;
            }

            $transaction = $transactions->firstWhere('id', (int) $transactionId);
            if ($transaction === null) {
                continue;
            }

            $unpaidById->get($invoiceId)?->setRelation('suggestedBankPayment', $transaction);
            $assignedInvoiceIds[$invoiceId] = true;
        }
    }

    /**
     * @return array<string, string>
     */
    private function monthFilterOptions(): array
    {
        $keys = $this->invoiceMonthKeysWithData();
        $current = $this->defaultMonthKey();
        if (! in_array($current, $keys, true)) {
            array_unshift($keys, $current);
        }

        $options = ['any' => 'All months'];
        foreach ($keys as $key) {
            $options[$key] = $this->monthTitle($key);
        }

        return $options;
    }

    /**
     * Months that actually have invoice activity (skips one-off OCR/wrong dates).
     *
     * @return list<string>
     */
    private function invoiceMonthKeysWithData(): array
    {
        $connection = Invoice::query()->getConnection();
        $monthExpression = $connection->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', invoice_date)"
            : "DATE_FORMAT(invoice_date, '%Y-%m')";

        $counts = Invoice::query()
            ->whereNotNull('invoice_date')
            ->selectRaw("{$monthExpression} as month_key, COUNT(*) as invoice_count")
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->pluck('invoice_count', 'month_key')
            ->filter(fn ($count, $key): bool => is_string($key) && preg_match('/^\d{4}-\d{2}$/', $key) === 1)
            ->all();

        $bankMonths = BankStatementTransaction::query()
            ->whereNotNull('month_key')
            ->where('month_key', '!=', '')
            ->distinct()
            ->pluck('month_key')
            ->filter(fn ($key): bool => is_string($key) && preg_match('/^\d{4}-\d{2}$/', $key) === 1)
            ->all();

        $keys = [];
        foreach ($counts as $key => $count) {
            // Keep months with real volume, or any month that also has bank statements.
            if ((int) $count >= 2 || in_array($key, $bankMonths, true)) {
                $keys[] = (string) $key;
            }
        }

        if ($keys === []) {
            $keys = array_keys($counts);
        }

        if ($keys === []) {
            $keys = [$this->defaultMonthKey()];
        }

        rsort($keys);

        return array_values($keys);
    }

    public function defaultMonthKey(): string
    {
        return now()->format('Y-m');
    }

    private function selectedMonthKey(): ?string
    {
        $month = trim((string) ($this->filters['month'] ?? ''));

        if ($month === 'any') {
            return null;
        }

        $available = $this->invoiceMonthKeysWithData();

        if ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            if ($available === [] || in_array($month, $available, true)) {
                return $month;
            }
        }

        // Soft default: current calendar month while the month field is empty and no other filters are set.
        if ($this->filtersWidenBeyondMonth()) {
            return null;
        }

        return now()->format('Y-m');
    }

    /**
     * Any non-month filter skips the soft month default (search across all months).
     */
    private function filtersWidenBeyondMonth(): bool
    {
        return $this->hasActiveInvoiceFilters();
    }

    public function isUsingSoftMonthDefault(): bool
    {
        return blank($this->filters['month'] ?? null) && ! $this->filtersWidenBeyondMonth();
    }

    public function monthTitle(string $monthKey): string
    {
        try {
            return Carbon::createFromFormat('Y-m', $monthKey)->format('F Y');
        } catch (Throwable) {
            return $monthKey;
        }
    }
}
