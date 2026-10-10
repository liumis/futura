<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Contact;
use App\Models\InvoiceCode;
use App\Services\LedgerRecalculator;
use App\Services\TurnoverSheetCalculator;
use App\Services\TurnoverSheetDocumentGenerator;
use App\Support\Money;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

class ReportsTurnoverSheet extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Turnover sheet';

    protected static ?string $title = 'Turnover sheet';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.reports-turnover-sheet';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
            'contact_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('contact_id')
                    ->label('Supplier')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),
            ])
            ->columns(4);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->codesQuery())
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('opening_debit_display')
                    ->label('Opening debit')
                    ->getStateUsing(fn (InvoiceCode $record): float => $this->rowCalc($record)['opening_debit'])
                    ->money(Money::currency())
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('opening_credit_display')
                    ->label('Opening credit')
                    ->getStateUsing(fn (InvoiceCode $record): float => $this->rowCalc($record)['opening_credit'])
                    ->money(Money::currency())
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('turnover_debit')
                    ->label('Turnover debit')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('turnover_credit')
                    ->label('Turnover credit')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('closing_debit_display')
                    ->label('Closing debit')
                    ->getStateUsing(fn (InvoiceCode $record): float => $this->rowCalc($record)['closing_debit'])
                    ->money(Money::currency())
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('closing_credit_display')
                    ->label('Closing credit')
                    ->getStateUsing(fn (InvoiceCode $record): float => $this->rowCalc($record)['closing_credit'])
                    ->money(Money::currency())
                    ->alignEnd(),
            ])
            ->defaultSort('code')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->recalculateLedgerAction(),
            $this->generateReportAction(),
        ];
    }

    public function recalculateLedgerAction(): Action
    {
        return Action::make('recalculateLedger')
            ->label('Recalculate from 2026-04-01')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Recalculate Didžioji knyga?')
            ->modalDescription('Regenerates invoice finance lines and payroll GL accruals from 2026-04-01. Opening balances stay zero. Existing lines in that range are replaced.')
            ->modalSubmitActionLabel('Recalculate')
            ->action(function (): void {
                try {
                    $result = LedgerRecalculator::recalculate(LedgerRecalculator::DEFAULT_FROM);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Recalculate failed')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $coverage = $result['coverage'];

                Notification::make()
                    ->title('Ledger recalculated')
                    ->body(sprintf(
                        'Invoices %d · payroll months %d · missing lines %d · unbalanced %d',
                        $result['invoices'],
                        $result['payroll_invoices'],
                        $coverage['invoices_missing_lines'],
                        $coverage['invoices_unbalanced'],
                    ))
                    ->success()
                    ->send();

                $this->resetTable();
            });
    }

    public function generateReportAction(): Action
    {
        return Action::make('generateReport')
            ->label('Generate report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate turnover sheet')
            ->modalDescription(function (): string {
                $totals = $this->totals();
                $filters = $this->filterContext();

                return sprintf(
                    'Save a PDF of the current filters (%s · %s · %s account(s)) to Documents and SharePoint?',
                    $filters['period_label'],
                    $filters['supplier_label'],
                    number_format($totals['accounts']),
                );
            })
            ->modalSubmitActionLabel('Generate')
            ->action(function (): void {
                $this->generateReport();
            });
    }

    /**
     * @return array{
     *     invoices_total: int,
     *     invoices_with_lines: int,
     *     invoices_missing_lines: int,
     *     invoices_unbalanced: int,
     *     payroll_months_total: int,
     *     payroll_months_posted: int
     * }
     */
    public function ledgerCoverage(): array
    {
        return LedgerRecalculator::coverage(LedgerRecalculator::DEFAULT_FROM);
    }

    public function generateReport(): void
    {
        try {
            $document = TurnoverSheetDocumentGenerator::create(
                $this->reportRows(),
                $this->filterContext(),
                auth()->user(),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not generate report')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Report saved')
            ->body('The PDF was stored in Documents and uploaded to SharePoint.')
            ->success()
            ->actions([
                Action::make('openDocument')
                    ->label('Open document')
                    ->url(DocumentResource::getUrl('edit', ['record' => $document]))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    /**
     * @return array{from: ?string, until: ?string, contact_id: mixed, period_label: string, supplier_label: string}
     */
    public function filterContext(): array
    {
        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);
        $contactId = $this->data['contact_id'] ?? null;
        $supplierLabel = filled($contactId)
            ? (Contact::query()->find($contactId)?->company_name ?? 'Selected supplier')
            : 'All suppliers';

        if (filled($from) && filled($until)) {
            $periodLabel = $from.' — '.$until;
        } elseif (filled($from)) {
            $periodLabel = 'From '.$from;
        } elseif (filled($until)) {
            $periodLabel = 'Until '.$until;
        } else {
            $periodLabel = 'All dates';
        }

        return [
            'from' => $from,
            'until' => $until,
            'contact_id' => $contactId,
            'period_label' => $periodLabel,
            'supplier_label' => $supplierLabel,
        ];
    }

    private function dateLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return trim((string) $value) ?: null;
        }
    }

    /**
     * @return Collection<int, InvoiceCode>
     */
    private function reportRows(): Collection
    {
        return $this->codesQuery()->orderBy('code')->get();
    }

    /**
     * @return array{
     *     opening_debit: float,
     *     opening_credit: float,
     *     turnover_debit: float,
     *     turnover_credit: float,
     *     closing_debit: float,
     *     closing_credit: float
     * }
     */
    private function rowCalc(InvoiceCode $record): array
    {
        return TurnoverSheetCalculator::forAccount(
            (float) ($record->opening_debit ?? 0),
            (float) ($record->opening_credit ?? 0),
            (float) ($record->turnover_debit ?? 0),
            (float) ($record->turnover_credit ?? 0),
        );
    }

    private function codesQuery(): Builder
    {
        $openingLines = $this->openingLineConstraint();
        $periodLines = $this->periodLineConstraint();

        return InvoiceCode::query()
            ->where(function (Builder $query) use ($openingLines, $periodLines): void {
                $from = $this->dateLabel($this->data['from'] ?? null);
                if (filled($from)) {
                    $query->whereHas('financeLines', $openingLines)
                        ->orWhereHas('financeLines', $periodLines);

                    return;
                }

                $query->whereHas('financeLines', $periodLines);
            })
            ->withSum(['financeLines as opening_debit' => $openingLines], 'debit')
            ->withSum(['financeLines as opening_credit' => $openingLines], 'credit')
            ->withSum(['financeLines as turnover_debit' => $periodLines], 'debit')
            ->withSum(['financeLines as turnover_credit' => $periodLines], 'credit')
            ->withCount(['financeLines as line_count' => $periodLines]);
    }

    /**
     * @return Closure(Builder): void
     */
    private function openingLineConstraint(): Closure
    {
        return function (Builder $lineQuery): void {
            $from = $this->dateLabel($this->data['from'] ?? null);
            $contactId = $this->data['contact_id'] ?? null;

            $lineQuery->whereHas('invoice', function (Builder $invoiceQuery) use ($from, $contactId): void {
                if (filled($from)) {
                    $invoiceQuery->whereDate('invoice_date', '<', $from);
                } else {
                    $invoiceQuery->whereRaw('0 = 1');
                }

                if (filled($contactId)) {
                    $invoiceQuery->where('contact_id', $contactId);
                }
            });
        };
    }

    /**
     * @return Closure(Builder): void
     */
    private function periodLineConstraint(): Closure
    {
        return function (Builder $lineQuery): void {
            $lineQuery->whereHas('invoice', function (Builder $invoiceQuery): void {
                $from = $this->dateLabel($this->data['from'] ?? null);
                $until = $this->dateLabel($this->data['until'] ?? null);
                $contactId = $this->data['contact_id'] ?? null;

                if (filled($from)) {
                    $invoiceQuery->whereDate('invoice_date', '>=', $from);
                }

                if (filled($until)) {
                    $invoiceQuery->whereDate('invoice_date', '<=', $until);
                }

                if (filled($contactId)) {
                    $invoiceQuery->where('contact_id', $contactId);
                }
            });
        };
    }

    /**
     * @return array{
     *     accounts: int,
     *     opening_debit: float,
     *     opening_credit: float,
     *     turnover_debit: float,
     *     turnover_credit: float,
     *     closing_debit: float,
     *     closing_credit: float,
     *     opening_balanced: bool,
     *     turnover_balanced: bool,
     *     closing_balanced: bool
     * }
     */
    public function totals(): array
    {
        $rows = $this->reportRows()->map(fn (InvoiceCode $code): array => $this->rowCalc($code));

        $openingDebit = round((float) $rows->sum('opening_debit'), 2);
        $openingCredit = round((float) $rows->sum('opening_credit'), 2);
        $turnoverDebit = round((float) $rows->sum('turnover_debit'), 2);
        $turnoverCredit = round((float) $rows->sum('turnover_credit'), 2);
        $closingDebit = round((float) $rows->sum('closing_debit'), 2);
        $closingCredit = round((float) $rows->sum('closing_credit'), 2);

        return [
            'accounts' => $rows->count(),
            'opening_debit' => $openingDebit,
            'opening_credit' => $openingCredit,
            'turnover_debit' => $turnoverDebit,
            'turnover_credit' => $turnoverCredit,
            'closing_debit' => $closingDebit,
            'closing_credit' => $closingCredit,
            'opening_balanced' => abs($openingDebit - $openingCredit) < 0.005,
            'turnover_balanced' => abs($turnoverDebit - $turnoverCredit) < 0.005,
            'closing_balanced' => abs($closingDebit - $closingCredit) < 0.005,
        ];
    }
}
