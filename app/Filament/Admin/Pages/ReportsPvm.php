<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\PvmReportBuilder;
use App\Services\PvmReportDocumentGenerator;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

class ReportsPvm extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'PVM report';

    protected static ?string $title = 'PVM report';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.admin.pages.reports-pvm';

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
            'direction' => 'all',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live(),

                Select::make('direction')
                    ->label('Invoice type')
                    ->options([
                        'all' => 'All',
                        PvmReportBuilder::DIRECTION_SALES => 'Sales (output VAT)',
                        PvmReportBuilder::DIRECTION_PURCHASES => 'Purchases (input VAT)',
                    ])
                    ->native(false)
                    ->live(),
            ])
            ->columns(5);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->invoicesQuery())
            ->columns([
                Tables\Columns\TextColumn::make('invoice_date')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Invoice no')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('contact.company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('direction')
                    ->label('Type')
                    ->getStateUsing(fn (Invoice $record): string => PvmReportBuilder::directionLabel(
                        PvmReportBuilder::direction($record),
                    )),

                Tables\Columns\TextColumn::make('vatRate.classificator')
                    ->label('PVM rate')
                    ->getStateUsing(fn (Invoice $record): string => PvmReportBuilder::rateLabel($record->vatRate))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sum_without_vat')
                    ->label('Net')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('vat')
                    ->label('VAT')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('sum_inc_vat')
                    ->label('Total')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),
            ])
            ->defaultSort('invoice_date', 'desc')
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
            ->modalHeading('Generate PVM report')
            ->modalDescription(function (): string {
                $filters = $this->filterContext();
                $summary = $this->summary();

                return sprintf(
                    'Save a PDF of the current filters (%s · %s · %s invoice(s)) to Documents and SharePoint?',
                    $filters['period_label'],
                    $filters['direction_label'],
                    number_format($summary['invoices']),
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
            $document = PvmReportDocumentGenerator::create(
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
     * @return array{
     *     from: ?string,
     *     until: ?string,
     *     contact_id: mixed,
     *     direction: string,
     *     period_label: string,
     *     company_label: string,
     *     direction_label: string
     * }
     */
    public function filterContext(): array
    {
        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);
        $contactId = $this->data['contact_id'] ?? null;
        $direction = (string) ($this->data['direction'] ?? 'all');
        $companyLabel = filled($contactId)
            ? (Contact::query()->find($contactId)?->company_name ?? 'Selected company')
            : 'All companies';
        $directionLabel = match ($direction) {
            PvmReportBuilder::DIRECTION_SALES => 'Sales (output VAT)',
            PvmReportBuilder::DIRECTION_PURCHASES => 'Purchases (input VAT)',
            default => 'All invoices',
        };

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
            'direction' => $direction,
            'period_label' => $periodLabel,
            'company_label' => $companyLabel,
            'direction_label' => $directionLabel,
        ];
    }

    /**
     * @return array{
     *     groups: list<array<string, mixed>>,
     *     sales_base: float,
     *     purchase_base: float,
     *     output_vat: float,
     *     input_vat: float,
     *     unclassified_vat: float,
     *     payable: float,
     *     is_payable: bool,
     *     invoices: int
     * }
     */
    public function summary(): array
    {
        return PvmReportBuilder::build($this->reportRows());
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
     * @return Collection<int, Invoice>
     */
    private function reportRows(): Collection
    {
        return $this->invoicesQuery()
            ->with(['contact', 'vatRate'])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    private function invoicesQuery(): Builder
    {
        $query = Invoice::query()
            ->with(['contact', 'vatRate'])
            ->whereNull('gl_generated_type');

        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);
        $contactId = $this->data['contact_id'] ?? null;
        $direction = (string) ($this->data['direction'] ?? 'all');

        if (filled($from)) {
            $query->whereDate('invoice_date', '>=', $from);
        }

        if (filled($until)) {
            $query->whereDate('invoice_date', '<=', $until);
        }

        if (filled($contactId)) {
            $query->where('contact_id', $contactId);
        }

        if ($direction === PvmReportBuilder::DIRECTION_SALES) {
            $query->whereNotNull('income_type_id');
        } elseif ($direction === PvmReportBuilder::DIRECTION_PURCHASES) {
            $query->whereNotNull('expense_type_id')->whereNull('income_type_id');
        }

        return $query;
    }
}
