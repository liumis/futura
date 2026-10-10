<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class EmployeePayslipPdfGenerator
{
    /** @var array<int, string> */
    public const MONTH_NAMES_GENITIVE_LT = PayrollAccrualJournalPdfGenerator::MONTH_NAMES_GENITIVE_LT;

    public static function generate(EmployeeMonthlyPayment $payment): string
    {
        $payload = self::build($payment);

        return Pdf::loadView('pdf.employee-payslip', $payload)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(EmployeeMonthlyPayment $payment): array
    {
        $payment->loadMissing('employee');
        $employee = $payment->employee;

        if (! $employee instanceof Employee) {
            throw new \InvalidArgumentException('Payment has no employee.');
        }

        $paymentDate = Carbon::parse((string) $payment->payment_date)->startOfDay();
        $year = (int) $paymentDate->year;
        $month = (int) $paymentDate->month;
        $monthGenitive = self::MONTH_NAMES_GENITIVE_LT[$month] ?? $paymentDate->format('F');

        $base = round((float) ($payment->base_salary ?? 0), 2);
        $bonus = $payment->bonus_payment !== null ? round((float) $payment->bonus_payment, 2) : 0.0;
        $gross = $payment->gross_amount !== null
            ? round((float) $payment->gross_amount, 2)
            : round($base + $bonus, 2);

        $calculator = app(LithuanianPayrollCalculator::class);
        $tax = $calculator->calculate($gross, $employee, year: $year);

        $npd = $payment->npd_amount !== null ? round((float) $payment->npd_amount, 2) : $tax['npd'];
        $gpm = $payment->gpm_amount !== null ? round((float) $payment->gpm_amount, 2) : $tax['gpm'];
        $net = $payment->net_amount !== null ? round((float) $payment->net_amount, 2) : $tax['net'];
        $sodraEmployer = $payment->sodra_employer_amount !== null
            ? round((float) $payment->sodra_employer_amount, 2)
            : $tax['sodra_employer'];

        $psd = $tax['sodra_health'];
        $vsdBase = round($gross * 0.1252, 2);
        $pillar = round(max(0, $tax['sodra_pension'] - $vsdBase), 2);
        $vsd = $vsdBase;

        // Prefer stored employee Sodra total if present; keep PSD/VSD/pillar split from rates.
        if ($payment->sodra_employee_amount !== null) {
            $storedSodra = round((float) $payment->sodra_employee_amount, 2);
            $splitTotal = round($psd + $vsd + $pillar, 2);
            if (abs($storedSodra - $splitTotal) > 0.05) {
                // Scale split to match stored total.
                if ($splitTotal > 0) {
                    $factor = $storedSodra / $splitTotal;
                    $psd = round($psd * $factor, 2);
                    $vsd = round($vsd * $factor, 2);
                    $pillar = round($storedSodra - $psd - $vsd, 2);
                }
            }
        }

        $earnings = [
            ['label' => 'Darbo užmokestis', 'amount' => $base],
            ['label' => 'Atostoginiai', 'amount' => 0.0],
            ['label' => 'Kompensacija', 'amount' => 0.0],
            ['label' => 'Liga', 'amount' => 0.0],
            ['label' => 'Priedai', 'amount' => $bonus],
        ];
        $earningsTotal = round(array_sum(array_column($earnings, 'amount')), 2);
        if (abs($earningsTotal - $gross) > 0.009) {
            // Keep Viso aligned to payroll gross when base+bonus differ from snapshot.
            $earningsTotal = $gross;
        }

        $deductions = [
            ['label' => 'Pajamų mokestis (GPM) 20%', 'amount' => $gpm],
            ['label' => 'Valstyb. Soc. Draud. (VSD)', 'amount' => $vsd],
            ['label' => 'Prival. Sveik. Draud. (PSD)', 'amount' => $psd],
            ['label' => 'Pens. kaupimas 3%', 'amount' => $pillar],
        ];
        $deductionsTotal = round(array_sum(array_column($deductions, 'amount')), 2);

        $company = CompanySetting::instance();
        $employerRatePct = rtrim(rtrim(number_format($tax['employer_sodra_rate'] * 100, 2, '.', ''), '0'), '.');

        return [
            'logoDataUri' => self::logoDataUri(),
            'companyName' => (string) ($company->company_name ?: 'Įmonė'),
            'companyId' => (string) ($company->company_id ?? ''),
            'companyAddress' => (string) ($company->company_address ?? ''),
            'companyEmail' => (string) ($company->company_email ?? ''),
            'companyPhone' => (string) ($company->company_phone ?? ''),
            'employeeName' => $employee->fullName(),
            'periodLabel' => sprintf('Už %d m. %s mėn.', $year, $monthGenitive),
            'baseSalary' => $base,
            'npd' => $npd,
            'earnings' => $earnings,
            'earningsTotal' => $earningsTotal,
            'deductions' => $deductions,
            'deductionsTotal' => $deductionsTotal,
            'netAmount' => $net,
            'sodraEmployer' => $sodraEmployer,
            'sodraEmployerLabel' => 'SODRA ('.$employerRatePct.'%)',
            'paymentId' => (int) $payment->getKey(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ];
    }

    public static function formatEur(float $amount): string
    {
        return number_format($amount, 2, ',', ' ');
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

        return null;
    }
}
