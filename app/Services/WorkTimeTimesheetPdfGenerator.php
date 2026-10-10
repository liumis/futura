<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Enums\OvertimeRequestStatus;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LtHoliday;
use App\Models\OvertimeRequest;
use App\Models\WorkSchedule;
use App\Support\TimesheetCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class WorkTimeTimesheetPdfGenerator
{
    /**
     * Official timesheet absence / day codes shown in the legend.
     *
     * @return list<string>
     */
    public static function legendCodes(): array
    {
        return TimesheetCode::legendCodes();
    }

    /**
     * @deprecated Use legendCodes() / TimesheetCode::legendCodes()
     *
     * @var list<string>
     */
    public const LEGEND_CODES = [
        'V', 'M', 'D', 'L', 'N', 'NS', 'A', 'MA', 'NA', 'KA', 'G', 'ID', 'PV',
        'MD', 'K', 'KV', 'VV', 'KT', 'KM', 'PK', 'PN', 'PB', 'NP', 'KR', 'NN',
        'ST', 'KK', 'P', 'S', 'TA',
    ];

    /**
     * @var array<int, string>
     */
    public const MONTH_NAMES_LT = [
        1 => 'sausis',
        2 => 'vasaris',
        3 => 'kovas',
        4 => 'balandis',
        5 => 'gegužė',
        6 => 'birželis',
        7 => 'liepa',
        8 => 'rugpjūtis',
        9 => 'rugsėjis',
        10 => 'spalis',
        11 => 'lapkritis',
        12 => 'gruodis',
    ];

    /**
     * @param  list<int>  $employeeIds
     */
    public static function generate(int $year, int $month, array $employeeIds): string
    {
        $payload = self::build($year, $month, $employeeIds);

        return Pdf::loadView('pdf.work-time-timesheet', $payload)
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month, array $employeeIds): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $daysInMonth = $start->daysInMonth;
        $holidayDates = LtHoliday::datesBetween($start, $end);
        $company = CompanySetting::instance();

        $employees = Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('surname')
            ->orderBy('name')
            ->get();

        $schedules = WorkSchedule::query()
            ->with('entries')
            ->whereIn('employee_id', $employees->modelKeys())
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('employee_id');

        $leaves = LeaveRequest::query()
            ->with('leaveRequestType')
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('status', [
                LeaveRequestStatus::Confirmed->value,
                LeaveRequestStatus::CancellationPending->value,
            ])
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get()
            ->groupBy('employee_id');

        $overtimes = OvertimeRequest::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereIn('status', [
                OvertimeRequestStatus::Confirmed->value,
                OvertimeRequestStatus::CancellationPending->value,
            ])
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get()
            ->groupBy('employee_id');

        $legendTotals = collect(TimesheetCode::legendCodes())
            ->mapWithKeys(fn (string $code): array => [$code => ['days' => 0, 'hours' => 0.0]])
            ->all();

        $rows = [];
        $rowNumber = 1;

        foreach ($employees as $employee) {
            $row = self::buildEmployeeRow(
                $employee,
                $schedules->get($employee->getKey()),
                $leaves->get($employee->getKey(), collect()),
                $overtimes->get($employee->getKey(), collect()),
                $start,
                $daysInMonth,
                $holidayDates,
            );

            $row['row_number'] = $rowNumber++;
            $rows[] = $row;

            foreach ($row['legend'] as $code => $totals) {
                if (! isset($legendTotals[$code])) {
                    $legendTotals[$code] = ['days' => 0, 'hours' => 0.0];
                }

                $legendTotals[$code]['days'] += $totals['days'];
                $legendTotals[$code]['hours'] += $totals['hours'];
            }
        }

        $days = [];
        for ($day = 1; $day <= 31; $day++) {
            $date = $day <= $daysInMonth ? $start->copy()->day($day) : null;
            $dateKey = $date?->toDateString();

            $days[] = [
                'number' => $day,
                'in_month' => $date !== null,
                'is_weekend' => $date?->isWeekend() ?? false,
                'is_holiday' => $dateKey !== null && array_key_exists($dateKey, $holidayDates),
            ];
        }

        return [
            'companyName' => filled($company->company_name) ? (string) $company->company_name : 'UAB',
            'companyId' => filled($company->company_id) ? (string) $company->company_id : '',
            'companyAddress' => filled($company->company_address) ? (string) $company->company_address : '',
            'companyEmail' => filled($company->company_email) ? (string) $company->company_email : '',
            'companyPhone' => filled($company->company_phone) ? (string) $company->company_phone : '',
            'logoDataUri' => self::logoDataUri(),
            'year' => $year,
            'monthName' => self::MONTH_NAMES_LT[$month] ?? $start->translatedFormat('F'),
            'daysInMonth' => $daysInMonth,
            'days' => $days,
            'rows' => $rows,
            'legendCodes' => TimesheetCode::legendCodes(),
            'legendTotals' => $legendTotals,
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaves
     * @param  Collection<int, OvertimeRequest>  $overtimes
     * @param  array<string, string>  $holidayDates
     * @return array<string, mixed>
     */
    protected static function buildEmployeeRow(
        Employee $employee,
        ?WorkSchedule $schedule,
        Collection $leaves,
        Collection $overtimes,
        Carbon $monthStart,
        int $daysInMonth,
        array $holidayDates,
    ): array {
        $generated = WorkScheduleGenerator::forEmployeeMonth($employee, $monthStart->year, $monthStart->month);
        $generatedByDate = collect($generated)->keyBy('work_date');
        $entriesByDate = $schedule?->entries->keyBy(fn ($entry) => $entry->work_date->toDateString()) ?? collect();
        $overtimeByDate = $overtimes->groupBy(fn (OvertimeRequest $overtime): string => $overtime->date->toDateString());
        $defaultHours = WorkScheduleGenerator::defaultHoursFor($employee);

        $cells = [];
        $legend = [];
        $workedDays = 0;
        $workedHours = 0.0;
        $overtimeHours = 0.0;
        $restDayHours = 0.0;
        $holidayHours = 0.0;
        $absenceDays = 0;
        $absenceHours = 0.0;
        $plannedHours = 0.0;

        for ($day = 1; $day <= 31; $day++) {
            if ($day > $daysInMonth) {
                $cells[] = ['text' => '', 'kind' => 'empty'];

                continue;
            }

            $date = $monthStart->copy()->day($day);
            $dateKey = $date->toDateString();
            $entry = $entriesByDate->get($dateKey);
            $generatedEntry = $generatedByDate->get($dateKey);
            $isHoliday = array_key_exists($dateKey, $holidayDates);
            $isWeekend = $date->isWeekend();
            $isNotWorkingDay = $entry !== null
                ? (bool) $entry->is_not_working_day
                : (bool) ($generatedEntry['is_not_working_day'] ?? ($isWeekend || $isHoliday));
            $planHours = $entry !== null
                ? (float) $entry->hours
                : (float) ($generatedEntry['hours'] ?? 0);
            $actualHours = $entry !== null
                ? (float) $entry->actual_hours
                : (float) ($generatedEntry['actual_hours'] ?? $planHours);
            $dayOvertime = (float) ($overtimeByDate->get($dateKey)?->sum('hours') ?? 0);
            $leaveCode = self::leaveCodeForDate($leaves, $date);

            $plannedHours += $planHours;
            $overtimeHours += $dayOvertime;

            $cellHours = $actualHours > 0 ? $actualHours : $planHours;

            if ($isHoliday && $actualHours <= 0 && $dayOvertime <= 0) {
                $cells[] = ['text' => 'S', 'kind' => 'holiday'];
                self::addLegend($legend, 'S', 0);

                continue;
            }

            if (($isWeekend || ($isNotWorkingDay && $leaveCode === null)) && $actualHours <= 0 && $dayOvertime <= 0) {
                $cells[] = ['text' => 'P', 'kind' => 'rest'];
                self::addLegend($legend, 'P', 0);

                continue;
            }

            if ($leaveCode !== null && $leaveCode !== 'K') {
                $hoursForCode = $planHours > 0 ? $planHours : $defaultHours;
                $cells[] = ['text' => $leaveCode, 'kind' => 'code'];
                self::addLegend($legend, $leaveCode, $hoursForCode);
                $absenceDays++;
                $absenceHours += $hoursForCode;

                continue;
            }

            if ($leaveCode === 'K') {
                $hoursForCode = $cellHours > 0 ? $cellHours : ($planHours > 0 ? $planHours : $defaultHours);
                $cells[] = ['text' => self::formatHours($hoursForCode), 'kind' => 'hours'];
                self::addLegend($legend, 'K', $hoursForCode);
                $workedDays++;
                $workedHours += $hoursForCode;

                continue;
            }

            $displayHours = $actualHours > 0 ? $actualHours : ($dayOvertime > 0 ? $dayOvertime : $planHours);
            $cells[] = ['text' => self::formatHours($displayHours), 'kind' => 'hours'];
            $workedDays++;
            $workedHours += $displayHours;

            if ($isHoliday) {
                $holidayHours += $displayHours;
            } elseif ($isWeekend) {
                $restDayHours += $displayHours;
            }
        }

        return [
            'name' => trim($employee->name ?? ''),
            'surname' => trim($employee->surname ?? ''),
            'position' => filled($employee->position) ? (string) $employee->position : '',
            'table_number' => (string) $employee->getKey(),
            'schedule_number' => '',
            'planned_hours' => self::formatHours($plannedHours),
            'cells' => $cells,
            'worked_days' => $workedDays,
            'worked_hours' => self::formatHours($workedHours),
            'night_hours' => '',
            'overtime_hours' => $overtimeHours > 0 ? self::formatHours($overtimeHours) : '',
            'on_call_hours' => '',
            'rest_day_hours' => $restDayHours > 0 ? self::formatHours($restDayHours) : '',
            'holiday_work_hours' => $holidayHours > 0 ? self::formatHours($holidayHours) : '',
            'absence_days' => $absenceDays > 0 ? (string) $absenceDays : '',
            'absence_hours' => $absenceHours > 0 ? self::formatHours($absenceHours) : '',
            'legend' => $legend,
        ];
    }

    /**
     * @param  Collection<int, LeaveRequest>  $leaves
     */
    protected static function leaveCodeForDate(Collection $leaves, Carbon $date): ?string
    {
        foreach ($leaves as $leave) {
            if ($date->lt($leave->date_from->copy()->startOfDay()) || $date->gt($leave->date_to->copy()->startOfDay())) {
                continue;
            }

            $type = $leave->leaveRequestType;
            $code = TimesheetCode::normalize($type?->timesheet_code);

            if ($code !== null) {
                return $code;
            }

            // Remote / non-absence types intentionally have no timesheet code.
            if ($type !== null && blank($type->timesheet_code)) {
                return null;
            }

            return self::codeForLeaveType((string) ($type?->name ?? ''));
        }

        return null;
    }

    /**
     * Fallback when timesheet_code is missing on legacy rows.
     */
    public static function codeForLeaveType(string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        return match (true) {
            str_contains($normalized, 'kasmetin') => 'A',
            str_contains($normalized, 'nedarbing') => 'L',
            str_contains($normalized, 'neapmokam') || str_contains($normalized, 'nemokam') => 'NA',
            str_contains($normalized, 'tėvadien') || str_contains($normalized, 'mamadien') => 'M',
            str_contains($normalized, 'tėvyst') => 'TA',
            str_contains($normalized, 'komandiruot') => 'K',
            default => 'KR',
        };
    }

    /**
     * @param  array<string, array{days: int, hours: float}>  $legend
     */
    protected static function addLegend(array &$legend, string $code, float $hours): void
    {
        if (! isset($legend[$code])) {
            $legend[$code] = ['days' => 0, 'hours' => 0.0];
        }

        $legend[$code]['days']++;
        $legend[$code]['hours'] += $hours;
    }

    public static function formatHours(float $hours): string
    {
        if (abs($hours - round($hours)) < 0.001) {
            return (string) (int) round($hours);
        }

        return rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
    }

    private static function logoDataUri(): ?string
    {
        $pngPath = public_path('images/logo-invoice.png');

        if (is_readable($pngPath)) {
            $contents = file_get_contents($pngPath);
            if ($contents !== false) {
                return 'data:image/png;base64,'.base64_encode($contents);
            }
        }

        $svgPath = public_path('images/logo.svg');

        if (! is_readable($svgPath)) {
            return null;
        }

        $contents = file_get_contents($svgPath);

        return $contents !== false
            ? 'data:image/svg+xml;base64,'.base64_encode($contents)
            : null;
    }

    /**
     * @return array<string, string>
     */
    public static function monthSelectOptions(): array
    {
        $options = [];
        // Newest first: current month and the previous 23 (no future months).
        $cursor = now()->startOfMonth();

        for ($i = 0; $i < 24; $i++) {
            $key = $cursor->format('Y-m');
            $lt = self::MONTH_NAMES_LT[(int) $cursor->month] ?? $cursor->format('F');
            $options[$key] = $cursor->format('Y').' '.$lt;
            $cursor->subMonth();
        }

        return $options;
    }
}
