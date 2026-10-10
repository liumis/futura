<?php

namespace App\Services;

use App\Enums\BusinessTripPaymentStatus;
use App\Enums\BusinessTripRequestStatus;
use App\Models\BusinessTripRequest;
use App\Models\EmployeeContract;
use App\Models\PayrollTaxSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BusinessTripAllowanceTaxCalculator
{
    /** MMA / MVA multiplier for the full tax-free dienpinigiai threshold. */
    public const SALARY_THRESHOLD_MULTIPLIER = 1.65;

    /** Low-salary monthly tax-free cap as a share of contractual DU. */
    public const FREE_CAP_OF_SALARY = 0.5;

    /**
     * @return array{
     *     tax_year: int,
     *     tax_month: int,
     *     salary_snapshot: float,
     *     salary_threshold_snapshot: float,
     *     is_low_salary_case: bool,
     *     month_free_cap_snapshot: float,
     *     other_month_gross: float,
     *     remaining_free_cap: float,
     *     non_taxable_amount: float,
     *     taxable_amount: float,
     *     gpm_amount: float,
     *     payable_amount: float
     * }
     */
    public static function calculate(
        float $grossAmount,
        float $monthlySalary,
        PayrollTaxSetting $settings,
        CarbonInterface|string $taxMonth,
        float $otherMonthGross = 0.0,
        ?float $hourlyRate = null,
    ): array {
        $month = Carbon::parse($taxMonth)->startOfMonth();
        $grossAmount = round(max(0, $grossAmount), 2);
        $monthlySalary = round(max(0, $monthlySalary), 2);
        $otherMonthGross = round(max(0, $otherMonthGross), 2);

        $mmaThreshold = round((float) $settings->mma * self::SALARY_THRESHOLD_MULTIPLIER, 2);
        $mva = $settings->mva !== null ? (float) $settings->mva : null;
        $mvaThreshold = $mva !== null
            ? round($mva * self::SALARY_THRESHOLD_MULTIPLIER, 4)
            : null;

        $meetsHourlyThreshold = $hourlyRate !== null
            && $mvaThreshold !== null
            && $hourlyRate >= $mvaThreshold;

        $isLowSalaryCase = $monthlySalary < $mmaThreshold && ! $meetsHourlyThreshold;
        $monthFreeCap = $isLowSalaryCase
            ? round($monthlySalary * self::FREE_CAP_OF_SALARY, 2)
            : $grossAmount + $otherMonthGross;

        $remainingFree = $isLowSalaryCase
            ? round(max(0, $monthFreeCap - $otherMonthGross), 2)
            : $grossAmount;

        $nonTaxable = round(min($grossAmount, $remainingFree), 2);
        $taxable = round(max(0, $grossAmount - $nonTaxable), 2);
        $gpmRate = (float) ($settings->gpm_rate ?? 0.2);
        $gpm = round($taxable * $gpmRate, 2);
        $payable = round(max(0, $grossAmount - $gpm), 2);

        return [
            'tax_year' => (int) $month->year,
            'tax_month' => (int) $month->month,
            'salary_snapshot' => $monthlySalary,
            'salary_threshold_snapshot' => $mmaThreshold,
            'is_low_salary_case' => $isLowSalaryCase,
            'month_free_cap_snapshot' => $isLowSalaryCase ? $monthFreeCap : round($grossAmount + $otherMonthGross, 2),
            'other_month_gross' => $otherMonthGross,
            'remaining_free_cap' => $remainingFree,
            'non_taxable_amount' => $nonTaxable,
            'taxable_amount' => $taxable,
            'gpm_amount' => $gpm,
            'payable_amount' => $payable,
        ];
    }

    /**
     * Resolve contractual monthly salary (DU) for the employee in the given month.
     */
    public static function resolveMonthlySalary(int $employeeId, CarbonInterface|string $month): float
    {
        if ($employeeId <= 0) {
            return 0.0;
        }

        $salary = EmployeeContract::query()
            ->where('employee_id', $employeeId)
            ->validDuringMonth($month)
            ->orderByDesc('effective_date_from')
            ->orderByDesc('id')
            ->value('base_salary');

        return round((float) ($salary ?? 0), 2);
    }

    /**
     * Gross dienpinigiai already counting toward the monthly free cap for this employee.
     * Confirmed and paid trips in the tax month consume the cap (drafts do not).
     */
    public static function otherMonthGross(
        int $employeeId,
        CarbonInterface|string $taxMonth,
        ?int $excludeRequestId = null,
    ): float {
        if ($employeeId <= 0) {
            return 0.0;
        }

        $month = Carbon::parse($taxMonth)->startOfMonth();
        $year = (int) $month->year;
        $monthNumber = (int) $month->month;

        $query = BusinessTripRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', '!=', BusinessTripRequestStatus::Canceled->value)
            ->where(function ($builder) use ($year, $monthNumber): void {
                $builder
                    ->where(function ($paid) use ($year, $monthNumber): void {
                        $paid
                            ->whereIn('payment_status', [
                                BusinessTripPaymentStatus::Paid->value,
                                BusinessTripPaymentStatus::Partly->value,
                            ])
                            ->whereNotNull('paid_at')
                            ->whereYear('paid_at', $year)
                            ->whereMonth('paid_at', $monthNumber);
                    })
                    ->orWhere(function ($confirmed) use ($year, $monthNumber): void {
                        $confirmed
                            ->where('payment_status', BusinessTripPaymentStatus::Unpaid->value)
                            ->where('status', BusinessTripRequestStatus::Confirmed->value)
                            ->whereNotNull('confirmed_at')
                            ->whereYear('confirmed_at', $year)
                            ->whereMonth('confirmed_at', $monthNumber);
                    });
            });

        if ($excludeRequestId !== null) {
            $query->whereKeyNot($excludeRequestId);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Month used for the GPM monthly cap (payment month when known).
     */
    public static function resolveTaxMonth(
        ?CarbonInterface $paidAt = null,
        ?CarbonInterface $confirmedAt = null,
        CarbonInterface|string|null $dateTo = null,
        ?CarbonInterface $fallback = null,
    ): Carbon {
        if ($paidAt !== null) {
            return Carbon::parse($paidAt)->startOfMonth();
        }

        if ($confirmedAt !== null) {
            return Carbon::parse($confirmedAt)->startOfMonth();
        }

        if ($dateTo !== null) {
            return Carbon::parse($dateTo)->startOfMonth();
        }

        return Carbon::parse($fallback ?? now())->startOfMonth();
    }

    /**
     * Full tax breakdown for a trip request (or form state).
     *
     * @return array<string, float|int|bool>
     */
    public static function forRequest(
        float $grossAmount,
        int $employeeId,
        CarbonInterface|string $taxMonth,
        ?int $excludeRequestId = null,
        ?float $hourlyRate = null,
    ): array {
        $month = Carbon::parse($taxMonth)->startOfMonth();
        $settings = PayrollTaxSetting::forYear((int) $month->year);
        $salary = self::resolveMonthlySalary($employeeId, $month);
        $other = self::otherMonthGross($employeeId, $month, $excludeRequestId);

        return self::calculate(
            $grossAmount,
            $salary,
            $settings,
            $month,
            $other,
            $hourlyRate,
        );
    }

    /**
     * Apply tax fields onto a model instance (does not save).
     */
    public static function applyTo(BusinessTripRequest $request, ?CarbonInterface $taxMonth = null): void
    {
        $month = $taxMonth ?? self::resolveTaxMonth(
            $request->paid_at,
            $request->confirmed_at,
            $request->date_to,
        );

        $result = self::forRequest(
            (float) $request->amount,
            (int) $request->employee_id,
            $month,
            $request->exists ? (int) $request->getKey() : null,
        );

        $request->tax_year = $result['tax_year'];
        $request->tax_month = $result['tax_month'];
        $request->salary_snapshot = $result['salary_snapshot'];
        $request->salary_threshold_snapshot = $result['salary_threshold_snapshot'];
        $request->is_low_salary_case = $result['is_low_salary_case'];
        $request->month_free_cap_snapshot = $result['month_free_cap_snapshot'];
        $request->non_taxable_amount = $result['non_taxable_amount'];
        $request->taxable_amount = $result['taxable_amount'];
        $request->gpm_amount = $result['gpm_amount'];
        $request->payable_amount = $result['payable_amount'];
    }

    /**
     * Recalculate tax for a SEPA payment batch (FIFO by confirmed_at).
     * Uses payment month and shares the free cap across the batch correctly.
     *
     * @param  \Illuminate\Support\Collection<int, BusinessTripRequest>  $requests
     * @return \Illuminate\Support\Collection<int, BusinessTripRequest>
     */
    public static function applyPaymentBatchTax(Collection $requests, CarbonInterface|string $paidAt): Collection
    {
        $paidAt = Carbon::parse($paidAt);
        $month = $paidAt->copy()->startOfMonth();

        $sorted = $requests
            ->sortBy(fn (BusinessTripRequest $row): string => sprintf(
                '%s-%020d',
                optional($row->confirmed_at)?->format('Y-m-d H:i:s') ?? '9999',
                (int) $row->getKey(),
            ))
            ->values();

        $batchIds = $sorted->modelKeys();
        $runningExtra = [];

        foreach ($sorted as $request) {
            $employeeId = (int) $request->employee_id;

            $other = self::otherMonthGross($employeeId, $month, (int) $request->getKey());

            $siblingConfirmedGross = round((float) BusinessTripRequest::query()
                ->where('employee_id', $employeeId)
                ->whereIn('id', $batchIds)
                ->whereKeyNot($request->getKey())
                ->where('status', BusinessTripRequestStatus::Confirmed->value)
                ->where('payment_status', BusinessTripPaymentStatus::Unpaid->value)
                ->whereYear('confirmed_at', $month->year)
                ->whereMonth('confirmed_at', $month->month)
                ->sum('amount'), 2);

            $other = round(max(0, $other - $siblingConfirmedGross) + ($runningExtra[$employeeId] ?? 0), 2);

            $settings = PayrollTaxSetting::forYear((int) $month->year);
            $salary = self::resolveMonthlySalary($employeeId, $month);
            $tax = self::calculate(
                (float) $request->amount,
                $salary,
                $settings,
                $month,
                $other,
            );

            $request->forceFill([
                'tax_year' => $tax['tax_year'],
                'tax_month' => $tax['tax_month'],
                'salary_snapshot' => $tax['salary_snapshot'],
                'salary_threshold_snapshot' => $tax['salary_threshold_snapshot'],
                'is_low_salary_case' => $tax['is_low_salary_case'],
                'month_free_cap_snapshot' => $tax['month_free_cap_snapshot'],
                'non_taxable_amount' => $tax['non_taxable_amount'],
                'taxable_amount' => $tax['taxable_amount'],
                'gpm_amount' => $tax['gpm_amount'],
                'payable_amount' => $tax['payable_amount'],
            ])->save();

            $runningExtra[$employeeId] = round(($runningExtra[$employeeId] ?? 0) + (float) $request->amount, 2);
        }

        return $sorted->map(fn (BusinessTripRequest $row): BusinessTripRequest => $row->fresh())->values();
    }

    /**
     * Recalculate tax for all confirmed unpaid trips sharing an employee's tax month
     * (FIFO by confirmed_at so free cap is consumed in confirmation order).
     */
    public static function recalculateMonthForEmployee(int $employeeId, CarbonInterface|string $taxMonth): void
    {
        $month = Carbon::parse($taxMonth)->startOfMonth();
        $year = (int) $month->year;
        $monthNumber = (int) $month->month;

        $rows = BusinessTripRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', BusinessTripRequestStatus::Confirmed->value)
            ->where('payment_status', BusinessTripPaymentStatus::Unpaid->value)
            ->whereYear('confirmed_at', $year)
            ->whereMonth('confirmed_at', $monthNumber)
            ->orderBy('confirmed_at')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($rows, $month, $year, $monthNumber): void {
            $employeeId = (int) $rows->first()->employee_id;

            $runningOther = round((float) BusinessTripRequest::query()
                ->where('employee_id', $employeeId)
                ->whereIn('payment_status', [
                    BusinessTripPaymentStatus::Paid->value,
                    BusinessTripPaymentStatus::Partly->value,
                ])
                ->whereNotNull('paid_at')
                ->whereYear('paid_at', $year)
                ->whereMonth('paid_at', $monthNumber)
                ->sum('amount'), 2);

            $settings = PayrollTaxSetting::forYear((int) $month->year);
            $salary = self::resolveMonthlySalary($employeeId, $month);

            foreach ($rows as $row) {
                $result = self::calculate(
                    (float) $row->amount,
                    $salary,
                    $settings,
                    $month,
                    $runningOther,
                );

                $row->forceFill([
                    'tax_year' => $result['tax_year'],
                    'tax_month' => $result['tax_month'],
                    'salary_snapshot' => $result['salary_snapshot'],
                    'salary_threshold_snapshot' => $result['salary_threshold_snapshot'],
                    'is_low_salary_case' => $result['is_low_salary_case'],
                    'month_free_cap_snapshot' => $result['month_free_cap_snapshot'],
                    'non_taxable_amount' => $result['non_taxable_amount'],
                    'taxable_amount' => $result['taxable_amount'],
                    'gpm_amount' => $result['gpm_amount'],
                    'payable_amount' => $result['payable_amount'],
                ])->save();

                $runningOther = round($runningOther + (float) $row->amount, 2);
            }
        });
    }
}
