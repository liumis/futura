<?php

namespace App\Filament\Admin\Pages;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeePaymentReport;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\ActivityLogger;
use App\Services\EmployeePaymentReportApprover;
use App\Services\LithuanianPayrollCalculator;
use App\Services\PayrollAuthoritySepaExporter;
use App\Services\SepaPain001Exporter;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class MonthlyPayment extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $title = 'Payments';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 99;

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.monthly-payment';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $reportData = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $companyData = [];

    /**
     * @var array<int, array{
     *     employee_id: int,
     *     employee_name: string,
     *     contract_id: int|null,
     *     base_salary: string,
     *     contract_base_salary: string,
     *     bonus_payment: string,
     *     comment: string,
     *     status: string,
     *     is_locked: bool,
     *     has_schedule: bool,
     *     planned_hours: float,
     *     actual_hours: float,
     *     planned_working_days: int,
     *     actual_working_days: int,
     *     prorated_base_salary: string|null,
     * }>
     */
    public array $rows = [];

    /**
     * @var array<int, array{id: int, payment_date: string, employee_name: string, base_salary: string, bonus_payment: string, comment: string, status: string, is_locked: bool, report_id: int|null, report_status: string|null, report_status_label: string, pending_approvers_label: string|null, can_confirm: bool}>
     */
    public array $reportRows = [];

    /**
     * @var array<int, int>
     */
    public array $selectedReportIds = [];

    public bool $selectAllReports = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'payment_date' => now()->toDateString(),
        ]);

        $this->reportFiltersForm->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'until' => now()->endOfMonth()->toDateString(),
            'employee_id' => null,
        ]);

        $this->fillCompanyForm();
        $this->loadRows();
        $this->loadReportRows();
    }

    /**
     * @return array<int, string>
     */
    protected function getForms(): array
    {
        return [
            'form',
            'reportFiltersForm',
            'companyForm',
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                DatePicker::make('payment_date')
                    ->label('Date')
                    ->native(false)
                    ->format('Y-m-d')
                    ->default(now())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->loadRows()),
            ])
            ->columns(1);
    }

    public function companyForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('companyData')
            ->components([
                TextInput::make('company_name')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('company_iban')
                    ->label('Company IBAN')
                    ->required()
                    ->maxLength(34)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                TextInput::make('company_bic')
                    ->label('Company BIC')
                    ->helperText('Optional. SEB Lithuania is often CBVILT2X.')
                    ->maxLength(11)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                TextInput::make('vmi_iban')
                    ->label('VMI collection IBAN')
                    ->helperText('Payment code 1311. Default SEB account used if empty — verify on vmi.lt.')
                    ->maxLength(34)
                    ->placeholder(PayrollAuthoritySepaExporter::DEFAULT_VMI_IBAN)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                TextInput::make('vmi_bic')
                    ->label('VMI BIC')
                    ->maxLength(11)
                    ->placeholder('CBVILT2X')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                TextInput::make('sodra_iban')
                    ->label('Sodra collection IBAN')
                    ->helperText('Payment code 252. Default SEB account used if empty — verify on sodra.lt.')
                    ->maxLength(34)
                    ->placeholder(PayrollAuthoritySepaExporter::DEFAULT_SODRA_IBAN)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                TextInput::make('sodra_bic')
                    ->label('Sodra BIC')
                    ->maxLength(11)
                    ->placeholder('CBVILT2X')
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),
            ])
            ->columns(3);
    }

    protected function fillCompanyForm(): void
    {
        $company = CompanySetting::instance();

        $this->companyForm->fill([
            'company_name' => $company->company_name,
            'company_iban' => $company->company_iban,
            'company_bic' => $company->company_bic,
            'vmi_iban' => $company->vmi_iban,
            'vmi_bic' => $company->vmi_bic,
            'sodra_iban' => $company->sodra_iban,
            'sodra_bic' => $company->sodra_bic,
        ]);
    }

    public function saveCompanyDetails(): void
    {
        $data = $this->companyForm->getState();
        $sepa = app(SepaPain001Exporter::class);
        $iban = $sepa->normalizeIban((string) ($data['company_iban'] ?? ''));
        $bic = $sepa->normalizeBic((string) ($data['company_bic'] ?? ''));
        $vmiIban = $sepa->normalizeIban((string) ($data['vmi_iban'] ?? ''));
        $vmiBic = $sepa->normalizeBic((string) ($data['vmi_bic'] ?? ''));
        $sodraIban = $sepa->normalizeIban((string) ($data['sodra_iban'] ?? ''));
        $sodraBic = $sepa->normalizeBic((string) ($data['sodra_bic'] ?? ''));

        foreach ([
            'Company' => $iban,
            'VMI' => $vmiIban,
            'Sodra' => $sodraIban,
        ] as $label => $value) {
            if ($value !== '' && ! $sepa->isValidIban($value)) {
                Notification::make()
                    ->title($label.' IBAN is invalid')
                    ->danger()
                    ->send();

                return;
            }
        }

        $company = CompanySetting::query()->firstOrCreate([]);
        $company->update([
            'company_name' => $data['company_name'] ?? null,
            'company_iban' => $iban !== '' ? $iban : null,
            'company_bic' => $bic !== '' ? $bic : null,
            'vmi_iban' => $vmiIban !== '' ? $vmiIban : null,
            'vmi_bic' => $vmiBic !== '' ? $vmiBic : null,
            'sodra_iban' => $sodraIban !== '' ? $sodraIban : null,
            'sodra_bic' => $sodraBic !== '' ? $sodraBic : null,
        ]);

        Notification::make()
            ->title('Company details saved')
            ->success()
            ->send();

        $this->fillCompanyForm();
    }

    public function reportFiltersForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('reportData')
            ->components([
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->loadReportRows()),

                DatePicker::make('until')
                    ->label('Until')
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->loadReportRows()),

                Select::make('employee_id')
                    ->label('Employee')
                    ->options(
                        fn (): array => Employee::query()
                            ->orderBy('surname')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Employee $employee): array => [
                                $employee->getKey() => $employee->fullName(),
                            ])
                            ->all()
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (): mixed => $this->loadReportRows()),
            ])
            ->columns(3);
    }

    public function loadRows(): void
    {
        $paymentDate = Carbon::parse((string) ($this->data['payment_date'] ?? now()))->startOfDay();
        $year = (int) $paymentDate->year;
        $month = (int) $paymentDate->month;

        $contracts = EmployeeContract::query()
            ->with('employee')
            ->validDuringMonth($paymentDate->copy()->startOfMonth())
            ->orderByDesc('effective_date_from')
            ->get()
            ->unique('employee_id')
            ->values();

        $existing = EmployeeMonthlyPayment::query()
            ->forDate($paymentDate)
            ->get()
            ->keyBy('employee_id');

        $this->rows = $contracts
            ->filter(fn (EmployeeContract $contract): bool => $contract->employee instanceof Employee)
            ->sortBy(fn (EmployeeContract $contract): string => mb_strtolower($contract->employee->fullName()))
            ->values()
            ->map(function (EmployeeContract $contract) use ($existing, $year, $month): array {
                $payment = $existing->get($contract->employee_id);
                $contractBase = (float) ($contract->base_salary ?? 0);
                $baseSalary = $payment?->base_salary ?? $contract->base_salary;
                $defaultBonus = $contract->default_bonus !== null
                    ? $this->formatAmount($contract->default_bonus)
                    : '';
                $schedule = WorkSchedule::monthSummaryForEmployee(
                    (int) $contract->employee_id,
                    $year,
                    $month,
                );
                $prorated = $schedule['has_schedule'] && $schedule['planned_hours'] > 0
                    ? WorkSchedule::prorateSalary(
                        $contractBase,
                        $schedule['planned_hours'],
                        $schedule['actual_hours'],
                    )
                    : null;

                return [
                    'employee_id' => (int) $contract->employee_id,
                    'employee_name' => $contract->employee->fullName(),
                    'contract_id' => $contract->getKey(),
                    'base_salary' => $this->formatAmount($baseSalary),
                    'contract_base_salary' => $this->formatAmount($contractBase),
                    'bonus_payment' => $payment?->bonus_payment !== null
                        ? $this->formatAmount($payment->bonus_payment)
                        : $defaultBonus,
                    'comment' => (string) ($payment?->comment ?? ''),
                    'status' => ($payment?->status ?? EmployeeMonthlyPaymentStatus::Open)->value,
                    'is_locked' => $payment?->isLocked() ?? false,
                    'has_schedule' => $schedule['has_schedule'],
                    'planned_hours' => $schedule['planned_hours'],
                    'actual_hours' => $schedule['actual_hours'],
                    'planned_working_days' => $schedule['planned_working_days'],
                    'actual_working_days' => $schedule['actual_working_days'],
                    'prorated_base_salary' => $prorated !== null ? $this->formatAmount($prorated) : null,
                ];
            })
            ->all();
    }

    public function applyActualHoursToSalary(int $index): void
    {
        if (! isset($this->rows[$index]) || ($this->rows[$index]['is_locked'] ?? false)) {
            return;
        }

        $row = $this->rows[$index];

        if (! ($row['has_schedule'] ?? false) || (float) ($row['planned_hours'] ?? 0) <= 0) {
            Notification::make()
                ->title('No work schedule hours for this month')
                ->warning()
                ->send();

            return;
        }

        $contractBase = $this->parseAmount($row['contract_base_salary'] ?? null)
            ?? $this->parseAmount($row['base_salary'] ?? null)
            ?? 0;

        $prorated = WorkSchedule::prorateSalary(
            $contractBase,
            (float) $row['planned_hours'],
            (float) $row['actual_hours'],
        );

        $this->rows[$index]['base_salary'] = $this->formatAmount($prorated);

        Notification::make()
            ->title('Base salary updated from actual hours')
            ->body(sprintf(
                '%s h actual / %s h plan → %s',
                number_format((float) $row['actual_hours'], 2),
                number_format((float) $row['planned_hours'], 2),
                $this->moneyPrefix().$this->formatAmount($prorated),
            ))
            ->success()
            ->send();
    }

    public function applyActualHoursToAllSalaries(): void
    {
        $updated = 0;

        foreach ($this->rows as $index => $row) {
            if ($row['is_locked'] ?? false) {
                continue;
            }

            if (! ($row['has_schedule'] ?? false) || (float) ($row['planned_hours'] ?? 0) <= 0) {
                continue;
            }

            $contractBase = $this->parseAmount($row['contract_base_salary'] ?? null)
                ?? $this->parseAmount($row['base_salary'] ?? null)
                ?? 0;

            $prorated = WorkSchedule::prorateSalary(
                $contractBase,
                (float) $row['planned_hours'],
                (float) $row['actual_hours'],
            );

            $this->rows[$index]['base_salary'] = $this->formatAmount($prorated);
            $updated++;
        }

        Notification::make()
            ->title($updated > 0
                ? "Updated {$updated} base salaries from actual hours"
                : 'No unlocked rows with schedule hours to update')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $paymentDate = Carbon::parse((string) ($this->data['payment_date'] ?? now()))->toDateString();
        $skippedLocked = 0;
        $calculator = app(LithuanianPayrollCalculator::class);
        $employees = Employee::query()
            ->whereIn('id', collect($this->rows)->pluck('employee_id')->filter()->map(fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');

        foreach ($this->rows as $row) {
            $employeeId = (int) ($row['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $existing = EmployeeMonthlyPayment::query()
                ->where('employee_id', $employeeId)
                ->whereDate('payment_date', $paymentDate)
                ->first();

            if ($existing?->isLocked()) {
                $comment = trim((string) ($row['comment'] ?? ''));
                $existing->update([
                    'comment' => $comment !== '' ? $comment : null,
                ]);
                $skippedLocked++;

                continue;
            }

            $baseSalary = $this->parseAmount($row['base_salary'] ?? null) ?? 0;
            $bonusRaw = trim((string) ($row['bonus_payment'] ?? ''));
            $bonusPayment = $bonusRaw === '' ? null : ($this->parseAmount($bonusRaw) ?? 0);
            $comment = trim((string) ($row['comment'] ?? ''));
            $employee = $employees->get($employeeId);
            $tax = $this->taxSnapshotAttributes($calculator, $employee, $baseSalary, $bonusPayment, $paymentDate);

            EmployeeMonthlyPayment::query()->updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'payment_date' => $paymentDate,
                ],
                [
                    'base_salary' => $baseSalary,
                    'bonus_payment' => $bonusPayment,
                    'comment' => $comment !== '' ? $comment : null,
                    'status' => EmployeeMonthlyPaymentStatus::Open,
                    'is_paid' => false,
                    ...$tax,
                ],
            );
        }

        Notification::make()
            ->title($skippedLocked > 0
                ? 'Monthly payments saved (locked amounts unchanged; comments updated)'
                : 'Monthly payments saved')
            ->success()
            ->send();

        $date = Carbon::parse($paymentDate);
        $this->reportFiltersForm->fill([
            'from' => $date->copy()->startOfMonth()->toDateString(),
            'until' => $date->copy()->endOfMonth()->toDateString(),
            'employee_id' => $this->reportData['employee_id'] ?? null,
        ]);

        $this->loadRows();
        $this->loadReportRows();
    }

    public function loadReportRows(): void
    {
        $from = filled($this->reportData['from'] ?? null)
            ? Carbon::parse((string) $this->reportData['from'])->startOfDay()
            : null;
        $until = filled($this->reportData['until'] ?? null)
            ? Carbon::parse((string) $this->reportData['until'])->endOfDay()
            : null;
        $employeeId = filled($this->reportData['employee_id'] ?? null)
            ? (int) $this->reportData['employee_id']
            : null;

        $query = EmployeeMonthlyPayment::query()
            ->with(['employee', 'paymentReport.approvers'])
            ->orderByDesc('payment_date')
            ->orderBy('employee_id');

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        if ($from !== null) {
            $query->whereDate('payment_date', '>=', $from->toDateString());
        }

        if ($until !== null) {
            $query->whereDate('payment_date', '<=', $until->toDateString());
        }

        $this->selectedReportIds = [];
        $this->selectAllReports = false;
        $currentUserId = auth()->id() ? (int) auth()->id() : null;

        $this->reportRows = $query
            ->get()
            ->filter(fn (EmployeeMonthlyPayment $payment): bool => $payment->employee instanceof Employee)
            ->mapWithKeys(function (EmployeeMonthlyPayment $payment) use ($currentUserId): array {
                $status = $payment->status ?? EmployeeMonthlyPaymentStatus::Open;
                $report = $payment->paymentReport;
                $canConfirm = false;
                $id = (int) $payment->getKey();

                $pendingApproversLabel = null;
                if ($report !== null) {
                    $pendingApprovers = $report->approvers
                        ->filter(fn (User $approver): bool => blank($approver->pivot?->approved_at))
                        ->values();

                    $pendingApproversLabel = $pendingApprovers
                        ->map(function (User $approver): string {
                            $name = trim((string) (($approver->name ?? '').' '.($approver->surname ?? '')));

                            return $name !== ''
                                ? $name
                                : (filled($approver->email) ? (string) $approver->email : 'User #'.$approver->id);
                        })
                        ->implode(', ');

                    if ($currentUserId !== null) {
                        $canConfirm = $pendingApprovers->contains(
                            fn (User $approver): bool => (int) $approver->getKey() === $currentUserId,
                        );
                    }
                }

                return [
                    $id => [
                        'id' => $id,
                        'payment_date' => $payment->payment_date?->toDateString() ?? '',
                        'employee_name' => $payment->employee->fullName(),
                        'base_salary' => $this->formatAmount($payment->base_salary),
                        'bonus_payment' => $payment->bonus_payment !== null
                            ? $this->formatAmount($payment->bonus_payment)
                            : '',
                        'gross_amount' => $payment->gross_amount !== null
                            ? $this->formatAmount($payment->gross_amount)
                            : null,
                        'npd_amount' => $payment->npd_amount !== null
                            ? $this->formatAmount($payment->npd_amount)
                            : null,
                        'sodra_employee_amount' => $payment->sodra_employee_amount !== null
                            ? $this->formatAmount($payment->sodra_employee_amount)
                            : null,
                        'sodra_employer_amount' => $payment->sodra_employer_amount !== null
                            ? $this->formatAmount($payment->sodra_employer_amount)
                            : null,
                        'gpm_amount' => $payment->gpm_amount !== null
                            ? $this->formatAmount($payment->gpm_amount)
                            : null,
                        'net_amount' => $payment->net_amount !== null
                            ? $this->formatAmount($payment->net_amount)
                            : null,
                        'comment' => (string) ($payment->comment ?? ''),
                        'status' => $status->value,
                        'is_locked' => $status->isLocked(),
                        'report_id' => $report?->getKey() ? (int) $report->getKey() : null,
                        'report_status' => $report?->status?->value,
                        'report_status_label' => $report?->status?->label() ?? '—',
                        'pending_approvers_label' => $pendingApproversLabel,
                        'can_confirm' => $canConfirm,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function selectableReportIds(): array
    {
        return collect($this->reportRows)
            ->filter(fn (array $row): bool => in_array(
                (string) ($row['status'] ?? ''),
                [
                    EmployeeMonthlyPaymentStatus::Open->value,
                    EmployeeMonthlyPaymentStatus::Payed->value,
                ],
                true,
            ))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function approvableReportIds(): array
    {
        return collect($this->reportRows)
            ->filter(fn (array $row): bool => empty($row['report_id']))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    protected function openReportIds(): array
    {
        return collect($this->reportRows)
            ->filter(fn (array $row): bool => ($row['status'] ?? '') === EmployeeMonthlyPaymentStatus::Open->value
                && empty($row['report_id']))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function updatedSelectAllReports(bool $value): void
    {
        if ($value) {
            $this->selectedReportIds = $this->selectableReportIds();

            return;
        }

        $this->selectedReportIds = [];
    }

    public function updatedSelectedReportIds(): void
    {
        $selectableIds = $this->selectableReportIds();

        $this->selectedReportIds = collect($this->selectedReportIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $selectableIds, true))
            ->values()
            ->all();

        $this->selectAllReports = count($selectableIds) > 0
            && count($this->selectedReportIds) === count($selectableIds);
    }

    public function payPayment(int $id): void
    {
        $payment = EmployeeMonthlyPayment::query()->find($id);

        if (! $payment || $payment->status !== EmployeeMonthlyPaymentStatus::Open) {
            return;
        }

        $payment->markPayed();

        Notification::make()
            ->title('Payment marked as payed')
            ->success()
            ->send();

        $this->loadRows();
        $this->loadReportRows();
    }

    public function markPaymentCancelled(int $id): void
    {
        $payment = EmployeeMonthlyPayment::query()->find($id);

        if (! $payment || $payment->status !== EmployeeMonthlyPaymentStatus::Payed) {
            return;
        }

        $payment->markWrong();

        Notification::make()
            ->title('Payment cancelled')
            ->success()
            ->send();

        $this->loadRows();
        $this->loadReportRows();
    }

    public function paySelected(): void
    {
        $ids = collect($this->selectedReportIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('Select at least one open payment')
                ->warning()
                ->send();

            return;
        }

        $payments = EmployeeMonthlyPayment::query()
            ->whereIn('id', $ids)
            ->where('status', EmployeeMonthlyPaymentStatus::Open)
            ->get();

        foreach ($payments as $payment) {
            $payment->markPayed();
        }

        Notification::make()
            ->title($payments->count() === 1
                ? '1 payment marked as payed'
                : $payments->count().' payments marked as payed')
            ->success()
            ->send();

        $this->loadRows();
        $this->loadReportRows();
    }

    public function exportSepaXml(): ?StreamedResponse
    {
        $ids = collect($this->selectedReportIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('Select at least one open payment')
                ->warning()
                ->send();

            return null;
        }

        $payments = EmployeeMonthlyPayment::query()
            ->with('employee')
            ->whereIn('id', $ids)
            ->where('status', EmployeeMonthlyPaymentStatus::Open)
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            Notification::make()
                ->title('No open payments selected')
                ->warning()
                ->send();

            return null;
        }

        try {
            $xml = app(SepaPain001Exporter::class)->buildXml($payments);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('SEPA export failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $filename = 'sepa-salary-'.now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded('SEPA salary export', 'xml', properties: [
            'file_name' => $filename,
            'payment_ids' => $payments->modelKeys(),
            'count' => $payments->count(),
        ]);

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    public function exportVmiXml(): ?StreamedResponse
    {
        return $this->exportAuthorityXml('vmi');
    }

    public function exportSodraXml(): ?StreamedResponse
    {
        return $this->exportAuthorityXml('sodra');
    }

    public function exportXls(): ?StreamedResponse
    {
        if ($this->reportRows === []) {
            Notification::make()
                ->title('No payments to export')
                ->warning()
                ->send();

            return null;
        }

        $fileName = 'payments-report-'.now()->format('Ymd-His').'.xls';
        $rows = $this->reportRows;

        ActivityLogger::logReportDownloaded('Monthly payments XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => count($rows),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $headers = [
                'Date',
                'Person',
                'Base (Gross)',
                'Bonus (gross)',
                'Gross',
                'NPD',
                'GPM 20%',
                'Sodra health 6.98%',
                'Sodra pension & soc.',
                'Net to employee',
                'Sodra employer 1.77%',
                'Workplace cost',
                'Comment',
                'Payment status',
                'Report status',
            ];

            echo '<table border="1"><tr>';
            foreach ($headers as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($rows as $row) {
                $base = (float) str_replace(',', '.', (string) ($row['base_salary'] ?? 0));
                $bonusRaw = trim((string) ($row['bonus_payment'] ?? ''));
                $bonus = $bonusRaw === '' ? 0.0 : (float) str_replace(',', '.', $bonusRaw);

                $grossOut = $row['gross_amount'] !== null
                    ? (string) $row['gross_amount']
                    : number_format($base + $bonus, 2, '.', '');
                $grossNum = (float) str_replace(',', '.', $grossOut);

                $npdOut = $row['npd_amount'] !== null ? (string) $row['npd_amount'] : '';
                $gpmOut = $row['gpm_amount'] !== null ? (string) $row['gpm_amount'] : '';

                $sodraEmployeeNum = $row['sodra_employee_amount'] !== null
                    ? (float) str_replace(',', '.', (string) $row['sodra_employee_amount'])
                    : null;
                $sodraEmployerNum = $row['sodra_employer_amount'] !== null
                    ? (float) str_replace(',', '.', (string) $row['sodra_employer_amount'])
                    : null;

                $sodraHealthOut = '';
                $sodraPensionOut = '';
                if ($sodraEmployeeNum !== null) {
                    $sodraHealthNum = round($grossNum * 0.0698, 2);
                    $sodraPensionNum = round($sodraEmployeeNum - $sodraHealthNum, 2);

                    $sodraHealthOut = number_format($sodraHealthNum, 2, '.', '');
                    $sodraPensionOut = number_format($sodraPensionNum, 2, '.', '');
                }

                $netOut = $row['net_amount'] !== null ? (string) $row['net_amount'] : '';
                $workplaceCostOut = '';
                if ($sodraEmployerNum !== null) {
                    $workplaceCostOut = number_format(round($grossNum + $sodraEmployerNum, 2), 2, '.', '');
                }

                $sodraEmployerOut = $sodraEmployerNum !== null
                    ? number_format($sodraEmployerNum, 2, '.', '')
                    : '';

                $paymentStatus = EmployeeMonthlyPaymentStatus::tryFrom((string) ($row['status'] ?? ''))?->label()
                    ?? (string) ($row['status'] ?? '');

                echo '<tr>';
                echo '<td>'.e((string) ($row['payment_date'] ?? '')).'</td>';
                echo '<td>'.e((string) ($row['employee_name'] ?? '')).'</td>';
                echo '<td>'.e((string) ($row['base_salary'] ?? '')).'</td>';
                echo '<td>'.e((string) ($row['bonus_payment'] ?? '')).'</td>';
                echo '<td>'.e($grossOut).'</td>';
                echo '<td>'.e($npdOut).'</td>';
                echo '<td>'.e($gpmOut).'</td>';
                echo '<td>'.e($sodraHealthOut).'</td>';
                echo '<td>'.e($sodraPensionOut).'</td>';
                echo '<td>'.e($netOut).'</td>';
                echo '<td>'.e($sodraEmployerOut).'</td>';
                echo '<td>'.e($workplaceCostOut).'</td>';
                echo '<td>'.e((string) ($row['comment'] ?? '')).'</td>';
                echo '<td>'.e($paymentStatus).'</td>';
                echo '<td>'.e((string) ($row['report_status_label'] ?? '—')).'</td>';
                echo '</tr>';
            }

            echo '</table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    /**
     * @param  'vmi'|'sodra'  $type
     */
    protected function exportAuthorityXml(string $type): ?StreamedResponse
    {
        $payments = $this->selectedPaymentsForAuthorityExport();

        if ($payments === null) {
            return null;
        }

        try {
            $exporter = app(PayrollAuthoritySepaExporter::class);
            $xml = $type === 'vmi'
                ? $exporter->buildVmiXml($payments)
                : $exporter->buildSodraXml($payments);
        } catch (\Throwable $e) {
            Notification::make()
                ->title($type === 'vmi' ? 'VMI export failed' : 'Sodra export failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return null;
        }

        $filename = ($type === 'vmi' ? 'sepa-vmi-gpm-' : 'sepa-sodra-').now()->format('Ymd-His').'.xml';

        ActivityLogger::logReportDownloaded(
            $type === 'vmi' ? 'VMI GPM export' : 'Sodra export',
            'xml',
            properties: [
                'file_name' => $filename,
                'type' => $type,
                'payment_ids' => $payments->modelKeys(),
                'count' => $payments->count(),
            ],
        );

        return response()->streamDownload(function () use ($xml): void {
            echo $xml;
        }, $filename, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * @return Collection<int, EmployeeMonthlyPayment>|null
     */
    protected function selectedPaymentsForAuthorityExport(): ?\Illuminate\Support\Collection
    {
        $ids = collect($this->selectedReportIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            Notification::make()
                ->title('Select at least one payment')
                ->warning()
                ->send();

            return null;
        }

        $payments = EmployeeMonthlyPayment::query()
            ->with('employee')
            ->whereIn('id', $ids)
            ->whereIn('status', [
                EmployeeMonthlyPaymentStatus::Open->value,
                EmployeeMonthlyPaymentStatus::Payed->value,
            ])
            ->orderBy('id')
            ->get();

        if ($payments->isEmpty()) {
            Notification::make()
                ->title('No open or payed payments selected')
                ->warning()
                ->send();

            return null;
        }

        return $payments;
    }

    public function saveReportPayment(int $id): void
    {
        $payment = EmployeeMonthlyPayment::query()->find($id);

        if (! $payment) {
            return;
        }

        $row = collect($this->reportRows)->firstWhere('id', $id);

        if (! is_array($row)) {
            return;
        }

        $comment = trim((string) ($row['comment'] ?? ''));

        if ($payment->isLocked()) {
            $payment->update([
                'comment' => $comment !== '' ? $comment : null,
            ]);

            Notification::make()
                ->title('Comment saved')
                ->success()
                ->send();

            $this->loadRows();
            $this->loadReportRows();

            return;
        }

        $paymentDate = filled($row['payment_date'] ?? null)
            ? Carbon::parse((string) $row['payment_date'])->toDateString()
            : null;

        if ($paymentDate === null) {
            Notification::make()
                ->title('Payment date is required')
                ->danger()
                ->send();

            return;
        }

        $duplicate = EmployeeMonthlyPayment::query()
            ->where('employee_id', $payment->employee_id)
            ->whereDate('payment_date', $paymentDate)
            ->whereKeyNot($payment->getKey())
            ->exists();

        if ($duplicate) {
            Notification::make()
                ->title('A payment for this person already exists on that date')
                ->danger()
                ->send();

            return;
        }

        $bonusRaw = trim((string) ($row['bonus_payment'] ?? ''));
        $baseSalary = $this->parseAmount($row['base_salary'] ?? null) ?? 0;
        $bonusPayment = $bonusRaw === '' ? null : ($this->parseAmount($bonusRaw) ?? 0);
        $payment->loadMissing('employee');
        $tax = $this->taxSnapshotAttributes(
            app(LithuanianPayrollCalculator::class),
            $payment->employee,
            $baseSalary,
            $bonusPayment,
            $paymentDate,
        );

        $payment->update([
            'payment_date' => $paymentDate,
            'base_salary' => $baseSalary,
            'bonus_payment' => $bonusPayment,
            'comment' => $comment !== '' ? $comment : null,
            ...$tax,
        ]);

        Notification::make()
            ->title('Payment saved')
            ->success()
            ->send();

        $this->loadRows();
        $this->loadReportRows();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Save payments')
                ->modalDescription('Save salary and bonus values for the selected date?')
                ->modalSubmitActionLabel('Save')
                ->action('save'),
        ];
    }

    public function savePaymentsAction(): Action
    {
        return Action::make('savePayments')
            ->label('Save')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Save payments')
            ->modalDescription('Save salary and bonus values for the selected date?')
            ->modalSubmitActionLabel('Save')
            ->action(fn (): mixed => $this->save());
    }

    public function saveReportPaymentAction(): Action
    {
        return Action::make('saveReportPayment')
            ->label('Save')
            ->requiresConfirmation()
            ->modalHeading('Save payment')
            ->modalDescription('Save changes to this payment?')
            ->modalSubmitActionLabel('Save')
            ->action(function (array $arguments): void {
                $this->saveReportPayment((int) ($arguments['id'] ?? 0));
            });
    }

    public function payPaymentAction(): Action
    {
        return Action::make('payPayment')
            ->label('Payed')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Mark as payed')
            ->modalDescription('Mark this payment as payed?')
            ->modalSubmitActionLabel('Payed')
            ->action(function (array $arguments): void {
                $this->payPayment((int) ($arguments['id'] ?? 0));
            });
    }

    public function cancelPaymentAction(): Action
    {
        return Action::make('cancelPayment')
            ->label('Cancel')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel payment')
            ->modalDescription('Cancel this payment? Fields stay locked.')
            ->modalSubmitActionLabel('Cancel payment')
            ->action(function (array $arguments): void {
                $this->markPaymentCancelled((int) ($arguments['id'] ?? 0));
            });
    }

    public function confirmPaymentReportAction(): Action
    {
        return Action::make('confirmPaymentReport')
            ->label('Confirm')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Confirm payment report')
            ->modalDescription('Confirm this payment report?')
            ->modalSubmitActionLabel('Confirm')
            ->action(function (array $arguments): void {
                $this->confirmPaymentReport((int) ($arguments['reportId'] ?? 0));
            });
    }

    public function paySelectedAction(): Action
    {
        return Action::make('paySelected')
            ->label('Payed')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Mark selected as payed')
            ->modalDescription('Mark selected payments as payed?')
            ->modalSubmitActionLabel('Payed')
            ->action(fn (): mixed => $this->paySelected());
    }

    public function approveReportAction(): Action
    {
        return Action::make('approveReport')
            ->label('Create report')
            ->modalHeading('Create payment report')
            ->modalDescription('Select people who must confirm this payment report. A PDF will be stored in Documents.')
            ->modalSubmitActionLabel('Create report')
            ->form([
                Select::make('approver_user_ids')
                    ->label('Approvals needed from')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->options(fn (): array => $this->approverUserOptions()),
            ])
            ->action(function (array $data): void {
                $user = auth()->user();
                if (! $user instanceof User) {
                    return;
                }

                $ids = collect($this->selectedReportIds)
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values()
                    ->all();

                if ($ids === []) {
                    Notification::make()
                        ->title('Select at least one payment')
                        ->warning()
                        ->send();

                    return;
                }

                $payments = EmployeeMonthlyPayment::query()
                    ->with('employee')
                    ->whereIn('id', $ids)
                    ->whereNull('employee_payment_report_id')
                    ->get();

                if ($payments->isEmpty()) {
                    Notification::make()
                        ->title('Selected payments are already in a report')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $report = EmployeePaymentReportApprover::create(
                        $payments,
                        $data['approver_user_ids'] ?? [],
                        $user,
                    );
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Could not create payment report')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Payment report created')
                    ->body($report->status?->label() ?? 'Report saved to Documents.')
                    ->success()
                    ->send();

                $this->loadRows();
                $this->loadReportRows();
            });
    }

    public function confirmPaymentReport(int $reportId): void
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return;
        }

        $report = EmployeePaymentReport::query()->find($reportId);
        if (! $report) {
            return;
        }

        try {
            EmployeePaymentReportApprover::confirmBy($report, $user);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Confirmation failed')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Payment report confirmed')
            ->success()
            ->send();

        $this->loadRows();
        $this->loadReportRows();
    }

    /**
     * @return array<int, string>
     */
    protected function approverUserOptions(): array
    {
        return User::query()
            ->whereDoesntHave(
                'roles',
                fn (Builder $query): Builder => $query->where('name', 'customer'),
            )
            ->orderBy('name')
            ->orderBy('surname')
            ->get()
            ->mapWithKeys(function (User $user): array {
                $name = trim(($user->name ?? '').' '.($user->surname ?? ''));
                $label = $name !== ''
                    ? (filled($user->email) ? $name.' ('.$user->email.')' : $name)
                    : (string) ($user->email ?? 'User #'.$user->id);

                return [$user->id => $label];
            })
            ->all();
    }

    /**
     * @return array{
     *     gross_amount: float,
     *     npd_amount: float,
     *     sodra_employee_amount: float,
     *     sodra_employer_amount: float,
     *     gpm_amount: float,
     *     net_amount: float
     * }
     */
    protected function taxSnapshotAttributes(
        LithuanianPayrollCalculator $calculator,
        ?Employee $employee,
        float $baseSalary,
        ?float $bonusPayment,
        mixed $paymentDate,
    ): array {
        $gross = round($baseSalary + (float) ($bonusPayment ?? 0), 2);
        $year = $calculator->yearFromDate($paymentDate);
        $tax = $calculator->calculate($gross, $employee, year: $year);

        return [
            'gross_amount' => $tax['gross'],
            'npd_amount' => $tax['npd'],
            'sodra_employee_amount' => $tax['sodra_employee'],
            'sodra_employer_amount' => $tax['sodra_employer'],
            'gpm_amount' => $tax['gpm'],
            'net_amount' => $tax['net'],
        ];
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    protected function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }

    public function moneyPrefix(): string
    {
        return Money::prefix();
    }
}
