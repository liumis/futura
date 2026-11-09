<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\OriginCountry;
use App\Services\ActivityLogger;
use App\Services\InvoiceSepaExporter;
use App\Services\InvoicesChartReportBuilder;
use App\Support\ReportDateRange;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class ReportsInvoicesChart extends Page implements HasActions, HasForms
{
    use HasReportMonthQuickFilter;
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Invoices chart';

    protected static ?string $title = 'Invoices chart';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.reports-invoices-chart';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var list<string|int>
     */
    public array $selectedInvoiceIds = [];

    /**
     * @var array<string, float|string|null>
     */
    public array $paymentAmounts = [];

    public bool $selectAllPayable = false;

    public function mount(): void
    {
        $this->form->fill([
            ...ReportDateRange::lastMonthFill(),
            'date_basis' => InvoicesChartReportBuilder::DATE_BASIS_DUE,
            'status' => InvoicesChartReportBuilder::STATUS_ALL,
            'late_days' => InvoicesChartReportBuilder::LATE_ANY,
            'contact_id' => null,
            'country' => null,
        ]);
    }

    public function updatedData(): void
    {
        $this->pruneSelectionToReport();
    }

    public function updatedSelectedInvoiceIds(): void
    {
        $this->syncPaymentAmountsForSelection();
        $this->syncSelectAllPayableState();
    }

    public function updatedPaymentAmounts(): void
    {
        // Keep numeric-ish values; validation happens on SEPA generate.
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('date_basis')
                    ->label('Group by')
                    ->options(InvoicesChartReportBuilder::dateBasisOptions())
                    ->default(InvoicesChartReportBuilder::DATE_BASIS_DUE)
                    ->helperText('Payment due date = invoice date + payment term (cashflow).')
                    ->native(false)
                    ->live(),

                Select::make('status')
                    ->label('Payment')
                    ->options(InvoicesChartReportBuilder::statusOptions())
                    ->default(InvoicesChartReportBuilder::STATUS_ALL)
                    ->native(false)
                    ->live(),

                Select::make('late_days')
                    ->label('Late days')
                    ->options(InvoicesChartReportBuilder::lateDaysOptions())
                    ->default(InvoicesChartReportBuilder::LATE_ANY)
                    ->native(false)
                    ->live(),

                Select::make('contact_id')
                    ->label('Counterpart')
                    ->options(fn (): array => Contact::query()
                        ->orderBy('company_name')
                        ->pluck('company_name', 'id')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->placeholder('All')
                    ->native(false)
                    ->live(),

                Select::make('country')
                    ->label('Country')
                    ->options(fn (): array => OriginCountry::options())
                    ->searchable()
                    ->preload()
                    ->placeholder('All')
                    ->native(false)
                    ->live(),
            ])
            ->columns(4);
    }

    /**
     * @return array{
     *     title: string,
     *     period_label: string,
     *     date_basis: string,
     *     date_basis_label: string,
     *     status: string,
     *     status_label: string,
     *     late_days: ?int,
     *     late_days_label: string,
     *     contact_id: ?int,
     *     contact_label: string,
     *     country: ?string,
     *     country_label: string,
     *     from: ?string,
     *     until: ?string,
     *     as_of: string,
     *     invoice_count: int,
     *     paid_count: int,
     *     partly_paid_count: int,
     *     unpaid_count: int,
     *     paid_total: float,
     *     partly_paid_total: float,
     *     unpaid_total: float,
     *     outstanding_total: float,
     *     due_to_pay_total: float,
     *     grand_total: float,
     *     days: list<array{
     *         day: string,
     *         label: string,
     *         paid: float,
     *         partly_paid: float,
     *         unpaid: float,
     *         due_to_pay: float
     *     }>,
     *     rows: list<array{
     *         id: int,
     *         number: string,
     *         invoice_date: ?string,
     *         due_date: ?string,
     *         company: string,
     *         status: string,
     *         status_label: string,
     *         amount: float,
     *         outstanding: float,
     *         days_late: int,
     *         late: bool,
     *         sort_day: string
     *     }>
     * }
     */
    public function report(): array
    {
        return InvoicesChartReportBuilder::build(
            $this->data['from'] ?? null,
            $this->data['until'] ?? null,
            $this->data['status'] ?? InvoicesChartReportBuilder::STATUS_ALL,
            $this->data['late_days'] ?? InvoicesChartReportBuilder::LATE_ANY,
            $this->data['contact_id'] ?? null,
            $this->data['country'] ?? null,
            null,
            $this->data['date_basis'] ?? InvoicesChartReportBuilder::DATE_BASIS_DUE,
        );
    }

    /**
     * @return list<array{id: int, number: string, company: string, outstanding: float}>
     */
    public function payableRows(): array
    {
        return collect($this->report()['rows'] ?? [])
            ->filter(fn (array $row): bool => (float) ($row['outstanding'] ?? 0) > 0.004)
            ->map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['number'],
                'company' => (string) $row['company'],
                'outstanding' => round((float) $row['outstanding'], 2),
            ])
            ->values()
            ->all();
    }

    public function selectedPayableCount(): int
    {
        $payableIds = collect($this->payableRows())->pluck('id')->all();

        return collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0 && in_array($id, $payableIds, true))
            ->unique()
            ->count();
    }

    public function toggleSelectAllPayable(): void
    {
        $payableIds = collect($this->payableRows())
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($this->selectAllPayable || count($this->selectedInvoiceIds) === count($payableIds)) {
            $this->selectedInvoiceIds = [];
            $this->selectAllPayable = false;
            $this->paymentAmounts = [];

            return;
        }

        $this->selectedInvoiceIds = $payableIds;
        $this->selectAllPayable = true;
        $this->syncPaymentAmountsForSelection();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->makeBankPaymentAction(),
        ];
    }

    public function makeBankPaymentAction(): Action
    {
        return Action::make('makeBankPayment')
            ->label(fn (): string => $this->selectedPayableCount() > 0
                ? 'Generate SEPA ('.$this->selectedPayableCount().')'
                : 'Generate SEPA')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->disabled(fn (): bool => $this->selectedPayableCount() < 1)
            ->modalHeading('Generate SEPA payment')
            ->modalDescription('Amounts default to the remaining balance. Change an amount for a partial payment, then download the bank file.')
            ->modalSubmitActionLabel('Download SEPA XML')
            ->modalWidth('4xl')
            ->fillForm(fn (): array => [
                'payments' => $this->sepaPaymentFormRows(),
            ])
            ->schema([
                Repeater::make('payments')
                    ->label('Invoices to pay')
                    ->helperText('Remove a row to skip that invoice. Amount cannot exceed the remaining balance.')
                    ->table([
                        TableColumn::make('Invoice'),
                        TableColumn::make('Company'),
                        TableColumn::make('Remaining')
                            ->alignment(Alignment::End)
                            ->width('8rem'),
                        TableColumn::make('Pay now')
                            ->alignment(Alignment::End)
                            ->width('9rem'),
                    ])
                    ->schema([
                        Hidden::make('invoice_id'),

                        TextInput::make('invoice_number')
                            ->label('Invoice')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('company')
                            ->label('Company')
                            ->disabled()
                            ->dehydrated(false),

                        TextInput::make('outstanding')
                            ->label('Remaining')
                            ->disabled()
                            ->dehydrated(false)
                            ->prefix('€'),

                        TextInput::make('amount')
                            ->label('Pay now')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->required()
                            ->prefix('€')
                            ->extraInputAttributes(['class' => 'text-right']),
                    ])
                    ->defaultItems(0)
                    ->addable(false)
                    ->deletable()
                    ->reorderable(false)
                    ->columnSpanFull(),
            ])
            ->action(fn (array $data): mixed => $this->makeBankPayment(
                is_array($data['payments'] ?? null) ? $data['payments'] : [],
            ));
    }

    /**
     * @return list<array{invoice_id: int, invoice_number: string, company: string, outstanding: float, amount: float}>
     */
    public function sepaPaymentFormRows(): array
    {
        $selected = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        return collect($this->payableRows())
            ->filter(fn (array $row): bool => in_array((int) $row['id'], $selected, true))
            ->map(function (array $row): array {
                $id = (int) $row['id'];
                $outstanding = (float) $row['outstanding'];
                $amount = round((float) ($this->paymentAmounts[(string) $id] ?? $outstanding), 2);
                if ($amount <= 0) {
                    $amount = $outstanding;
                }
                if ($amount > $outstanding) {
                    $amount = $outstanding;
                }

                return [
                    'invoice_id' => $id,
                    'invoice_number' => (string) $row['number'],
                    'company' => (string) $row['company'],
                    'outstanding' => $outstanding,
                    'amount' => $amount,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array{invoice_id?: mixed, amount?: mixed}>  $paymentRows
     */
    public function makeBankPayment(array $paymentRows = []): ?StreamedResponse
    {
        $rows = collect($paymentRows)
            ->map(function (array $row): ?array {
                $invoiceId = (int) ($row['invoice_id'] ?? 0);
                $amount = round((float) ($row['amount'] ?? 0), 2);

                if ($invoiceId < 1 || $amount <= 0) {
                    return null;
                }

                return [
                    'invoice_id' => $invoiceId,
                    'amount' => $amount,
                ];
            })
            ->filter()
            ->values();

        if ($rows->isEmpty()) {
            $rows = collect($this->sepaPaymentFormRows())
                ->map(fn (array $row): array => [
                    'invoice_id' => (int) $row['invoice_id'],
                    'amount' => (float) $row['amount'],
                ])
                ->values();
        }

        if ($rows->isEmpty()) {
            Notification::make()
                ->title('Nothing to pay')
                ->body('Select unpaid invoices and set an amount greater than zero.')
                ->warning()
                ->send();

            return null;
        }

        $ids = $rows->pluck('invoice_id')->unique()->values()->all();
        $amountsByInvoiceId = $rows
            ->mapWithKeys(fn (array $row): array => [(int) $row['invoice_id'] => (float) $row['amount']])
            ->all();

        $invoices = Invoice::query()
            ->with('contact')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            Notification::make()
                ->title('No invoice selected')
                ->warning()
                ->send();

            return null;
        }

        try {
            $xml = app(InvoiceSepaExporter::class)->buildXml(
                $invoices,
                null,
                null,
                $amountsByInvoiceId,
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not generate SEPA payment')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $filename = 'sepa-invoices-'.now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded('SEPA invoice payment (chart)', 'xml', properties: [
            'file_name' => $filename,
            'invoice_ids' => $invoices->modelKeys(),
            'amounts' => $amountsByInvoiceId,
            'count' => $invoices->count(),
        ]);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * @param  array{id?: int, number?: string}  $row
     */
    public function rowUrl(array $row): ?string
    {
        $number = trim((string) ($row['number'] ?? ''));
        if ($number === '') {
            return Invoices::getUrl();
        }

        return Invoices::getUrl([
            'filters' => [
                'month' => 'any',
                'invoice_number' => $number,
            ],
        ]);
    }

    private function pruneSelectionToReport(): void
    {
        $payableIds = collect($this->payableRows())->pluck('id')->all();

        $this->selectedInvoiceIds = collect($this->selectedInvoiceIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $payableIds, true))
            ->map(fn (int $id): string => (string) $id)
            ->values()
            ->all();

        $this->paymentAmounts = collect($this->paymentAmounts)
            ->filter(fn ($amount, $key): bool => in_array((int) $key, $payableIds, true))
            ->all();

        $this->syncPaymentAmountsForSelection();
        $this->syncSelectAllPayableState();
    }

    private function syncPaymentAmountsForSelection(): void
    {
        $byId = collect($this->payableRows())->keyBy('id');

        foreach ($this->selectedInvoiceIds as $rawId) {
            $id = (int) $rawId;
            $key = (string) $id;
            $row = $byId->get($id);
            if ($row === null) {
                continue;
            }

            if (! array_key_exists($key, $this->paymentAmounts) || $this->paymentAmounts[$key] === null || $this->paymentAmounts[$key] === '') {
                $this->paymentAmounts[$key] = $row['outstanding'];
            }
        }
    }

    private function syncSelectAllPayableState(): void
    {
        $payableCount = count($this->payableRows());
        $this->selectAllPayable = $payableCount > 0
            && $this->selectedPayableCount() === $payableCount;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
