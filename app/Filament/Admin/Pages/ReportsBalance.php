<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Contact;
use App\Models\InvoiceCode;
use App\Services\BalanceReportDocumentGenerator;
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

class ReportsBalance extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Balance';

    protected static ?string $title = 'Balance';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.admin.pages.reports-balance';

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

                Tables\Columns\TextColumn::make('line_count')
                    ->label('Lines')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('debit')
                    ->label('Debit')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('credit')
                    ->label('Credit')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Balance')
                    ->getStateUsing(function (InvoiceCode $record): float {
                        return round((float) ($record->debit ?? 0) - (float) ($record->credit ?? 0), 2);
                    })
                    ->money(Money::currency())
                    ->alignEnd(),
            ])
            ->defaultSort('code')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateReportAction(),
        ];
    }

    public function generateReportAction(): Action
    {
        return Action::make('generateReport')
            ->label('Generate report')
            ->icon('heroicon-o-document-arrow-down')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate balance report')
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

    public function generateReport(): void
    {
        try {
            $document = BalanceReportDocumentGenerator::create(
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

    private function codesQuery(): Builder
    {
        $constrainLines = $this->financeLineConstraint();

        return InvoiceCode::query()
            ->whereHas('financeLines', $constrainLines)
            ->withSum(['financeLines as debit' => $constrainLines], 'debit')
            ->withSum(['financeLines as credit' => $constrainLines], 'credit')
            ->withCount(['financeLines as line_count' => $constrainLines]);
    }

    /**
     * @return Closure(Builder): void
     */
    private function financeLineConstraint(): Closure
    {
        return function (Builder $lineQuery): void {
            $lineQuery->whereHas('invoice', function (Builder $invoiceQuery): void {
                $from = $this->data['from'] ?? null;
                $until = $this->data['until'] ?? null;
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
     * @return array{accounts: int, lines: int, debit: float, credit: float, balance: float, balanced: bool}
     */
    public function totals(): array
    {
        $rows = $this->reportRows();
        $debit = round((float) $rows->sum(fn (InvoiceCode $code): float => (float) ($code->debit ?? 0)), 2);
        $credit = round((float) $rows->sum(fn (InvoiceCode $code): float => (float) ($code->credit ?? 0)), 2);
        $balance = round($debit - $credit, 2);

        return [
            'accounts' => $rows->count(),
            'lines' => (int) $rows->sum(fn (InvoiceCode $code): int => (int) ($code->line_count ?? 0)),
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $balance,
            'balanced' => abs($balance) < 0.005,
        ];
    }
}
