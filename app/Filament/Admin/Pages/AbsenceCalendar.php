<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BusinessTripRequestStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\BusinessTripRequests\BusinessTripRequestResource;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\BusinessTripRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRequestType;
use App\Models\WorkScheduleEntry;
use App\Services\ActivityLogger;
use App\Services\AnnualLeaveCalculator;
use App\Services\ParentalLeaveCalculator;
use App\Services\WorkScheduleGenerator;
use App\Services\WorkTimeTimesheetPdfGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

class AbsenceCalendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Work calendar';

    protected static ?string $title = 'Work calendar';

    protected static string|\UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.admin.pages.absence-calendar';

    #[Url(as: 'employee_id')]
    public ?int $employeeId = null;

    #[Url(as: 'status')]
    public ?string $status = null;

    public ?string $annualLeaveAsOf = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $annualLeaveResult = null;

    public ?string $parentalLeaveAsOf = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $parentalLeaveResult = null;

    public int $calendarVersion = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timesheetPdf')
                ->label('Timesheet PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->modalHeading('Generate timesheet')
                ->modalDescription('Select a month and employees. The PDF follows the Lithuanian monthly working-time register.')
                ->modalSubmitActionLabel('Generate PDF')
                ->modalWidth('lg')
                ->fillForm(fn (): array => [
                    'month' => now()->format('Y-m'),
                    'select_all' => false,
                    'employee_ids' => $this->employeeId !== null ? [$this->employeeId] : [],
                ])
                ->form([
                    Select::make('month')
                        ->label('Month')
                        ->options(fn (): array => WorkTimeTimesheetPdfGenerator::monthSelectOptions())
                        ->default(now()->format('Y-m'))
                        ->required()
                        ->native(false)
                        ->searchable(),

                    Checkbox::make('select_all')
                        ->label('Select all employees')
                        ->live()
                        ->afterStateUpdated(function (bool $state, Set $set): void {
                            $set('employee_ids', $state ? self::allEmployeeIds() : []);
                        }),

                    Select::make('employee_ids')
                        ->label('Employees')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->options(fn (): array => self::allEmployeeOptions())
                        ->live()
                        ->afterStateUpdated(function (?array $state, Set $set): void {
                            $selected = collect($state ?? [])->map(fn ($id): int => (int) $id)->all();
                            $all = self::allEmployeeIds();
                            $set('select_all', $all !== [] && count($selected) === count($all));
                        }),
                ])
                ->action(function (array $data) {
                    $monthKey = (string) ($data['month'] ?? '');
                    $parts = explode('-', $monthKey);
                    $year = (int) ($parts[0] ?? 0);
                    $month = (int) ($parts[1] ?? 0);
                    $employeeIds = collect($data['employee_ids'] ?? [])
                        ->map(fn ($id): int => (int) $id)
                        ->filter(fn (int $id): bool => $id > 0)
                        ->unique()
                        ->values()
                        ->all();

                    if ($year < 2000 || $month < 1 || $month > 12 || $employeeIds === []) {
                        Notification::make()
                            ->title('Select a month and at least one employee')
                            ->warning()
                            ->send();

                        return;
                    }

                    $binary = WorkTimeTimesheetPdfGenerator::generate($year, $month, $employeeIds);
                    $filename = sprintf('darbo-laiko-ziniarastis-%04d-%02d.pdf', $year, $month);

                    ActivityLogger::logReportDownloaded('Work time timesheet PDF', 'pdf', null, [
                        'year' => $year,
                        'month' => $month,
                        'employee_ids' => $employeeIds,
                        'employee_count' => count($employeeIds),
                        'file_name' => $filename,
                    ]);

                    return response()->streamDownload(function () use ($binary): void {
                        echo $binary;
                    }, $filename, ['Content-Type' => 'application/pdf']);
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function allEmployeeOptions(): array
    {
        return Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->fullName(),
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    protected static function allEmployeeIds(): array
    {
        return Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function mount(): void
    {
        $this->annualLeaveAsOf = Carbon::today()->toDateString();

        $statusOptions = [
            ...LeaveRequestStatus::options(),
            ...OvertimeRequestStatus::options(),
        ];

        if (filled($this->status) && ! array_key_exists($this->status, $statusOptions)) {
            $this->status = null;
        }

        if (filled($this->employeeId) && Employee::query()->whereKey($this->employeeId)->exists()) {
            return;
        }

        $this->employeeId = Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->value('id');
    }

    public function updatedEmployeeId(): void
    {
        $this->annualLeaveResult = null;
        $this->parentalLeaveResult = null;
        $this->calendarVersion++;
    }

    public function updatedStatus(): void
    {
        $this->calendarVersion++;
    }

    public function calculateAnnualLeave(): void
    {
        $this->annualLeaveResult = null;

        if (blank($this->employeeId)) {
            $this->annualLeaveResult = [
                'ok' => false,
                'message' => 'Select an employee first.',
                'as_of' => $this->annualLeaveAsOf,
                'contract_start' => null,
                'employment_days' => 0,
                'completed_years' => 0,
                'base_days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
                'seniority_extra_days' => 0,
                'days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
                'accrued_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'seniority_rule_label' => 'No seniority bonus yet.',
            ];

            return;
        }

        $asOf = filled($this->annualLeaveAsOf)
            ? $this->annualLeaveAsOf
            : Carbon::today()->toDateString();

        $this->annualLeaveAsOf = $asOf;

        $employee = Employee::query()->find($this->employeeId);

        if ($employee === null) {
            $this->annualLeaveResult = [
                'ok' => false,
                'message' => 'Employee not found.',
                'as_of' => $asOf,
                'contract_start' => null,
                'employment_days' => 0,
                'completed_years' => 0,
                'base_days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
                'seniority_extra_days' => 0,
                'days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
                'accrued_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'seniority_rule_label' => 'No seniority bonus yet.',
            ];

            return;
        }

        $this->annualLeaveResult = AnnualLeaveCalculator::calculate($employee, $asOf);
    }

    public function calculateParentalLeave(): void
    {
        $this->parentalLeaveResult = null;

        $asOf = filled($this->parentalLeaveAsOf)
            ? $this->parentalLeaveAsOf
            : Carbon::today()->toDateString();

        $this->parentalLeaveAsOf = $asOf;

        if (blank($this->employeeId)) {
            $this->parentalLeaveResult = [
                'ok' => false,
                'message' => 'Select an employee first.',
                'as_of' => $asOf,
                'children_under_12' => 0,
                'children_disabled_under_18' => 0,
                'eligible_children' => 0,
                'days_per_period' => 0.0,
                'period' => 'none',
                'period_label' => '—',
                'period_from' => null,
                'period_to' => null,
                'entitlement_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'rule_label' => 'No entitlement',
            ];

            return;
        }

        $employee = Employee::query()->with('children')->find($this->employeeId);

        if ($employee === null) {
            $this->parentalLeaveResult = [
                'ok' => false,
                'message' => 'Employee not found.',
                'as_of' => $asOf,
                'children_under_12' => 0,
                'children_disabled_under_18' => 0,
                'eligible_children' => 0,
                'days_per_period' => 0.0,
                'period' => 'none',
                'period_label' => '—',
                'period_from' => null,
                'period_to' => null,
                'entitlement_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'rule_label' => 'No entitlement',
            ];

            return;
        }

        $this->parentalLeaveResult = ParentalLeaveCalculator::calculate($employee, $asOf);
    }

    public function editWorkDayHoursAction(): Action
    {
        return Action::make('editWorkDayHours')
            ->modalHeading(function (array $arguments): string {
                $date = (string) ($arguments['date'] ?? '');

                return filled($date)
                    ? 'Edit hours — '.Carbon::parse($date)->format('l, Y-m-d')
                    : 'Edit hours';
            })
            ->modalDescription('Update planned and actual hours for this day.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $arguments): array {
                $entry = $this->findWorkDayEntry((string) ($arguments['date'] ?? ''));

                if ($entry === null) {
                    return [
                        'hours' => 0,
                        'actual_hours' => 0,
                        'is_not_working_day' => false,
                    ];
                }

                return [
                    'hours' => (float) $entry->hours,
                    'actual_hours' => (float) $entry->actual_hours,
                    'is_not_working_day' => (bool) $entry->is_not_working_day,
                ];
            })
            ->form([
                Checkbox::make('is_not_working_day')
                    ->label('Not working day')
                    ->live()
                    ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                        if ($state) {
                            $set('hours', 0);
                            $set('actual_hours', 0);

                            return;
                        }

                        if ((float) ($get('hours') ?? 0) !== 0.0) {
                            return;
                        }

                        $employee = Employee::query()->find($this->employeeId);
                        $hours = $employee !== null
                            ? WorkScheduleGenerator::defaultHoursFor($employee)
                            : WorkScheduleGenerator::BASE_HOURS_PER_DAY;

                        $set('hours', $hours);
                        $set('actual_hours', $hours);
                    }),

                TextInput::make('hours')
                    ->label('Plan hours')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.25)
                    ->suffix('h')
                    ->required()
                    ->disabled(fn (Get $get): bool => (bool) $get('is_not_working_day'))
                    ->dehydrated(),

                TextInput::make('actual_hours')
                    ->label('Actual hours')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.25)
                    ->suffix('h')
                    ->required()
                    ->disabled(fn (Get $get): bool => (bool) $get('is_not_working_day'))
                    ->dehydrated(),
            ])
            ->action(function (array $data, array $arguments): void {
                $date = (string) ($arguments['date'] ?? '');
                $entry = $this->findWorkDayEntry($date);

                if ($entry === null) {
                    Notification::make()
                        ->title('No work schedule for this day')
                        ->body('Create a work schedule for this employee and month first.')
                        ->warning()
                        ->send();

                    return;
                }

                $isOff = (bool) ($data['is_not_working_day'] ?? false);
                $hours = $isOff ? 0.0 : (float) ($data['hours'] ?? 0);
                $actualHours = $isOff ? 0.0 : (float) ($data['actual_hours'] ?? 0);

                $entry->forceFill([
                    'is_not_working_day' => $isOff,
                    'hours' => $hours,
                    'actual_hours' => $actualHours,
                ])->save();

                $this->calendarVersion++;

                Notification::make()
                    ->title('Hours updated')
                    ->body(Carbon::parse($date)->toDateString().': plan '.$hours.' h, actual '.$actualHours.' h')
                    ->success()
                    ->send();
            });
    }

    /**
     * Fallback entry point for Alpine / Livewire when opening the hours modal.
     */
    public function openWorkDayHoursEditor(string $date): void
    {
        $this->mountAction('editWorkDayHours', ['date' => $date]);
    }

    /**
     * Choose leave / business trip / overtime for a calendar day.
     */
    public function openAddDayRequest(string $date): void
    {
        if (blank($this->employeeId)) {
            Notification::make()
                ->title('Select an employee first')
                ->warning()
                ->send();

            return;
        }

        $this->mountAction('addDayRequest', ['date' => $date]);
    }

    public function addDayRequestAction(): Action
    {
        return Action::make('addDayRequest')
            ->modalHeading(function (array $arguments): string {
                $date = (string) ($arguments['date'] ?? '');

                return filled($date)
                    ? 'Add request — '.Carbon::parse($date)->format('l, Y-m-d')
                    : 'Add request';
            })
            ->modalDescription('Create a leave, business trip, or overtime request for this day.')
            ->modalSubmitActionLabel('Continue')
            ->fillForm(fn (): array => [
                'request_kind' => 'leave',
            ])
            ->form([
                Radio::make('request_kind')
                    ->label('Request type')
                    ->options([
                        'leave' => 'Absence / leave',
                        'business_trip' => 'Business trip',
                        'overtime' => 'Overtime',
                    ])
                    ->descriptions([
                        'leave' => 'Annual leave, sick leave, mamadienis, remote work, etc.',
                        'business_trip' => 'Komandiruotė with destination and allowances.',
                        'overtime' => 'Extra hours or work on a rest day.',
                    ])
                    ->required()
                    ->inline(false),
            ])
            ->action(function (array $data, array $arguments) {
                $date = (string) ($arguments['date'] ?? '');
                $employeeId = (int) ($this->employeeId ?? 0);
                $kind = (string) ($data['request_kind'] ?? 'leave');

                if ($employeeId <= 0 || blank($date)) {
                    Notification::make()
                        ->title('Missing employee or date')
                        ->warning()
                        ->send();

                    return;
                }

                $query = array_filter([
                    'employee_id' => $employeeId,
                    'date' => $kind === 'overtime' ? $date : null,
                    'date_from' => $kind === 'overtime' ? null : $date,
                    'date_to' => $kind === 'overtime' ? null : $date,
                ], fn ($value): bool => $value !== null && $value !== '');

                $url = match ($kind) {
                    'business_trip' => BusinessTripRequestResource::getUrl('create'),
                    'overtime' => OvertimeRequestResource::getUrl('create'),
                    default => LeaveRequestResource::getUrl('create'),
                };

                return redirect()->to($url.'?'.http_build_query($query));
            });
    }

    protected function findWorkDayEntry(string $date): ?WorkScheduleEntry
    {
        if (blank($this->employeeId) || blank($date)) {
            return null;
        }

        return WorkScheduleEntry::query()
            ->whereDate('work_date', $date)
            ->whereHas('workSchedule', fn ($query) => $query->where('employee_id', $this->employeeId))
            ->first();
    }

    /**
     * @return array<int, string>
     */
    public function getEmployeeOptions(): array
    {
        return Employee::query()
            ->orderBy('surname')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $employee->fullName(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return [
            '' => 'All statuses',
            ...LeaveRequestStatus::options(),
        ];
    }

    /**
     * @return array<string, array{hours: float, actual_hours: float, is_past: bool, is_not_working_day: bool, work_schedule_id: int|null, editable: bool}>
     */
    public function getWorkDays(): array
    {
        if (blank($this->employeeId)) {
            return [];
        }

        $today = now()->startOfDay();

        return WorkScheduleEntry::query()
            ->whereHas('workSchedule', fn ($query) => $query->where('employee_id', $this->employeeId))
            ->orderBy('work_date')
            ->get()
            ->mapWithKeys(function (WorkScheduleEntry $entry) use ($today): array {
                $date = $entry->work_date->toDateString();

                return [
                    $date => [
                        'hours' => (float) $entry->hours,
                        'actual_hours' => (float) $entry->actual_hours,
                        'is_past' => $entry->work_date->startOfDay()->lte($today),
                        'is_not_working_day' => (bool) $entry->is_not_working_day,
                        'work_schedule_id' => $entry->work_schedule_id,
                        'editable' => true,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLeaveEvents(): array
    {
        if (blank($this->employeeId)) {
            return [];
        }

        return LeaveRequest::query()
            ->with('leaveRequestType')
            ->where('employee_id', $this->employeeId)
            ->when(
                filled($this->status),
                fn ($query) => $query->where('status', $this->status),
            )
            ->orderBy('date_from')
            ->get()
            ->map(function (LeaveRequest $leave): array {
                $type = $leave->leaveRequestType;
                $color = $type?->color ?? '#6b7280';
                $statusLabel = $leave->status?->label() ?? '—';
                $typeName = $type?->name ?? 'Leave';

                return [
                    'title' => $typeName.' ('.$statusLabel.')',
                    'start' => $leave->date_from->toDateString(),
                    'end' => $leave->date_to->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'color' => $color,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'url' => LeaveRequestResource::getUrl('edit', ['record' => $leave]),
                    'extendedProps' => [
                        'comment' => Str::limit((string) ($leave->comment ?? ''), 300),
                        'type' => $typeName,
                        'status' => $statusLabel,
                        'kind' => 'leave',
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCalendarEvents(): array
    {
        return [
            ...$this->getLeaveEvents(),
            ...$this->getOvertimeEvents(),
            ...$this->getBusinessTripEvents(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getBusinessTripEvents(): array
    {
        if (blank($this->employeeId)) {
            return [];
        }

        $tripStatuses = collect(BusinessTripRequestStatus::cases())
            ->map(fn (BusinessTripRequestStatus $status): string => $status->value)
            ->all();

        return BusinessTripRequest::query()
            ->where('employee_id', $this->employeeId)
            ->when(
                filled($this->status) && in_array((string) $this->status, $tripStatuses, true),
                fn ($query) => $query->where('status', $this->status),
            )
            ->when(
                filled($this->status) && ! in_array((string) $this->status, $tripStatuses, true),
                fn ($query) => $query->whereRaw('0 = 1'),
            )
            ->orderBy('date_from')
            ->get()
            ->map(function (BusinessTripRequest $trip): array {
                $color = '#d97706';
                $statusLabel = $trip->status?->label() ?? '—';
                $country = trim((string) ($trip->country_name ?? ''));
                $countryLabel = $country !== '' ? $country : 'Business trip';
                $purpose = trim((string) ($trip->purpose ?? ''));

                return [
                    'title' => 'Trip: '.$countryLabel.' ('.$statusLabel.')',
                    'start' => $trip->date_from?->toDateString(),
                    'end' => $trip->date_to?->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'color' => $color,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'url' => BusinessTripRequestResource::getUrl('edit', ['record' => $trip]),
                    'extendedProps' => [
                        'comment' => Str::limit(implode(' · ', array_filter([
                            $purpose !== '' ? $purpose : null,
                            filled($trip->comment) ? (string) $trip->comment : null,
                        ])), 300),
                        'type' => 'Business trip',
                        'status' => $statusLabel,
                        'kind' => 'business_trip',
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOvertimeEvents(): array
    {
        if (blank($this->employeeId)) {
            return [];
        }

        return OvertimeRequest::query()
            ->with('overtimeRequestType')
            ->where('employee_id', $this->employeeId)
            ->when(
                filled($this->status),
                fn ($query) => $query->where('status', $this->status),
            )
            ->orderBy('date')
            ->get()
            ->map(function (OvertimeRequest $overtime): array {
                $type = $overtime->overtimeRequestType;
                $color = $type?->color ?? '#0ea5e9';
                $statusLabel = $overtime->status?->label() ?? '—';
                $typeName = $type?->name ?? 'Overtime';
                $hoursLabel = number_format((float) ($overtime->hours ?? 0), 2).'h';
                $day = $overtime->date?->toDateString();

                return [
                    'title' => 'OT: '.$typeName.' · '.$hoursLabel.' ('.$statusLabel.')',
                    'start' => $day,
                    'end' => $overtime->date?->copy()->addDay()->toDateString(),
                    'allDay' => true,
                    'color' => $color,
                    'backgroundColor' => $color,
                    'borderColor' => $color,
                    'url' => OvertimeRequestResource::getUrl('edit', ['record' => $overtime]),
                    'extendedProps' => [
                        'comment' => Str::limit((string) ($overtime->comment ?? ''), 300),
                        'type' => $typeName,
                        'status' => $statusLabel,
                        'hours' => $hoursLabel,
                        'kind' => 'overtime',
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{name: string, color: string}>
     */
    public function getLeaveTypeLegend(): array
    {
        $leave = LeaveRequestType::query()
            ->orderBy('name')
            ->get(['name', 'color'])
            ->map(fn (LeaveRequestType $type): array => [
                'name' => $type->name,
                'color' => $type->color ?? '#6b7280',
            ]);

        $overtime = OvertimeRequestType::query()
            ->orderBy('name')
            ->get(['name', 'color'])
            ->map(fn (OvertimeRequestType $type): array => [
                'name' => 'OT: '.$type->name,
                'color' => $type->color ?? '#0ea5e9',
            ]);

        $trips = collect([
            [
                'name' => 'Business trip',
                'color' => '#d97706',
            ],
        ]);

        return $leave->concat($overtime)->concat($trips)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function getCalendarPayload(): array
    {
        return [
            'workDays' => $this->getWorkDays(),
            'leaveEvents' => $this->getCalendarEvents(),
            'legend' => $this->getLeaveTypeLegend(),
            'today' => Carbon::today()->toDateString(),
        ];
    }
}
