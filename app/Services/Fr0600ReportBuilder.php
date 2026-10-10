<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Fr0600ReportBuilder
{
    /**
     * @return array{from: Carbon, until: Carbon}
     */
    public static function period(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1)->startOfDay();

        return [
            'from' => $from,
            'until' => $from->copy()->endOfMonth()->endOfDay(),
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    public static function invoices(int $year, int $month): Collection
    {
        $period = self::period($year, $month);

        return Invoice::query()
            ->with(['contact', 'vatRate'])
            ->whereDate('invoice_date', '>=', $period['from']->toDateString())
            ->whereDate('invoice_date', '<=', $period['until']->toDateString())
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month): array
    {
        $company = CompanySetting::instance();
        $invoices = self::invoices($year, $month);
        $fields = self::emptyFields();
        $salesCount = 0;
        $purchaseCount = 0;

        foreach ($invoices as $invoice) {
            $direction = PvmReportBuilder::direction($invoice);
            $base = round((float) $invoice->sum_without_vat, 2);
            $vat = round((float) $invoice->vat, 2);
            $code = strtoupper(trim((string) ($invoice->vatRate?->classificator ?? '')));
            $rate = VatRateResolver::numericRate($invoice->vatRate);

            if ($direction === PvmReportBuilder::DIRECTION_SALES) {
                $salesCount++;
                self::addSale($fields, $code, $rate, $base, $vat);
            } elseif ($direction === PvmReportBuilder::DIRECTION_PURCHASES) {
                $purchaseCount++;
                self::addPurchase($fields, $code, $vat);
            }
        }

        $fields['35'] = round($fields['25'] + $fields['26'] + $fields['27'], 2);
        $fields['36'] = round(
            $fields['29'] + $fields['29a'] + $fields['30'] + $fields['31']
            + $fields['32'] + $fields['33'] + $fields['34']
            - $fields['35'],
            2,
        );

        $monthName = WorkTimeTimesheetPdfGenerator::MONTH_NAMES_LT[$month] ?? (string) $month;
        $labels = [];
        foreach ($fields as $key => $amount) {
            $labels[$key] = Gpm313ReportBuilder::formatLt($amount);
        }

        return [
            'year' => $year,
            'month' => $month,
            'period_key' => sprintf('%04d-%02d', $year, $month),
            'period_label' => $year.' '.$monthName,
            'month_name' => $monthName,
            'company_name' => trim((string) ($company->company_name ?: 'Įmonė')),
            'company_id' => trim((string) ($company->company_id ?? '')),
            'company_vat' => trim((string) ($company->company_vat ?? '')),
            'company_address' => trim((string) ($company->company_address ?? '')),
            'company_email' => trim((string) ($company->company_email ?? '')),
            'company_phone' => trim((string) ($company->company_phone ?? '')),
            'fields' => $fields,
            'labels' => $labels,
            'invoice_count' => $invoices->count(),
            'sales_count' => $salesCount,
            'purchase_count' => $purchaseCount,
            'is_payable' => $fields['36'] >= -0.005,
        ];
    }

    /**
     * @return array<string, float>
     */
    public static function emptyFields(): array
    {
        $keys = [
            '11', '12', '13', '14', '15', '16', '17', '18', '19', '20',
            '21', '22', '23', '24',
            '25', '26', '27',
            '28',
            '29', '29a', '30', '31', '32', '33', '34', '35', '36',
        ];

        return array_fill_keys($keys, 0.0);
    }

    /**
     * @return list<array{key: string, label: string, section: string}>
     */
    public static function fieldMeta(): array
    {
        return [
            ['key' => '11', 'section' => 'I', 'label' => 'PVM apmokestinami sandoriai'],
            ['key' => '12', 'section' => 'I', 'label' => 'Sandoriai, kai PVM išskaito pirkėjas (96 str.)'],
            ['key' => '13', 'section' => 'I', 'label' => 'PVM neapmokestinami sandoriai'],
            ['key' => '14', 'section' => 'I', 'label' => 'Suvartojimas privatiems poreikiams'],
            ['key' => '15', 'section' => 'I', 'label' => 'Ilgalaikio materialiojo turto pasigaminimas'],
            ['key' => '16', 'section' => 'I', 'label' => 'Maržos schema'],
            ['key' => '17', 'section' => 'I', 'label' => 'Prekių eksportas (0 %)'],
            ['key' => '18', 'section' => 'I', 'label' => 'ES PVM mokėtojams patiektos prekės (0 %)'],
            ['key' => '19', 'section' => 'I', 'label' => 'Kiti PVM apmokestinami sandoriai (0 %)'],
            ['key' => '20', 'section' => 'I', 'label' => 'Už Lietuvos ribų įvykę sandoriai'],
            ['key' => '21', 'section' => 'II', 'label' => 'Iš ES įsigytos prekės'],
            ['key' => '22', 'section' => 'II', 'label' => 'Iš ES įsigytos prekės trikampei prekybai'],
            ['key' => '23', 'section' => 'II', 'label' => 'Iš užsienio valstybių įsigytos paslaugos'],
            ['key' => '24', 'section' => 'II', 'label' => 'Iš jų: įsigytos iš ES PVM mokėtojų'],
            ['key' => '25', 'section' => 'III', 'label' => 'Įsigytų prekių ir paslaugų pirkimo PVM'],
            ['key' => '26', 'section' => 'III', 'label' => 'Sumokėtas importo PVM'],
            ['key' => '27', 'section' => 'III', 'label' => 'Importo PVM, kurio įskaitymą kontroliuoja VMI'],
            ['key' => '28', 'section' => 'IV', 'label' => 'Proporcinis PVM atskaitos procentas'],
            ['key' => '29', 'section' => 'V', 'label' => 'Standartinio tarifo pardavimo PVM'],
            ['key' => '29a', 'section' => 'V', 'label' => '12 proc. pardavimo PVM'],
            ['key' => '30', 'section' => 'V', 'label' => '9 proc. pardavimo PVM'],
            ['key' => '31', 'section' => 'V', 'label' => '5 proc. pardavimo PVM'],
            ['key' => '32', 'section' => 'V', 'label' => 'Pardavimo PVM (95 str.)'],
            ['key' => '33', 'section' => 'V', 'label' => 'Pardavimo PVM (96 str.)'],
            ['key' => '34', 'section' => 'V', 'label' => 'Iš ES įsigytų prekių pardavimo PVM'],
            ['key' => '35', 'section' => 'V', 'label' => 'Atskaitomas PVM'],
            ['key' => '36', 'section' => 'V', 'label' => 'Mokėtinas / grąžintinas (−) PVM'],
        ];
    }

    /**
     * @param  array<string, float>  $fields
     */
    private static function addSale(array &$fields, string $code, float $rate, float $base, float $vat): void
    {
        if ($code === 'PVM16') {
            $fields['12'] = round($fields['12'] + $base, 2);

            return;
        }

        if (in_array($code, ['PVM15', 'PVM34'], true)) {
            $fields['20'] = round($fields['20'] + $base, 2);

            return;
        }

        if ($code === 'PVM32') {
            $fields['16'] = round($fields['16'] + $base, 2);
            $fields['29'] = round($fields['29'] + $vat, 2);

            return;
        }

        if ($code === 'PVM33' || ($code === 'PVM4' && $rate <= 0)) {
            $fields['18'] = round($fields['18'] + $base, 2);

            return;
        }

        if ($rate <= 0) {
            $fields['19'] = round($fields['19'] + $base, 2);

            return;
        }

        $fields['11'] = round($fields['11'] + $base, 2);

        if ($rate >= 20) {
            $fields['29'] = round($fields['29'] + $vat, 2);
        } elseif ($rate >= 11) {
            $fields['29a'] = round($fields['29a'] + $vat, 2);
        } elseif ($rate >= 8) {
            $fields['30'] = round($fields['30'] + $vat, 2);
        } else {
            $fields['31'] = round($fields['31'] + $vat, 2);
        }
    }

    /**
     * @param  array<string, float>  $fields
     */
    private static function addPurchase(array &$fields, string $code, float $vat): void
    {
        if (in_array($code, ['PVM15', 'PVM34'], true)) {
            return;
        }

        $fields['25'] = round($fields['25'] + $vat, 2);
    }
}
