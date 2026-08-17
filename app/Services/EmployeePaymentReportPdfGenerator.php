<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeePaymentReport;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeePaymentReportPdfGenerator
{
    public static function generate(EmployeePaymentReport $report): string
    {
        $report->loadMissing(['creator', 'payments.employee', 'approvers']);

        $lines = $report->payments->map(function ($payment): array {
            $base = (float) $payment->base_salary;
            $bonus = $payment->bonus_payment !== null ? (float) $payment->bonus_payment : 0.0;
            $gross = $payment->gross_amount !== null ? (float) $payment->gross_amount : ($base + $bonus);
            $npd = $payment->npd_amount !== null ? (float) $payment->npd_amount : 0.0;
            $gpm = $payment->gpm_amount !== null ? (float) $payment->gpm_amount : 0.0;
            $sodraEmployee = $payment->sodra_employee_amount !== null ? (float) $payment->sodra_employee_amount : 0.0;
            $sodraEmployer = $payment->sodra_employer_amount !== null ? (float) $payment->sodra_employer_amount : 0.0;
            $net = $payment->net_amount !== null ? (float) $payment->net_amount : max(0, $gross - $sodraEmployee - $gpm);
            $sodraHealth = round($gross * 0.0698, 2);
            $sodraPension = round($sodraEmployee - $sodraHealth, 2);
            $workplaceCost = round($gross + $sodraEmployer, 2);

            return [
                'date' => $payment->payment_date?->format('Y-m-d') ?? '—',
                'person' => $payment->employee?->fullName() ?? '—',
                'base_salary' => Money::format($base),
                'bonus_payment' => $payment->bonus_payment !== null ? Money::format($bonus) : '—',
                'gross' => Money::format($gross),
                'npd' => Money::format($npd),
                'gpm' => Money::format($gpm),
                'sodra_health' => Money::format($sodraHealth),
                'sodra_pension' => Money::format($sodraPension),
                'net' => Money::format($net),
                'sodra_employer' => Money::format($sodraEmployer),
                'workplace_cost' => Money::format($workplaceCost),
                'comment' => filled($payment->comment) ? (string) $payment->comment : '—',
                'status' => $payment->status?->label() ?? (string) ($payment->status?->value ?? '—'),
            ];
        });

        $grossTotal = Money::format(
            $report->payments->sum(
                fn ($payment): float => (float) ($payment->gross_amount ?? ((float) $payment->base_salary + (float) ($payment->bonus_payment ?? 0))),
            ),
        );
        $netTotal = Money::format($report->payments->sum(fn ($payment): float => (float) ($payment->net_amount ?? 0)));
        $workplaceCostTotal = Money::format(
            $report->payments->sum(
                fn ($payment): float => (float) ($payment->gross_amount ?? ((float) $payment->base_salary + (float) ($payment->bonus_payment ?? 0)))
                    + (float) ($payment->sodra_employer_amount ?? 0),
            ),
        );

        $approvers = $report->approvers->map(function ($user): array {
            return [
                'name' => $user->fullName() ?: (string) $user->email,
                'approved' => filled($user->pivot?->approved_at),
                'auto' => (bool) ($user->pivot?->is_auto_approved ?? false),
                'approved_at' => filled($user->pivot?->approved_at)
                    ? \Illuminate\Support\Carbon::parse($user->pivot->approved_at)->format('Y-m-d H:i:s')
                    : null,
            ];
        });

        return Pdf::loadView('pdf.employee-payment-report', [
            'report' => $report,
            'company' => CompanySetting::instance(),
            'lines' => $lines,
            'grossTotal' => $grossTotal,
            'netTotal' => $netTotal,
            'workplaceCostTotal' => $workplaceCostTotal,
            'approvers' => $approvers,
        ])
            ->setPaper('a4', 'landscape')
            ->output();
    }
}
