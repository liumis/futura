<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Contact;
use App\Models\InvoiceFinanceLine;
use App\Services\DebitCreditReportDocumentGenerator;
use App\Support\Money;
use BackedEnum;
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
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

class ReportsDebitCredit extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Debit/credit report';

    protected static ?string $title = 'Debit/credit report';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.admin.pages.reports-debit-credit';

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
            ->query(fn (): Builder => $this->linesQuery())
            ->columns([
                Tables\Columns\TextColumn::make('invoice.invoice_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice.invoice_number')
                    ->label('Invoice no')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoice.contact.company_name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('invoiceCode.code')
                    ->label('Code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->wrap(),

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
            ])
            ->defaultSort('id', 'desc')
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
            ->modalHeading('Generate debit/credit report')
            ->modalDescription(function (): string {
                $totals = $this->totals();
                $filters = $this->filterContext();

                return sprintf(
                    'Save a PDF of the current filters (%s · %s · %s line(s)) to Documents and SharePoint?',
                    $filters['period_label'],
                    $filters['supplier_label'],
                    number_format($totals['lines']),
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
            $document = DebitCreditReportDocumentGenerator::create(
                $this->reportLines(),
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
        $from = $this->data['from'] ?? null;
        $until = $this->data['until'] ?? null;
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

    /**
     * @return Collection<int, InvoiceFinanceLine>
     */
    private function reportLines(): Collection
    {
        return $this->linesQuery()
            ->get()
            ->sortBy(function (InvoiceFinanceLine $line): string {
                $date = $line->invoice?->invoice_date?->format('Y-m-d') ?? '0000-00-00';
                $number = (string) ($line->invoice?->invoice_number ?? '');
                $order = str_pad((string) ((int) $line->sort_order), 6, '0', STR_PAD_LEFT);
                $id = str_pad((string) ((int) $line->getKey()), 12, '0', STR_PAD_LEFT);

                return $date.'|'.$number.'|'.$order.'|'.$id;
            })
            ->values();
    }

    private function linesQuery(): Builder
    {
        $query = InvoiceFinanceLine::query()
            ->with(['invoice.contact', 'invoiceCode'])
            ->whereHas('invoice', function (Builder $invoiceQuery): void {
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

        return $query;
    }

    /**
     * @return array{lines: int, debit: float, credit: float, balanced: bool}
     */
    public function totals(): array
    {
        $rows = $this->linesQuery()->get();
        $debit = round((float) $rows->sum('debit'), 2);
        $credit = round((float) $rows->sum('credit'), 2);

        return [
            'lines' => $rows->count(),
            'debit' => $debit,
            'credit' => $credit,
            'balanced' => abs($debit - $credit) < 0.005,
        ];
    }
}
