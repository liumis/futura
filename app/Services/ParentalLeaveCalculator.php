<?php

namespace App\Services;

use App\Enums\LeaveRequestStatus;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lithuanian DK 138(3) — mamadieniai / tėvadieniai.
 *
 * @see https://vdi.lrv.lt/lt/naujienos/aktualu-auginantiems-vaikus-mamadieniu-ir-tevadieniu-suteikimo-tvarka/
 */
final class ParentalLeaveCalculator
{
    public const TYPE_NAME = 'Tėvadienis / Mamadienis';

    public const AGE_LIMIT_YEARS = 12;

    public const DISABILITY_AGE_LIMIT_YEARS = 18;

    /**
     * @return array{
     *     ok: bool,
     *     message: ?string,
     *     as_of: string,
     *     children_under_12: int,
     *     children_disabled_under_18: int,
     *     eligible_children: int,
     *     days_per_period: float,
     *     period: 'none'|'month'|'quarter',
     *     period_label: string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     entitlement_days: float,
     *     used_days: float,
     *     available_days: float,
     *     rule_label: string,
     * }
     */
    public static function calculate(Employee $employee, Carbon|string $asOf, ?int $ignoreLeaveId = null): array
    {
        $asOfDate = ($asOf instanceof Carbon ? $asOf->copy() : Carbon::parse((string) $asOf))->startOfDay();
        $employee->loadMissing('children');

        $under12 = self::childrenUnderAge($employee->children, $asOfDate, self::AGE_LIMIT_YEARS);
        $disabledUnder18 = self::disabledChildrenUnderAge(
            $employee->children,
            $asOfDate,
            self::DISABILITY_AGE_LIMIT_YEARS,
        );
        $under12Disabled = $under12->filter(fn (EmployeeChild $child): bool => (bool) $child->has_disability);

        $under12Count = $under12->count();
        $disabledCount = $disabledUnder18->count();
        $eligibleCount = $under12->merge($disabledUnder18)->unique('id')->count();

        if ($eligibleCount === 0) {
            return self::emptyResult(
                asOf: $asOfDate,
                message: 'No children under 12 (or disabled under 18). Add birthdates under Employee → Children.',
                under12: 0,
                disabled: 0,
                eligible: 0,
            );
        }

        [$period, $daysPerPeriod, $ruleLabel] = self::resolveEntitlement(
            under12Count: $under12Count,
            disabledUnder18Count: $disabledCount,
            under12DisabledCount: $under12Disabled->count(),
        );

        [$periodFrom, $periodTo, $periodLabel] = self::periodBounds($asOfDate, $period);
        $used = self::usedWorkingDays($employee, $periodFrom, $periodTo, $ignoreLeaveId);
        $available = max(0, round($daysPerPeriod - $used, 2));

        return [
            'ok' => true,
            'message' => null,
            'as_of' => $asOfDate->toDateString(),
            'children_under_12' => $under12Count,
            'children_disabled_under_18' => $disabledCount,
            'eligible_children' => $eligibleCount,
            'days_per_period' => (float) $daysPerPeriod,
            'period' => $period,
            'period_label' => $periodLabel,
            'period_from' => $periodFrom->toDateString(),
            'period_to' => $periodTo->toDateString(),
            'entitlement_days' => (float) $daysPerPeriod,
            'used_days' => $used,
            'available_days' => $available,
            'rule_label' => $ruleLabel,
        ];
    }

