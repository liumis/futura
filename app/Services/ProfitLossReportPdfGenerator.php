<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\InvoiceCode;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

class ProfitLossReportPdfGenerator
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
        return Pdf::loadView('pdf.profit-loss-statement', self::build($codes, $filters))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @param  iterable<int, InvoiceCode>  $codes
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(iterable $codes, array $filters = []): array
    {
        $statement = ProfitLossStatementBuilder::build($codes);

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $supplierLabel = filled($filters['supplier_label'] ?? null)
            ? (string) $filters['supplier_label']
            : 'All suppliers';

        $lines = collect($statement['lines'])->map(function (array $line): array {
            return [
                ...$line,
                'amount_label' => Money::format($line['amount']),
            ];
        });

        return [
            'company' => CompanySetting::instance(),
            'title' => 'Profit (loss) statement',
            'subtitle' => 'Pelno (nuostolių) ataskaita',
            'periodLabel' => $periodLabel,
            'supplierLabel' => $supplierLabel,
            'lines' => $lines,
            'revenue' => $statement['revenue'],
            'expenses' => $statement['expenses'],
            'net' => $statement['net'],
            'isProfit' => $statement['is_profit'],
            'revenueLabel' => Money::format($statement['revenue']),
            'expensesLabel' => Money::format($statement['expenses']),
            'netLabel' => Money::format($statement['net']),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
