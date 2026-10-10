<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\EmployeeMonthlyPayment;
use App\Services\Gpm313ReportBuilder;
use App\Services\Gpm313ReportDocumentGenerator;
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

class ReportsGpm313 extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'GPM313 report';

    protected static ?string $title = 'GPM313 report';

    protected static ?string $slug = 'gpm313-report';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 23;

    protected string $view = 'filament.admin.pages.reports-gpm313';

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
            ->query(fn (): Builder => $this->paymentsQuery())
            ->columns([
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->getStateUsing(fn (EmployeeMonthlyPayment $record): string => $record->employee?->fullName() ?: '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('employee', function (Builder $employeeQuery) use ($search): void {
                            $employeeQuery
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('surname', 'like', '%'.$search.'%');
                        });
                    }),

                Tables\Columns\TextColumn::make('payment_date')
                    ->label('Payment date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('gross_amount')
                    ->label('Gross (field 5)')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('gpm_amount')
                    ->label('GPM')
                    ->money(Money::currency())
                    ->alignEnd()
                    ->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->money(Money::currency())->label('Total')),

                Tables\Columns\TextColumn::make('gpm_window')
                    ->label('GPM window')
                    ->getStateUsing(function (EmployeeMonthlyPayment $record): string {
                        $day = (int) $record->payment_date?->day;

                        return $day <= 15 ? 'Until 15th (6)' : 'From 16th (7)';
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
            ])
            ->defaultSort('payment_date')
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
            ->modalHeading('Generate GPM313 declaration')
            ->modalDescription(function (): string {
                $report = $this->report();

                return sprintf(
                    'Save a branded GPM313 PDF for %s (employment GPM %s) to Documents and SharePoint?',
                    $report['period_label'],
                    $report['field6_label'].' + '.$report['field7_label'],
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
            $document = Gpm313ReportDocumentGenerator::create($year, $month, auth()->user());
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
     *     month: int,
     *     period_key: string,
     *     period_label: string,
     *     month_name: string,
     *     company_name: string,
     *     company_id: string,
     *     company_address: string,
     *     company_email: string,
     *     company_phone: string,
     *     field5: float,
     *     field6: float,
     *     field7: float,
     *     field8: float,
     *     field9: float,
     *     field10: float,
     *     field11: float,
     *     field12: float,
     *     field5_label: string,
     *     field6_label: string,
     *     field7_label: string,
     *     field8_label: string,
     *     field9_label: string,
     *     field10_label: string,
     *     field11_label: string,
     *     field12_label: string,
     *     employment_count: int,
     *     dividend_count: int
     * }
     */
    public function report(): array
    {
        [$year, $month] = $this->selectedPeriod();

        return Gpm313ReportBuilder::build($year, $month);
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

    private function paymentsQuery(): Builder
    {
        [$year, $month] = $this->selectedPeriod();
        $period = Gpm313ReportBuilder::period($year, $month);

        return EmployeeMonthlyPayment::query()
            ->with('employee')
            ->whereDate('payment_date', '>=', $period['from']->toDateString())
            ->whereDate('payment_date', '<=', $period['until']->toDateString())
            ->whereIn('status', ['open', 'payed']);
    }
}
