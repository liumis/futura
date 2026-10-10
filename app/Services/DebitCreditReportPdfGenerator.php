<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\InvoiceFinanceLine;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class DebitCreditReportPdfGenerator
{
    /**
     * @param  iterable<int, InvoiceFinanceLine>  $lines
     * @param  array{
     *     from?: string|null,
     *     until?: string|null,
     *     contact_id?: int|string|null,
     *     period_label?: string,
     *     supplier_label?: string
     * }  $filters
     */
    public static function generate(iterable $lines, array $filters = []): string
    {
        return Pdf::loadView('pdf.debit-credit-report', self::build($lines, $filters))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  iterable<int, InvoiceFinanceLine>  $lines
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(iterable $lines, array $filters = []): array
    {
        $rows = Collection::make($lines)
            ->map(function (InvoiceFinanceLine $line): array {
                $line->loadMissing(['invoice.contact', 'invoiceCode']);

                return [
                    'date' => $line->invoice?->invoice_date?->format('Y-m-d') ?? '—',
                    'invoice_number' => filled($line->invoice?->invoice_number)
                        ? (string) $line->invoice->invoice_number
                        : '—',
                    'supplier' => filled($line->invoice?->contact?->company_name)
                        ? (string) $line->invoice->contact->company_name
                        : '—',
                    'code' => filled($line->invoiceCode?->code)
                        ? (string) $line->invoiceCode->code
                        : '—',
                    'name' => filled($line->name) ? (string) $line->name : '—',
                    'debit' => Money::format((float) $line->debit),
                    'credit' => Money::format((float) $line->credit),
                    'debit_raw' => round((float) $line->debit, 2),
                    'credit_raw' => round((float) $line->credit, 2),
                ];
            })
            ->values();

        $debitTotal = round((float) $rows->sum('debit_raw'), 2);
        $creditTotal = round((float) $rows->sum('credit_raw'), 2);

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $supplierLabel = filled($filters['supplier_label'] ?? null)
            ? (string) $filters['supplier_label']
            : 'All suppliers';

        return [
            'company' => CompanySetting::instance(),
            'title' => 'Debit/credit report',
            'periodLabel' => $periodLabel,
            'supplierLabel' => $supplierLabel,
            'lines' => $rows,
            'debitTotal' => Money::format($debitTotal),
            'creditTotal' => Money::format($creditTotal),
            'debitTotalRaw' => $debitTotal,
            'creditTotalRaw' => $creditTotal,
            'balanced' => abs($debitTotal - $creditTotal) < 0.005,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
