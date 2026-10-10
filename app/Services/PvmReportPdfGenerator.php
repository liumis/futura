<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class PvmReportPdfGenerator
{
    /**
     * @param  iterable<int, Invoice>  $invoices
     * @param  array<string, mixed>  $filters
     */
    public static function generate(iterable $invoices, array $filters = []): string
    {
        return Pdf::loadView('pdf.pvm-report', self::build($invoices, $filters))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(iterable $invoices, array $filters = []): array
    {
        $invoices = Collection::make($invoices);
        $summary = PvmReportBuilder::build($invoices);

        $lines = $invoices->map(function (Invoice $invoice): array {
            $invoice->loadMissing(['contact', 'vatRate']);
            $direction = PvmReportBuilder::direction($invoice);

            return [
                'date' => $invoice->invoice_date?->format('Y-m-d') ?? '—',
                'invoice_number' => $invoice->displayNumber(),
                'company' => $invoice->contact?->company_name ?: '—',
                'direction' => PvmReportBuilder::directionLabel($direction),
                'rate' => PvmReportBuilder::rateLabel($invoice->vatRate),
                'base' => Money::format((float) $invoice->sum_without_vat),
                'vat' => Money::format((float) $invoice->vat),
                'total' => Money::format((float) $invoice->sum_inc_vat),
                'base_raw' => round((float) $invoice->sum_without_vat, 2),
                'vat_raw' => round((float) $invoice->vat, 2),
                'total_raw' => round((float) $invoice->sum_inc_vat, 2),
            ];
        })->values();

        $groups = collect($summary['groups'])->map(function (array $group): array {
            return [
                ...$group,
                'sales_base_label' => Money::format($group['sales_base']),
                'output_vat_label' => Money::format($group['output_vat']),
                'purchase_base_label' => Money::format($group['purchase_base']),
                'input_vat_label' => Money::format($group['input_vat']),
            ];
        });

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $companyLabel = filled($filters['company_label'] ?? null)
            ? (string) $filters['company_label']
            : 'All companies';
        $directionLabel = filled($filters['direction_label'] ?? null)
            ? (string) $filters['direction_label']
            : 'All invoices';

        return [
            'company' => CompanySetting::instance(),
            'title' => 'PVM report',
            'subtitle' => 'Pridėtinės vertės mokestis',
            'periodLabel' => $periodLabel,
            'companyLabel' => $companyLabel,
            'directionLabel' => $directionLabel,
            'lines' => $lines,
            'groups' => $groups,
            'salesBaseLabel' => Money::format($summary['sales_base']),
            'purchaseBaseLabel' => Money::format($summary['purchase_base']),
            'outputVatLabel' => Money::format($summary['output_vat']),
            'inputVatLabel' => Money::format($summary['input_vat']),
            'payableLabel' => Money::format($summary['payable']),
            'payable' => $summary['payable'],
            'isPayable' => $summary['is_payable'],
            'unclassifiedVat' => $summary['unclassified_vat'],
            'unclassifiedVatLabel' => Money::format($summary['unclassified_vat']),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
