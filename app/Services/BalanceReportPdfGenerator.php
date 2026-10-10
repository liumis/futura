<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\InvoiceCode;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class BalanceReportPdfGenerator
{
    /**
     * @param  iterable<int, InvoiceCode>  $codes
     * @param  array{
     *     from?: string|null,
     *     until?: string|null,
     *     contact_id?: int|string|null,
     *     period_label?: string,
     *     supplier_label?: string
     * }  $filters
     */
    public static function generate(iterable $codes, array $filters = []): string
    {
        return Pdf::loadView('pdf.balance-report', self::build($codes, $filters))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  iterable<int, InvoiceCode>  $codes
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(iterable $codes, array $filters = []): array
    {
        $rows = Collection::make($codes)
            ->map(function (InvoiceCode $code): array {
                $debit = round((float) ($code->debit ?? 0), 2);
                $credit = round((float) ($code->credit ?? 0), 2);
                $balance = round($debit - $credit, 2);

                return [
                    'code' => filled($code->code) ? (string) $code->code : '—',
                    'name' => filled($code->name) ? (string) $code->name : '—',
                    'line_count' => (int) ($code->line_count ?? 0),
                    'debit' => Money::format($debit),
                    'credit' => Money::format($credit),
                    'balance' => Money::format($balance),
                    'debit_raw' => $debit,
                    'credit_raw' => $credit,
                    'balance_raw' => $balance,
                ];
            })
            ->values();

        $debitTotal = round((float) $rows->sum('debit_raw'), 2);
        $creditTotal = round((float) $rows->sum('credit_raw'), 2);
        $balanceTotal = round($debitTotal - $creditTotal, 2);

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $supplierLabel = filled($filters['supplier_label'] ?? null)
            ? (string) $filters['supplier_label']
            : 'All suppliers';

        return [
            'company' => CompanySetting::instance(),
            'title' => 'Balance',
            'subtitle' => 'Balansas',
            'periodLabel' => $periodLabel,
            'supplierLabel' => $supplierLabel,
            'lines' => $rows,
            'debitTotal' => Money::format($debitTotal),
            'creditTotal' => Money::format($creditTotal),
            'balanceTotal' => Money::format($balanceTotal),
            'debitTotalRaw' => $debitTotal,
            'creditTotalRaw' => $creditTotal,
            'balanceTotalRaw' => $balanceTotal,
            'balanced' => abs($balanceTotal) < 0.005,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
