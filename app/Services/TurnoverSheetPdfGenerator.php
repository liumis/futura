<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\InvoiceCode;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class TurnoverSheetPdfGenerator
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
        return Pdf::loadView('pdf.turnover-sheet', self::build($codes, $filters))
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
                $calc = TurnoverSheetCalculator::forAccount(
                    (float) ($code->opening_debit ?? 0),
                    (float) ($code->opening_credit ?? 0),
                    (float) ($code->turnover_debit ?? 0),
                    (float) ($code->turnover_credit ?? 0),
                );

                return [
                    'code' => filled($code->code) ? (string) $code->code : '—',
                    'name' => filled($code->name) ? (string) $code->name : '—',
                    'opening_debit' => Money::format($calc['opening_debit']),
                    'opening_credit' => Money::format($calc['opening_credit']),
                    'turnover_debit' => Money::format($calc['turnover_debit']),
                    'turnover_credit' => Money::format($calc['turnover_credit']),
                    'closing_debit' => Money::format($calc['closing_debit']),
                    'closing_credit' => Money::format($calc['closing_credit']),
                    'opening_debit_raw' => $calc['opening_debit'],
                    'opening_credit_raw' => $calc['opening_credit'],
                    'turnover_debit_raw' => $calc['turnover_debit'],
                    'turnover_credit_raw' => $calc['turnover_credit'],
                    'closing_debit_raw' => $calc['closing_debit'],
                    'closing_credit_raw' => $calc['closing_credit'],
                ];
            })
            ->values();

        $openingDebit = round((float) $rows->sum('opening_debit_raw'), 2);
        $openingCredit = round((float) $rows->sum('opening_credit_raw'), 2);
        $turnoverDebit = round((float) $rows->sum('turnover_debit_raw'), 2);
        $turnoverCredit = round((float) $rows->sum('turnover_credit_raw'), 2);
        $closingDebit = round((float) $rows->sum('closing_debit_raw'), 2);
        $closingCredit = round((float) $rows->sum('closing_credit_raw'), 2);

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $supplierLabel = filled($filters['supplier_label'] ?? null)
            ? (string) $filters['supplier_label']
            : 'All suppliers';

        return [
            'company' => CompanySetting::instance(),
            'title' => 'Turnover sheet',
            'subtitle' => 'Didžioji apskaitos knyga',
            'periodLabel' => $periodLabel,
            'supplierLabel' => $supplierLabel,
            'lines' => $rows,
            'openingDebitTotal' => Money::format($openingDebit),
            'openingCreditTotal' => Money::format($openingCredit),
            'turnoverDebitTotal' => Money::format($turnoverDebit),
            'turnoverCreditTotal' => Money::format($turnoverCredit),
            'closingDebitTotal' => Money::format($closingDebit),
            'closingCreditTotal' => Money::format($closingCredit),
            'openingDebitTotalRaw' => $openingDebit,
            'openingCreditTotalRaw' => $openingCredit,
            'turnoverDebitTotalRaw' => $turnoverDebit,
            'turnoverCreditTotalRaw' => $turnoverCredit,
            'closingDebitTotalRaw' => $closingDebit,
            'closingCreditTotalRaw' => $closingCredit,
            'openingBalanced' => abs($openingDebit - $openingCredit) < 0.005,
            'turnoverBalanced' => abs($turnoverDebit - $turnoverCredit) < 0.005,
            'closingBalanced' => abs($closingDebit - $closingCredit) < 0.005,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