    /**
     * Validate a requested leave range against DK 138(3) entitlement.
     *
     * @return array{ok: bool, message: string, requested_days: float, available_days: float}
     */
    public static function validateRequest(
        Employee $employee,
        Carbon|string $dateFrom,
        Carbon|string $dateTo,
        ?int $ignoreLeaveId = null,
    ): array {
        $from = ($dateFrom instanceof Carbon ? $dateFrom->copy() : Carbon::parse((string) $dateFrom))->startOfDay();
        $to = ($dateTo instanceof Carbon ? $dateTo->copy() : Carbon::parse((string) $dateTo))->startOfDay();

        if ($to->lt($from)) {
            return [
                'ok' => false,
                'message' => 'Date to must be on or after date from.',
                'requested_days' => 0.0,
                'available_days' => 0.0,
            ];
        }

        $balance = self::calculate($employee, $from, $ignoreLeaveId);

        if (! $balance['ok']) {
            return [
                'ok' => false,
                'message' => (string) ($balance['message'] ?? 'Not entitled to parental rest days.'),
                'requested_days' => 0.0,
                'available_days' => 0.0,
            ];
        }

        $periodFrom = Carbon::parse((string) $balance['period_from'])->startOfDay();
        $periodTo = Carbon::parse((string) $balance['period_to'])->startOfDay();

        if ($from->lt($periodFrom) || $to->gt($periodTo)) {
            return [
                'ok' => false,
                'message' => 'Tėvadienis / Mamadienis must fall within one entitlement period ('
                    .$balance['period_label'].': '.$balance['period_from'].' – '.$balance['period_to'].').',
                'requested_days' => self::countWeekdays($from, $to),
                'available_days' => (float) $balance['available_days'],
            ];
        }

        $requested = self::countWeekdays($from, $to);

        if ($requested <= 0) {
            return [
                'ok' => false,
                'message' => 'Selected dates contain no working days.',
                'requested_days' => 0.0,
                'available_days' => (float) $balance['available_days'],
            ];
        }

        if ($requested > (float) $balance['available_days'] + 0.001) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Only %.0f day(s) available in this %s (%s – %s). Requested %.0f working day(s). Rule: %s',
                    $balance['available_days'],
                    $balance['period'],
                    $balance['period_from'],
                    $balance['period_to'],
                    $requested,
                    $balance['rule_label'],
                ),
                'requested_days' => $requested,
                'available_days' => (float) $balance['available_days'],
            ];
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'OK — %.0f of %.0f day(s) remaining in this %s.',
                $balance['available_days'] - $requested,
                $balance['entitlement_days'],
                $balance['period'],
            ),
            'requested_days' => $requested,
            'available_days' => (float) $balance['available_days'],
        ];
    }

    public static function isParentalType(LeaveRequestType|string|null $type): bool
    {
        if ($type instanceof LeaveRequestType) {
            return $type->name === self::TYPE_NAME;
        }

        return is_string($type) && $type === self::TYPE_NAME;
    }

    /**
     * @param  Collection<int, EmployeeChild>  $children
     * @return Collection<int, EmployeeChild>
     */
    public static function childrenUnderAge(Collection $children, Carbon $asOf, int $years): Collection
    {
        return $children
            ->filter(function (EmployeeChild $child) use ($asOf, $years): bool {
                if ($child->birthdate === null) {
                    return false;
                }

                // Inclusive of the birthday when the age limit is reached (VDI).
                return $asOf->lte($child->birthdate->copy()->startOfDay()->addYears($years));
            })
            ->values();
    }

    /**
     * @param  Collection<int, EmployeeChild>  $children
     * @return Collection<int, EmployeeChild>
     */
    public static function disabledChildrenUnderAge(Collection $children, Carbon $asOf, int $years): Collection
    {
        return self::childrenUnderAge(
            $children->filter(fn (EmployeeChild $child): bool => (bool) $child->has_disability)->values(),
            $asOf,
            $years,
        );
    }

    /**
     * @return array{0: 'month'|'quarter', 1: int, 2: string}
     */
    protected static function resolveEntitlement(
        int $under12Count,
        int $disabledUnder18Count,
        int $under12DisabledCount,
    ): array {
        if ($under12Count >= 3 || ($under12Count >= 2 && $under12DisabledCount >= 1)) {
            return [
                'month',
                2,
                '3+ children under 12, or 2 under 12 with disability — 2 days per month',
            ];
        }

        if ($disabledUnder18Count >= 1 || $under12Count >= 2) {
            return [
                'month',
                1,
                $disabledUnder18Count >= 1 && $under12Count < 2
                    ? 'Disabled child under 18 — 1 day per month'
                    : '2 children under 12 — 1 day per month',
            ];
        }

        return [
            'quarter',
            1,
            '1 child under 12 — 1 day per 3 months',
        ];
    }

    /**
     * @param  'month'|'quarter'  $period
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected static function periodBounds(Carbon $asOf, string $period): array
    {
        if ($period === 'quarter') {
            $from = $asOf->copy()->firstOfQuarter()->startOfDay();
            $to = $asOf->copy()->lastOfQuarter()->startOfDay();
            $label = 'Q'.$asOf->quarter.' '.$asOf->year;

            return [$from, $to, $label];
        }

        $from = $asOf->copy()->startOfMonth()->startOfDay();
        $to = $asOf->copy()->endOfMonth()->startOfDay();
        $label = $asOf->format('Y-m');

        return [$from, $to, $label];
    }

    protected static function usedWorkingDays(
        Employee $employee,
        Carbon $from,
        Carbon $to,
        ?int $ignoreLeaveId = null,
    ): float {
        $query = LeaveRequest::query()
            ->where('employee_id', $employee->getKey())
            ->whereIn('status', [
                LeaveRequestStatus::Confirmed->value,
                LeaveRequestStatus::CancellationPending->value,
                LeaveRequestStatus::New->value,
            ])
            ->whereHas('leaveRequestType', function ($q): void {
                $q->where('name', self::TYPE_NAME);
            })
            ->whereDate('date_from', '<=', $to->toDateString())
            ->whereDate('date_to', '>=', $from->toDateString());

        if ($ignoreLeaveId !== null) {
            $query->whereKeyNot($ignoreLeaveId);
        }

        $used = 0.0;

        foreach ($query->get(['date_from', 'date_to']) as $leave) {
            $start = $leave->date_from->copy()->startOfDay()->max($from);
            $end = $leave->date_to->copy()->startOfDay()->min($to);

            if ($end->lt($start)) {
                continue;
            }

            $used += self::countWeekdays($start, $end);
        }

        return $used;
    }

    protected static function countWeekdays(Carbon $from, Carbon $to): float
    {
        $days = 0;

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if ($day->isWeekday()) {
                $days++;
            }
        }

        return (float) $days;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function emptyResult(
        Carbon $asOf,
        string $message,
        int $under12,
        int $disabled,
        int $eligible,
    ): array {
        return [
            'ok' => false,
            'message' => $message,
            'as_of' => $asOf->toDateString(),
            'children_under_12' => $under12,
            'children_disabled_under_18' => $disabled,
            'eligible_children' => $eligible,
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
    }
}
