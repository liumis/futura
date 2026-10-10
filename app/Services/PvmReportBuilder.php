<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\VatRate;
use Illuminate\Support\Collection;

/**
 * Lithuanian VAT (PVM) period summary from invoices.
 *
 * Sales invoices (income type) contribute output VAT (pardavimo PVM).
 * Purchase invoices (expense type) contribute input VAT (pirkimo PVM).
 * Payable = output − input (negative = refundable / gautinas PVM).
 */
final class PvmReportBuilder
{
    public const DIRECTION_SALES = 'sales';

    public const DIRECTION_PURCHASES = 'purchases';

    public const DIRECTION_UNCLASSIFIED = 'unclassified';

    public static function direction(Invoice $invoice): string
    {
        if (filled($invoice->income_type_id)) {
            return self::DIRECTION_SALES;
        }

        if (filled($invoice->expense_type_id)) {
            return self::DIRECTION_PURCHASES;
        }

        return self::DIRECTION_UNCLASSIFIED;
    }

    public static function directionLabel(string $direction): string
    {
        return match ($direction) {
            self::DIRECTION_SALES => 'Sales',
            self::DIRECTION_PURCHASES => 'Purchases',
            default => 'Unclassified',
        };
    }

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @return array{
     *     groups: list<array{
     *         vat_rate_id: int|null,
     *         classificator: string,
     *         rate_label: string,
     *         sales_base: float,
     *         output_vat: float,
     *         purchase_base: float,
     *         input_vat: float,
     *         unclassified_base: float,
     *         unclassified_vat: float
     *     }>,
     *     sales_base: float,
     *     purchase_base: float,
     *     output_vat: float,
     *     input_vat: float,
     *     unclassified_vat: float,
     *     payable: float,
     *     is_payable: bool,
     *     invoices: int
     * }
     */
    public static function build(iterable $invoices): array
    {
        $groups = [];

        foreach (Collection::make($invoices) as $invoice) {
            $invoice->loadMissing('vatRate');
            $rate = $invoice->vatRate;
            $key = $rate instanceof VatRate ? (string) $rate->getKey() : 'none';

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'vat_rate_id' => $rate?->getKey(),
                    'classificator' => filled($rate?->classificator) ? (string) $rate->classificator : '—',
                    'rate_label' => self::rateLabel($rate),
                    'sales_base' => 0.0,
                    'output_vat' => 0.0,
                    'purchase_base' => 0.0,
                    'input_vat' => 0.0,
                    'unclassified_base' => 0.0,
                    'unclassified_vat' => 0.0,
                ];
            }

            $base = round((float) $invoice->sum_without_vat, 2);
            $vat = round((float) $invoice->vat, 2);
            $direction = self::direction($invoice);

            if ($direction === self::DIRECTION_SALES) {
                $groups[$key]['sales_base'] = round($groups[$key]['sales_base'] + $base, 2);
                $groups[$key]['output_vat'] = round($groups[$key]['output_vat'] + $vat, 2);
            } elseif ($direction === self::DIRECTION_PURCHASES) {
                $groups[$key]['purchase_base'] = round($groups[$key]['purchase_base'] + $base, 2);
                $groups[$key]['input_vat'] = round($groups[$key]['input_vat'] + $vat, 2);
            } else {
                $groups[$key]['unclassified_base'] = round($groups[$key]['unclassified_base'] + $base, 2);
                $groups[$key]['unclassified_vat'] = round($groups[$key]['unclassified_vat'] + $vat, 2);
            }
        }

        $rows = Collection::make(array_values($groups))
            ->sortBy(fn (array $group): string => $group['classificator'])
            ->values()
            ->all();

        $salesBase = round((float) collect($rows)->sum('sales_base'), 2);
        $purchaseBase = round((float) collect($rows)->sum('purchase_base'), 2);
        $outputVat = round((float) collect($rows)->sum('output_vat'), 2);
        $inputVat = round((float) collect($rows)->sum('input_vat'), 2);
        $unclassifiedVat = round((float) collect($rows)->sum('unclassified_vat'), 2);
        $payable = round($outputVat - $inputVat, 2);

        return [
            'groups' => $rows,
            'sales_base' => $salesBase,
            'purchase_base' => $purchaseBase,
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'unclassified_vat' => $unclassifiedVat,
            'payable' => $payable,
            'is_payable' => $payable >= -0.005,
            'invoices' => Collection::make($invoices)->count(),
        ];
    }

    public static function rateLabel(?VatRate $rate): string
    {
        if ($rate === null) {
            return 'No rate';
        }

        $classificator = filled($rate->classificator) ? (string) $rate->classificator : 'PVM';
        if ($rate->rate === null) {
            return $classificator;
        }

        return $classificator.' ('.number_format((float) $rate->rate, 2).'%)';
    }
}
