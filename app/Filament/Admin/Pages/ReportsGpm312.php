<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Dividend;
use App\Models\Invoice;
use App\Services\Gpm312ReportBuilder;
use App\Services\Gpm312ReportDocumentGenerator;
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

class ReportsGpm312 extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationLabel = 'GPM312 report';

    protected static ?string $title = 'GPM312 report';

    protected static ?string $slug = 'gpm312-report';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 24;

    protected string $view = 'filament.admin.pages.reports-gpm312';

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
            'year' => $this->defaultYearKey(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('year')
                    ->label('Year')
                    ->options(fn (): array => Gpm312ReportBuilder::yearSelectOptions())
                    ->required()
                    ->native(false)
                    ->live(),
            ])
            ->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->dividendsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('shareholder.name')
                    ->label('Shareholder')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('date')
                    ->label('Payment date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Gross (L8)')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('gpm_amount')
                    ->label('GPM (L10)')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('net_amount')
                    ->label('Net')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
            ])
            ->defaultSort('date')
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
            ->modalHeading('Generate GPM312 declaration')
            ->modalDescription(function (): string {
                $report = $this->report();

                return sprintf(
                    'Save a branded GPM312 PDF for %s (%s GPM312L line(s), GPM %s) to Documents and SharePoint?',
                    $report['period_label'],
                    $report['line_count'],
                    $report['field5_label'],
                );
            })
            ->modalSubmitActionLabel('Generate')
            ->action(function (): void {
                $this->generateReport();
            });
    }

    public function generateReport(): void
    {
        $year = $this->selectedYear();

        try {
            $document = Gpm312ReportDocumentGenerator::create($year, auth()->user());
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
     *     year: int,
     *     period_label: string,
     *     company_name: string,
     *     company_id: string,
     *     company_address: string,
     *     company_email: string,
     *     company_phone: string,
     *     field4: float,
     *     field5: float,
     *     field6: float,
     *     field7: float,
     *     field4_label: string,
     *     field5_label: string,
     *     field6_label: string,
     *     field7_label: string,
     *     dividend_count: int,
     *     shareholder_count: int,
     *     invoice_count: int,
     *     provider_count: int,
     *     line_count: int,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function report(): array
    {
        return Gpm312ReportBuilder::build($this->selectedYear());
    }

    public function selectedYear(): int
    {
        $year = (int) ($this->data['year'] ?? $this->defaultYearKey());

        if ($year < 2000 || $year > 2100) {
            return (int) $this->defaultYearKey();
        }

        return $year;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function privateProviderInvoices(): Collection
    {
        return Gpm312ReportBuilder::privateProviderInvoices($this->selectedYear());
    }

    private function defaultYearKey(): string
    {
        $key = (string) now()->copy()->subYear()->year;
        $options = Gpm312ReportBuilder::yearSelectOptions();

        if (array_key_exists($key, $options)) {
            return $key;
        }

        return (string) now()->year;
    }

    private function dividendsQuery(): Builder
    {
        $period = Gpm312ReportBuilder::period($this->selectedYear());

        return Dividend::query()
            ->with('shareholder')
            ->whereDate('date', '>=', $period['from']->toDateString())
            ->whereDate('date', '<=', $period['until']->toDateString())
            ->whereIn('status', ['open', 'payed']);
    }
}
