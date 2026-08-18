<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\DividendPaymentReport;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

class DividendPaymentReportPdfGenerator
{
    public static function generate(DividendPaymentReport $report): string
    {
        $report->loadMissing(['creator', 'payments.shareholder', 'approvers']);

        $lines = $report->payments->map(function ($payment): array {
            $gross = (float) $payment->amount;
            $gpm = $payment->gpm_amount !== null ? (float) $payment->gpm_amount : 0.0;
            $net = $payment->net_amount !== null ? (float) $payment->net_amount : max(0, $gross - $gpm);

            return [
                'date' => $payment->date?->format('Y-m-d') ?? '—',
                'person' => $payment->shareholder?->name ?? '—',
                'gross' => Money::format($gross),
                'gpm' => Money::format($gpm),
                'net' => Money::format($net),
                'comment' => filled($payment->comment) ? (string) $payment->comment : '—',
                'status' => $payment->status?->label() ?? (string) ($payment->status?->value ?? '—'),
            ];
        });

        $grossTotal = Money::format(
            $report->payments->sum(
                fn ($p): float => (float) $p->amount,
            ),
        );
        $gpmTotal = Money::format(
            $report->payments->sum(
                fn ($p): float => (float) ($p->gpm_amount ?? 0),
            ),
        );
        $netTotal = Money::format(
            $report->payments->sum(
                fn ($p): float => (float) ($p->net_amount ?? 0),
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

        return Pdf::loadView('pdf.dividend-payment-report', [
            'report' => $report,
            'company' => CompanySetting::instance(),
            'lines' => $lines,
            'grossTotal' => $grossTotal,
            'gpmTotal' => $gpmTotal,
            'netTotal' => $netTotal,
            'approvers' => $approvers,
        ])
            ->setPaper('a4', 'landscape')
            ->output();
    }
}

