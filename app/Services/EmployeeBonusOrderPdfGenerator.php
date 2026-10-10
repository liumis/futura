<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeMonthlyPayment;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

class EmployeeBonusOrderPdfGenerator
{
    public static function generate(EmployeeMonthlyPayment $payment): string
    {
        return Pdf::loadView('pdf.employee-bonus-isakymas', self::build($payment))
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
        $company = CompanySetting::instance();
        $paymentDate = Carbon::parse((string) $payment->payment_date)->startOfDay();
        $monthGenitive = PayrollAccrualJournalPdfGenerator::MONTH_NAMES_GENITIVE_LT[(int) $paymentDate->month]
            ?? $paymentDate->format('F');

        $bonus = round((float) ($payment->bonus_payment ?? 0), 2);

        return [
            'orderNumber' => 'PR-'.$payment->getKey().'/'.$paymentDate->format('Y'),
            'employeeName' => $employee?->fullName() ?: '—',
            'employeePosition' => $employee?->position,
            'bonusAmount' => Money::format($bonus),
            'bonusAmountRaw' => $bonus,
            'periodLabel' => sprintf('%d m. %s mėn.', $paymentDate->year, $monthGenitive),
            'paymentDate' => $paymentDate->toDateString(),
            'comment' => trim((string) ($payment->comment ?? '')),
            'companyName' => filled($company->company_name) ? (string) $company->company_name : 'Darbdavys',
            'companyAddress' => $company->company_address,
            'companyId' => $company->company_id,
            'generatedAt' => now()->format('Y-m-d'),
            'paymentId' => (int) $payment->getKey(),
        ];
    }
}
