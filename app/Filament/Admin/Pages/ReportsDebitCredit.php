<?php

namespace App\Filament\Admin\Pages;

use App\Models\Contact;
use App\Models\InvoiceFinanceLine;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReportsDebitCredit extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Debit/credit report';

    protected static ?string $title = 'Debit/credit report';

    protected static string|UnitEnum|null $navigationGroup = 'Invoices';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.admin.pages.reports-debit-credit';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'until' => now()->endOfMonth()->toDateString(),
            'contact_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live(),

                DatePicker::make('until')
                    ->label('Until')
                    ->native(false)
                    ->live(),

                Select::make('contact_id')
                    ->label('Supplier')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),
            ])
            ->columns(3);
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
