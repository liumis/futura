<?php

namespace App\Services;

use App\Enums\EmployeeNpdType;
use App\Models\Employee;
use App\Models\PayrollTaxSetting;
use Illuminate\Support\Carbon;

class LithuanianPayrollCalculator
{
    /**
     * @return array{
     *     gross: float,
     *     npd: float,
     *     sodra_employee: float,
     *     sodra_health: float,
     *     sodra_pension: float,
     *     sodra_employer: float,
     *     sodra_rate: float,
     *     employer_sodra_rate: float,
     *     gpm: float,
     *     gpm_taxable: float,
     *     net: float,
     *     workplace_cost: float
     * }
     */
    public function calculate(
        float $gross,
        Employee|EmployeeNpdType|null $employeeOrNpdType = null,
        bool $secondPillarEnrolled = false,
        ?float $secondPillarRate = null,
        ?int $year = null,
        bool $fixedTermContract = false,
    ): array {
        $gross = round(max(0, $gross), 2);
        $year ??= (int) now()->year;
        $settings = PayrollTaxSetting::forYear($year);

        $npdType = EmployeeNpdType::Standard;
        if ($employeeOrNpdType instanceof Employee) {
            $npdType = $employeeOrNpdType->npd_type instanceof EmployeeNpdType
                ? $employeeOrNpdType->npd_type
                : EmployeeNpdType::tryFrom((string) $employeeOrNpdType->npd_type) ?? EmployeeNpdType::Standard;
            $secondPillarEnrolled = (bool) $employeeOrNpdType->second_pillar_enrolled;
            $secondPillarRate = $employeeOrNpdType->second_pillar_rate !== null
                ? (float) $employeeOrNpdType->second_pillar_rate
                : null;
        } elseif ($employeeOrNpdType instanceof EmployeeNpdType) {
            $npdType = $employeeOrNpdType;
        }

        $pillarRate = 0.0;
        if ($secondPillarEnrolled) {
            $pillarRate = $secondPillarRate !== null && $secondPillarRate > 0
                ? (float) $secondPillarRate
                : 0.03;
        }

        $healthRate = 0.0698;
        $pensionRate = 0.1252 + $pillarRate;
        $sodraHealth = round($gross * $healthRate, 2);
        $sodraPension = round($gross * $pensionRate, 2);
        $sodra = round($sodraHealth + $sodraPension, 2);
        $employerRate = $fixedTermContract
            ? (float) ($settings->employer_sodra_fixed_term_rate ?? 0.0249)
            : (float) ($settings->employer_sodra_permanent_rate ?? 0.0177);
        $sodraEmployer = round($gross * $employerRate, 2);
        $npd = $this->calculateNpd($gross, $npdType, $settings);
        $gpmTaxable = round(max(0, $gross - $npd), 2);
        $gpm = round($gpmTaxable * (float) $settings->gpm_rate, 2);
        $net = round(max(0, $gross - $sodra - $gpm), 2);
        $workplaceCost = round($gross + $sodraEmployer, 2);

        return [
            'gross' => $gross,
            'npd' => $npd,
            'sodra_employee' => $sodra,
            'sodra_health' => $sodraHealth,
            'sodra_pension' => $sodraPension,
            'sodra_employer' => $sodraEmployer,
            'sodra_rate' => round($healthRate + $pensionRate, 4),
            'employer_sodra_rate' => round($employerRate, 4),
            'gpm' => $gpm,
            'gpm_taxable' => $gpmTaxable,
            'net' => $net,
            'workplace_cost' => $workplaceCost,
        ];
    }

    /**
     * Reverse a paid net amount into a full tax snapshot (bank DU transfers are net).
     *
     * @return array{
     *     gross: float,
     *     npd: float,
     *     sodra_employee: float,
     *     sodra_health: float,
     *     sodra_pension: float,
     *     sodra_employer: float,
     *     sodra_rate: float,
     *     employer_sodra_rate: float,
     *     gpm: float,
     *     gpm_taxable: float,
     *     net: float,
     *     workplace_cost: float
     * }
     */
    public function calculateFromNet(
        float $net,
        Employee|EmployeeNpdType|null $employeeOrNpdType = null,
        bool $secondPillarEnrolled = false,
        ?float $secondPillarRate = null,
        ?int $year = null,
        bool $fixedTermContract = false,
    ): array {
        $target = round(max(0, $net), 2);
        $low = $target;
        $high = max($target * 2.5, $target + 50);

        $best = $this->calculate($target, $employeeOrNpdType, $secondPillarEnrolled, $secondPillarRate, $year, $fixedTermContract);

        for ($i = 0; $i < 48; $i++) {
            $mid = round(($low + $high) / 2, 2);
            $tax = $this->calculate($mid, $employeeOrNpdType, $secondPillarEnrolled, $secondPillarRate, $year, $fixedTermContract);
            $best = $tax;

            if (abs($tax['net'] - $target) <= 0.01) {
                $tax['net'] = $target;

                return $tax;
            }

            if ($tax['net'] > $target) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        $best['net'] = $target;

        return $best;
    }

    public function calculateNpd(float $gross, EmployeeNpdType $type, PayrollTaxSetting $settings): float
    {
        $gross = round(max(0, $gross), 2);

        $npd = match ($type) {
            EmployeeNpdType::None => 0.0,
            EmployeeNpdType::Disability0To25 => (float) $settings->npd_disability_0_25,
            EmployeeNpdType::Disability30To55 => (float) $settings->npd_disability_30_55,
            EmployeeNpdType::Standard => $this->standardNpd($gross, $settings),
        };

        return round(max(0, $npd), 2);
    }

    protected function standardNpd(float $gross, PayrollTaxSetting $settings): float
    {
        $mma = (float) $settings->mma;
        $npdMax = (float) $settings->npd_max;
        $coefficient = (float) $settings->npd_coefficient;

        if ($gross <= $mma) {
            return $npdMax;
        }

        return max(0, $npdMax - $coefficient * ($gross - $mma));
    }

    public function yearFromDate(mixed $date): int
    {
        if ($date instanceof Carbon) {
            return (int) $date->year;
        }

        if (filled($date)) {
            return (int) Carbon::parse((string) $date)->year;
        }

        return (int) now()->year;
    }
}
