<?php

namespace App\Filament\Admin\Pages;

use App\Enums\InvoiceLanguage;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\ExpenseType;
use App\Models\IncomeType;
use App\Models\Invoice;
use App\Models\InvoiceCode;
use App\Models\InvoiceFinanceLine;
use App\Models\User;
use App\Services\InvoiceMailer;
use App\Services\InvoiceOcrService;
use App\Services\InvoicePdfGenerator;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class Invoices extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Invoices';

    protected static string|UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.invoices';

    /**
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed> | null
     */
    public ?array $filters = [];

    public function mount(): void
    {
        $this->form->fill([
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
            'create_contact_if_missing' => true,
        ]);

        $this->filtersForm->fill([]);
    }

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
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                FileUpload::make('pdf_path')
                    ->label('Upload file')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg'])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices')
                    ->required()
                    ->helperText(UploadLimits::withExistingNote('Supported formats: PDF, JPG, JPEG.'))
                    ->columnSpanFull()
                    ->extraAttributes([
                        'class' => 'invoice-fancy-upload',
                    ])
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        $this->applyOcrPrefill(is_string($state) ? $state : null, $set);
                    })
                    ->downloadable()
                    ->openable(),

                Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->required(),

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

                Select::make('income_type_id')
                    ->label('Income type')
                    ->options(fn (): array => IncomeType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Select::make('expense_type_id')
                    ->label('Expense type')
                    ->options(fn (): array => ExpenseType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false),

                DatePicker::make('upload_date')
                    ->label('Upload date')
                    ->required(),

                Select::make('uploaded_by')
                    ->label('Uploaded user')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): ?int => auth()->id())
                    ->disabled()
                    ->dehydrated()
                    ->required(),
            ])
            ->columns(2);
    }

    public function filtersForm(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema
            ->statePath('filters')
            ->components([
                DatePicker::make('invoice_date_from')
                    ->label('Invoice date from')
                    ->native(false)
                    ->live(),

                DatePicker::make('invoice_date_until')
                    ->label('Invoice date until')
                    ->native(false)
                    ->live(),

                Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),

                Select::make('income_type_id')
                    ->label('Income type')
                    ->options(fn (): array => IncomeType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),

                Select::make('expense_type_id')
                    ->label('Expense type')
                    ->options(fn (): array => ExpenseType::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),
            ])
            ->columns(5);
    }

    public function regeneratePdf(int $invoiceId): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);

        try {
            InvoicePdfGenerator::refreshSalesInvoicePdf($invoice);
        } catch (\Throwable $exception) {
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
                } catch (\Throwable $exception) {
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

    public function invoiceDetailsAction(): Action
    {
        return Action::make('invoiceDetails')
            ->modalHeading(function (array $arguments): string {
                $invoice = $this->resolveInvoiceRecord($arguments['invoiceId'] ?? null);

                return 'Invoice details'.(filled($invoice->invoice_number) ? ' — '.$invoice->invoice_number : ' #'.$invoice->getKey());
            })
            ->modalSubmitActionLabel('Confirm')
            ->modalCancelActionLabel('Cancel')
            ->modalWidth('5xl')
            ->schema([
                View::make('filament.admin.components.invoice-details-header')
                    ->viewData(fn (self $livewire): array => $livewire->invoiceDetailsHeaderData())
                    ->columnSpanFull(),

                Actions::make([
                    Action::make('addDefaultLines')
                        ->label('Add default lines')
                        ->icon('heroicon-o-queue-list')
                        ->color('gray')
                        ->button()
                        ->action(function (Get $get, Set $set): void {
                            $invoice = $this->resolveInvoiceRecord($this->mountedInvoiceId());
                            $set('lines', $this->buildDefaultFinanceLines($invoice));

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
                            ->width('9rem')
                            ->markAsRequired(),
                        TableColumn::make('Name')
                            ->markAsRequired(),
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
                            ->required()
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
                            ->required()
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
                        $totalToPay = $this->mountedInvoiceTotalToPay();
                        $matchesTotalToPay = abs($creditTotal - $totalToPay) < 0.005
                            && abs($debitTotal - $totalToPay) < 0.005;
                        $valid = $balanced && $matchesTotalToPay;

                        $debitLabel = number_format($debitTotal, 2, ',', ' ');
                        $creditLabel = number_format($creditTotal, 2, ',', ' ');
                        $totalToPayLabel = number_format($totalToPay, 2, ',', ' ');
                        $rowClass = $valid
                            ? 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-100'
                            : 'bg-danger-50 text-danger-700 dark:bg-danger-950 dark:text-danger-300';

                        $messages = [];
                        if (! $balanced) {
                            $messages[] = 'Debit and credit totals must be equal.';
                        }
                        if ($balanced && ! $matchesTotalToPay) {
                            $messages[] = 'Debit/credit total must equal Total to pay ('.$totalToPayLabel.').';
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
                $totalToPay = round((float) $invoice->sum_inc_vat, 2);
                $matchesTotalToPay = abs($creditTotal - $totalToPay) < 0.005
                    && abs($debitTotal - $totalToPay) < 0.005;

                DB::transaction(function () use ($invoice, $lines): void {
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

                if (! $balanced || ! $matchesTotalToPay) {
                    $warnings = [];

                    if (! $balanced) {
                        $warnings[] = 'Debit and credit totals are not equal.';
                    }

                    if ($balanced && ! $matchesTotalToPay) {
                        $warnings[] = 'Debit/credit total does not equal Total to pay ('.Money::format($totalToPay).').';
                    }

                    Notification::make()
                        ->title('Invoice details saved with warnings')
                        ->body(implode(' ', $warnings))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Invoice details saved')
                    ->success()
                    ->send();
            });
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
     * @return list<array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    private function buildDefaultFinanceLines(Invoice $invoice): array
    {
        $codes = $this->defaultFinanceInvoiceCodes();

        $invoice->loadMissing(['order.orderItems.product.productType']);

        $sumIncVat = round((float) $invoice->sum_inc_vat, 2);
        $vat = round((float) $invoice->vat, 2);
        $sumWithoutVat = round((float) $invoice->sum_without_vat, 2);
        $shipping = round((float) ($invoice->order?->shipping_cost ?? 0), 2);
        $goodsRevenue = round(max(0, $sumWithoutVat - $shipping), 2);
        $cogs = $this->estimateInvoiceCogs($invoice);

        $rows = [
            ['code' => '2410', 'debit' => $sumIncVat, 'credit' => 0.0],
            ['code' => '5001', 'debit' => 0.0, 'credit' => $shipping],
            ['code' => '4492', 'debit' => 0.0, 'credit' => $vat],
            ['code' => '2040', 'debit' => 0.0, 'credit' => $cogs],
            ['code' => '6000', 'debit' => $cogs, 'credit' => 0.0],
            ['code' => '5000', 'debit' => 0.0, 'credit' => $goodsRevenue],
        ];

        $lines = [];

        foreach ($rows as $row) {
            $code = $codes[$row['code']];

            $lines[(string) Str::uuid()] = [
                'name' => $code->name,
                'invoice_code_id' => $code->id,
                'name_select' => $code->id,
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, InvoiceCode>
     */
    private function defaultFinanceInvoiceCodes(): array
    {
        $defaults = [
            '2410' => 'Pirkėjų skolų vertė',
            '5001' => 'Suteiktų paslaugų pajamos',
            '4492' => 'Mokėtinas pridėtinės vertės mokestis',
            '2040' => 'Pirktų prekių, skirtų perparduoti, įsigijimo',
            '6000' => 'Parduotų prekių savikaina',
            '5000' => 'Parduotų prekių pajamos',
        ];

        $existing = InvoiceCode::query()
            ->whereIn('code', array_keys($defaults))
            ->get()
            ->keyBy('code');

        $codes = [];

        foreach ($defaults as $code => $name) {
            $record = $existing->get($code);

            if ($record === null) {
                $record = InvoiceCode::query()->create([
                    'code' => $code,
                    'name' => $name,
                ]);
            }

            $codes[$code] = $record;
        }

        return $codes;
    }

    private function estimateInvoiceCogs(Invoice $invoice): float
    {
        $order = $invoice->order;

        if ($order === null) {
            return 0.0;
        }

        $order->loadMissing('orderItems.product.productType');
        $total = 0.0;

        foreach ($order->orderItems as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $quantity = max(0, (int) $item->amount);
            $unitCost = (float) $product->default_cost;

            if ($product->isCatalog()) {
                $total += $unitCost * $quantity;

                continue;
            }

            $meters = is_numeric($product->name) ? (float) $product->name : 0.0;
            $total += $unitCost * $meters * $quantity;
        }

        return round($total, 2);
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

        return Invoice::query()->findOrFail((int) $invoiceId);
    }

    private function resolveInvoiceForEmail(mixed $invoiceId): Invoice
    {
        if (! is_numeric($invoiceId)) {
            throw new \RuntimeException('Invoice not found.');
        }

        return Invoice::query()
            ->with(['order.user', 'contact'])
            ->findOrFail((int) $invoiceId);
    }

    public function create(): void
    {
        $state = $this->form->getState();
        $storedPath = (string) ($state['pdf_path'] ?? '');

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
            $name = trim((string) ($state['provider_company_name'] ?? ''));
            if ($name === '') {
                Notification::make()
                    ->title('Provider not detected')
                    ->body('Please select a company or provide provider name to create a new contact.')
                    ->warning()
                    ->send();

                return;
            }

            $contact = Contact::query()->create([
                'company_name' => $name,
                'company_id' => (string) ($state['provider_company_id'] ?? ('AUTO-'.Str::upper(Str::random(8)))),
                'company_vat' => $state['provider_company_vat'] ?? null,
                'company_address' => (string) ($state['provider_company_address'] ?? 'Auto-created from OCR'),
                'company_email' => $state['provider_company_email'] ?? null,
                'company_phone' => $state['provider_company_phone'] ?? null,
            ]);

            $contactId = $contact->getKey();
        }

        if ($contactId === null) {
            Notification::make()
                ->title('Company is required')
                ->warning()
                ->send();

            return;
        }

        Invoice::query()->create([
            'contact_id' => $contactId,
            'invoice_date' => $state['invoice_date'],
            'sum_without_vat' => $state['sum_without_vat'],
            'vat' => $state['vat'],
            'sum_inc_vat' => $state['sum_inc_vat'],
            'income_type_id' => $state['income_type_id'] ?? null,
            'expense_type_id' => $state['expense_type_id'] ?? null,
            'upload_date' => $state['upload_date'],
            'uploaded_by' => $state['uploaded_by'] ?? auth()->id(),
            'pdf_path' => $storedPath,
            'file_content' => base64_encode($binary),
            'file_name' => basename($storedPath),
            'file_mime' => $mime,
        ]);

        Notification::make()
            ->title('Invoice uploaded')
            ->success()
            ->send();

        $this->form->fill([
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
            'create_contact_if_missing' => true,
        ]);
    }

    private function applyOcrPrefill(?string $path, callable $set): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $absolutePath = Storage::disk('public')->path($path);
        $ocr = app(InvoiceOcrService::class)->extract($absolutePath);

        if ($ocr === []) {
            return;
        }

        foreach ([
            'provider_company_name',
            'provider_company_vat',
            'provider_company_email',
            'invoice_date',
            'sum_without_vat',
            'vat',
            'sum_inc_vat',
        ] as $field) {
            if (array_key_exists($field, $ocr) && filled($ocr[$field])) {
                $set($field, $ocr[$field]);
            }
        }

        $providerName = trim((string) ($ocr['provider_company_name'] ?? ''));
        if ($providerName === '') {
            return;
        }

        $matchedContact = Contact::query()
            ->whereRaw('LOWER(company_name) = ?', [Str::lower($providerName)])
            ->orWhereRaw('LOWER(company_name) LIKE ?', ['%'.Str::lower($providerName).'%'])
            ->orderByRaw('CASE WHEN LOWER(company_name) = ? THEN 0 ELSE 1 END', [Str::lower($providerName)])
            ->first();

        if ($matchedContact !== null) {
            $set('contact_id', $matchedContact->getKey());
            $set('provider_company_id', $matchedContact->company_id);
            $set('provider_company_address', $matchedContact->company_address);
            $set('provider_company_phone', $matchedContact->company_phone);
            $set('provider_company_email', $matchedContact->company_email);
            $set('provider_company_vat', $matchedContact->company_vat);
        } else {
            $set('contact_id', null);
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getInvoices(): Collection
    {
        $query = Invoice::query()
            ->with(['contact', 'uploadedUser', 'order.user', 'incomeType', 'expenseType', 'financeLines'])
            ->latest('upload_date');

        $filters = $this->filters ?? [];

        if (filled($filters['invoice_date_from'] ?? null)) {
            $query->whereDate('invoice_date', '>=', $filters['invoice_date_from']);
        }

        if (filled($filters['invoice_date_until'] ?? null)) {
            $query->whereDate('invoice_date', '<=', $filters['invoice_date_until']);
        }

        if (filled($filters['contact_id'] ?? null)) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (filled($filters['income_type_id'] ?? null)) {
            $query->where('income_type_id', $filters['income_type_id']);
        }

        if (filled($filters['expense_type_id'] ?? null)) {
            $query->where('expense_type_id', $filters['expense_type_id']);
        }

        return $query->get();
    }

    public function hasActiveInvoiceFilters(): bool
    {
        $filters = $this->filters ?? [];

        return filled($filters['invoice_date_from'] ?? null)
            || filled($filters['invoice_date_until'] ?? null)
            || filled($filters['contact_id'] ?? null)
            || filled($filters['income_type_id'] ?? null)
            || filled($filters['expense_type_id'] ?? null);
    }
}
