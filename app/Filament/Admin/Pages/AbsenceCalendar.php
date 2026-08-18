<?php

namespace App\Filament\Admin\Pages;

use App\Enums\LeaveRequestStatus;
use App\Enums\OvertimeRequestStatus;
use App\Filament\Admin\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Admin\Resources\OvertimeRequests\OvertimeRequestResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRequestType;
use App\Models\WorkScheduleEntry;
use App\Services\AnnualLeaveCalculator;
use App\Services\WorkScheduleGenerator;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
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

    public int $calendarVersion = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
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
                'accrued_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
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
                'accrued_days' => 0.0,
                'used_days' => 0.0,
                'available_days' => 0.0,
                'days_per_year' => AnnualLeaveCalculator::DAYS_PER_YEAR,
            ];

            return;
        }

        $this->annualLeaveResult = AnnualLeaveCalculator::calculate($employee, $asOf);
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
        ];
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

        return $leave->concat($overtime)->values()->all();
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
