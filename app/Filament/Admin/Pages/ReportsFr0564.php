<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Invoice;
use App\Services\Fr0564ReportBuilder;
use App\Services\Fr0564ReportDocumentGenerator;
use App\Services\PvmReportBuilder;
use App\Services\WorkTimeTimesheetPdfGenerator;
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
use Throwable;
use UnitEnum;

class ReportsFr0564 extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = '0564 export';

    protected static ?string $title = '0564 export';

    protected static ?string $slug = 'fr0564-export';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.admin.pages.reports-fr0564';

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
            'month' => $this->defaultMonthKey(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('month')
                    ->label('Month')
                    ->options(fn (): array => WorkTimeTimesheetPdfGenerator::monthSelectOptions())
                    ->required()
                    ->native(false)
                    ->live(),
            ])
            ->columns(3);
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
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('contact.company_name')
                    ->label('Company')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('country')
                    ->label('Country')
                    ->getStateUsing(function (Invoice $record): string {
                        return Fr0564ReportBuilder::partner($record)[0] ?? '—';
                    }),

                Tables\Columns\TextColumn::make('eu_vat')
                    ->label('EU VAT')
                    ->getStateUsing(function (Invoice $record): string {
                        return Fr0564ReportBuilder::partner($record)[1] ?: '—';
                    }),

                Tables\Columns\TextColumn::make('vatRate.classificator')
                    ->label('PVM')
                    ->getStateUsing(fn (Invoice $record): string => PvmReportBuilder::rateLabel($record->vatRate))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sum_without_vat')
                    ->label('Net')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('invoice_date')
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
            ->label('Generate PDF')
            ->icon('heroicon-o-document-arrow-down')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate FR0564 declaration')
            ->modalDescription(function (): string {
                $report = $this->report();

                return sprintf(
                    'Save a branded FR0564 PDF for %s (field 16: %s) to Documents and SharePoint?',
                    $report['period_label'],
                    $report['field16_label'],
                );
            })
            ->modalSubmitActionLabel('Generate')
            ->action(function (): void {
                $this->generateReport();
            });
    }

    public function generateReport(): void
    {
        [$year, $month] = $this->selectedPeriod();

        try {
            $document = Fr0564ReportDocumentGenerator::create($year, $month, auth()->user());
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
     * @return array<string, mixed>
     */
    public function report(): array
    {
        [$year, $month] = $this->selectedPeriod();

        return Fr0564ReportBuilder::build($year, $month);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function selectedPeriod(): array
    {
        $key = (string) ($this->data['month'] ?? $this->defaultMonthKey());
        $parts = explode('-', $key);

        $year = (int) ($parts[0] ?? now()->year);
        $month = (int) ($parts[1] ?? now()->month);

        if ($year < 2000 || $month < 1 || $month > 12) {
            $fallback = Carbon::parse($this->defaultMonthKey());

            return [(int) $fallback->year, (int) $fallback->month];
        }

        return [$year, $month];
    }

    private function defaultMonthKey(): string
    {
        $key = now()->copy()->subMonthNoOverflow()->format('Y-m');
        $options = WorkTimeTimesheetPdfGenerator::monthSelectOptions();

        if (array_key_exists($key, $options)) {
            return $key;
        }

        return now()->format('Y-m');
    }

    private function invoicesQuery(): Builder
    {
        [$year, $month] = $this->selectedPeriod();
        $ids = Fr0564ReportBuilder::invoices($year, $month)->pluck('id')->all();

        return Invoice::query()
            ->with(['contact', 'vatRate'])
            ->when(
                $ids === [],
                fn (Builder $query): Builder => $query->whereRaw('0 = 1'),
                fn (Builder $query): Builder => $query->whereIn('id', $ids),
            );
    }
}
