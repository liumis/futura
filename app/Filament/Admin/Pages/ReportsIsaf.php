<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Models\Invoice;
use App\Services\ActivityLogger;
use App\Services\IsafXmlExporter;
use App\Services\PvmReportBuilder;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class ReportsIsaf extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'i.SAF export';

    protected static ?string $title = 'i.SAF export';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.admin.pages.reports-isaf';

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
            'data_type' => IsafXmlExporter::DATA_FULL,
            'part_number' => '1',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('data_type')
                    ->label('Register')
                    ->options([
                        IsafXmlExporter::DATA_FULL => 'Full (F) — sales and purchases',
                        IsafXmlExporter::DATA_SALES => 'Sales (S) — issued invoices',
                        IsafXmlExporter::DATA_PURCHASES => 'Purchases (P) — received invoices',
                    ])
                    ->native(false)
                    ->required()
                    ->live(),

                TextInput::make('part_number')
                    ->label('Part number')
                    ->helperText('Unique file part id for this tax period (A–Z, 0–9, _). Use 1 if this is the only file.')
                    ->maxLength(20)
                    ->default('1')
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
                    ->label('PVM code')
                    ->getStateUsing(fn (Invoice $record): string => PvmReportBuilder::rateLabel($record->vatRate))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sum_without_vat')
                    ->label('Net')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vat')
                    ->label('VAT')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sum_inc_vat')
                    ->label('Total')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('invoice_date', 'desc')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->downloadXmlAction(),
        ];
    }

    public function downloadXmlAction(): Action
    {
        return Action::make('downloadXml')
            ->label('Download i.SAF XML')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Download i.SAF XML')
            ->modalDescription(function (): string {
                $filters = $this->filterContext();
                $preview = $this->preview();

                return sprintf(
                    'Download an i.SAF 1.2 XML file (%s · %s · %s sales, %s purchases) to upload in i.MAS?',
                    $filters['period_label'],
                    $filters['data_type_label'],
                    number_format($preview['sales']->count()),
                    number_format($preview['purchases']->count()),
                );
            })
            ->modalSubmitActionLabel('Download')
            ->action(fn (): ?StreamedResponse => $this->downloadXml());
    }

    public function downloadXml(): ?StreamedResponse
    {
        $filters = $this->filterContext();

        try {
            $xml = app(IsafXmlExporter::class)->buildXml($this->reportRows(), $filters);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not build i.SAF file')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $fileName = app(IsafXmlExporter::class)->fileName($filters);
        $preview = $this->preview();

        ActivityLogger::logReportDownloaded('i.SAF XML', 'xml', properties: [
            'file_name' => $fileName,
            'period' => $filters['period_label'],
            'data_type' => $filters['data_type'],
            'sales' => $preview['sales']->count(),
            'purchases' => $preview['purchases']->count(),
        ]);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $fileName, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * @return array{
     *     from: ?string,
     *     until: ?string,
     *     data_type: string,
     *     part_number: string,
     *     period_label: string,
     *     data_type_label: string
     * }
     */
    public function filterContext(): array
    {
        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);
        $dataType = (string) ($this->data['data_type'] ?? IsafXmlExporter::DATA_FULL);

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
            'data_type' => $dataType,
            'part_number' => (string) ($this->data['part_number'] ?? '1'),
            'period_label' => $periodLabel,
            'data_type_label' => IsafXmlExporter::dataTypeLabel($dataType),
        ];
    }

    /**
     * @return array{
     *     sales: Collection<int, Invoice>,
     *     purchases: Collection<int, Invoice>,
     *     unclassified: Collection<int, Invoice>,
     *     warnings: list<string>
     * }
     */
    public function preview(): array
    {
        return app(IsafXmlExporter::class)->preview(
            $this->reportRows(),
            (string) ($this->data['data_type'] ?? IsafXmlExporter::DATA_FULL),
        );
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
        $dataType = (string) ($this->data['data_type'] ?? IsafXmlExporter::DATA_FULL);

        $query->where(function (Builder $outer) use ($from, $until, $dataType): void {
            if ($dataType !== IsafXmlExporter::DATA_PURCHASES) {
                $outer->orWhere(function (Builder $sales) use ($from, $until): void {
                    $sales->whereNotNull('income_type_id');
                    $this->constrainInvoiceDate($sales, $from, $until);
                });
            }

            if ($dataType !== IsafXmlExporter::DATA_SALES) {
                $outer->orWhere(function (Builder $purchases) use ($from, $until): void {
                    $purchases->whereNotNull('expense_type_id')->whereNull('income_type_id');
                    $this->constrainPurchasePeriod($purchases, $from, $until);
                });
            }

            $outer->orWhere(function (Builder $unclassified) use ($from, $until): void {
                $unclassified->whereNull('income_type_id')->whereNull('expense_type_id');
                $this->constrainInvoiceDate($unclassified, $from, $until);
            });
        });

        return $query;
    }

    private function constrainInvoiceDate(Builder $query, ?string $from, ?string $until): void
    {
        if (filled($from)) {
            $query->whereDate('invoice_date', '>=', $from);
        }

        if (filled($until)) {
            $query->whereDate('invoice_date', '<=', $until);
        }
    }

    private function constrainPurchasePeriod(Builder $query, ?string $from, ?string $until): void
    {
        $query->where(function (Builder $period) use ($from, $until): void {
            $period->where(function (Builder $byInvoiceDate) use ($from, $until): void {
                $this->constrainInvoiceDate($byInvoiceDate, $from, $until);
            });

            if (filled($from) || filled($until)) {
                $period->orWhere(function (Builder $byUploadDate) use ($from, $until): void {
                    if (filled($from)) {
                        $byUploadDate->whereDate('upload_date', '>=', $from);
                    }
                    if (filled($until)) {
                        $byUploadDate->whereDate('upload_date', '<=', $until);
                    }
                });
            }
        });
    }
}
