<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Fr0564ReportBuilder
{
    /**
     * @var array<string, string>
     */
    public const COUNTRY_ISO = [
        'Austria' => 'AT',
        'Belgium' => 'BE',
        'Bulgaria' => 'BG',
        'Croatia' => 'HR',
        'Cyprus' => 'CY',
        'Czech Republic' => 'CZ',
        'Czechia' => 'CZ',
        'Čekija' => 'CZ',
        'Denmark' => 'DK',
        'Estonia' => 'EE',
        'Finland' => 'FI',
        'France' => 'FR',
        'Germany' => 'DE',
        'Greece' => 'EL',
        'Graikija' => 'EL',
        'Hungary' => 'HU',
        'Ireland' => 'IE',
        'Italy' => 'IT',
        'Latvia' => 'LV',
        'Lithuania' => 'LT',
        'Lietuva' => 'LT',
        'Luxembourg' => 'LU',
        'Malta' => 'MT',
        'Netherlands' => 'NL',
        'Poland' => 'PL',
        'Portugal' => 'PT',
        'Romania' => 'RO',
        'Slovakia' => 'SK',
        'Slovenia' => 'SI',
        'Spain' => 'ES',
        'Sweden' => 'SE',
    ];

    /**
     * @var list<string>
     */
    public const EU_CODES = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'EL', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL',
        'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'XI',
    ];

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
            ->whereNotNull('income_type_id')
            ->whereDate('invoice_date', '>=', $period['from']->toDateString())
            ->whereDate('invoice_date', '<=', $period['until']->toDateString())
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (Invoice $invoice): bool => self::isIntraCommunitySupply($invoice))
            ->values();
    }

    public static function isIntraCommunitySupply(Invoice $invoice): bool
    {
        if (PvmReportBuilder::direction($invoice) !== PvmReportBuilder::DIRECTION_SALES) {
            return false;
        }

        $code = strtoupper(trim((string) ($invoice->vatRate?->classificator ?? '')));
        $rate = VatRateResolver::numericRate($invoice->vatRate);

        if (in_array($code, ['PVM15', 'PVM34', 'PVM16', 'PVM33'], true)) {
            return false;
        }

        if ($code !== 'PVM4' && $rate > 0) {
            return false;
        }

        [$country, $vatNumber] = self::partner($invoice);

        return $country !== null
            && $country !== 'LT'
            && self::isEuCode($country)
            && $vatNumber !== '';
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    public static function partner(Invoice $invoice): array
    {
        $contact = $invoice->contact;
        $vatRaw = strtoupper(preg_replace('/[\s.\-\/]/', '', (string) ($contact?->company_vat ?? '')) ?? '');
        $countryFromVat = null;
        $number = $vatRaw;

        if (preg_match('/^([A-Z]{2})(.+)$/', $vatRaw, $matches) === 1 && self::isEuCode($matches[1])) {
            $countryFromVat = self::normalizeCode($matches[1]);
            $number = $matches[2];
        }

        $country = $countryFromVat ?? self::countryIso($contact?->company_country);

        return [$country, $number];
    }

    public static function countryIso(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/', strtoupper($name)) === 1) {
            return self::normalizeCode(strtoupper($name));
        }

        $map = array_change_key_case(self::COUNTRY_ISO, CASE_LOWER);

        return isset($map[strtolower($name)])
            ? self::normalizeCode($map[strtolower($name)])
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month): array
    {
        $company = CompanySetting::instance();
        $invoices = self::invoices($year, $month);
        $grouped = [];

        foreach ($invoices as $invoice) {
            [$country, $vatNumber] = self::partner($invoice);
            if ($country === null || $vatNumber === '') {
                continue;
            }

            $key = $country.'|'.$vatNumber;
            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'country' => $country,
                    'vat_number' => $vatNumber,
                    'name' => trim((string) ($invoice->contact?->company_name ?? '')),
                    'goods' => 0.0,
                    'triangular' => 0.0,
                    'services' => 0.0,
                    'invoice_count' => 0,
                ];
            }

            $grouped[$key]['goods'] = round($grouped[$key]['goods'] + (float) $invoice->sum_without_vat, 2);
            $grouped[$key]['invoice_count']++;
        }

        $lines = collect($grouped)
            ->sortBy(fn (array $line): string => $line['country'].$line['vat_number'])
            ->values()
            ->map(function (array $line, int $index): array {
                $line['row'] = $index + 1;
                $line['goods_int'] = (int) round($line['goods']);
                $line['triangular_int'] = (int) round($line['triangular']);
                $line['services_int'] = (int) round($line['services']);
                $line['goods_label'] = Gpm313ReportBuilder::formatLt($line['goods']);
                $line['triangular_label'] = $line['triangular'] > 0
                    ? Gpm313ReportBuilder::formatLt($line['triangular'])
                    : '';
                $line['services_label'] = $line['services'] > 0
                    ? Gpm313ReportBuilder::formatLt($line['services'])
                    : '';

                return $line;
            })
            ->all();

        $goodsTotal = (int) array_sum(array_column($lines, 'goods_int'));
        $servicesTotal = (int) array_sum(array_column($lines, 'services_int'));
        $monthName = WorkTimeTimesheetPdfGenerator::MONTH_NAMES_LT[$month] ?? (string) $month;
        $extraPages = max(0, (int) ceil(count($lines) / 12) - 1);

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
            'contact_details' => trim(implode(' · ', array_filter([
                trim((string) ($company->company_phone ?? '')),
                trim((string) ($company->company_email ?? '')),
            ]))),
            'lines' => $lines,
            'invoice_count' => $invoices->count(),
            'line_count' => count($lines),
            'field15' => $goodsTotal,
            'field16' => $goodsTotal,
            'field17' => $extraPages,
            'services_total' => $servicesTotal,
            'field15_label' => number_format($goodsTotal, 0, ',', ' '),
            'field16_label' => number_format($goodsTotal, 0, ',', ' '),
        ];
    }

    public static function isEuCode(string $code): bool
    {
        return in_array(self::normalizeCode($code), self::EU_CODES, true)
            || in_array(strtoupper($code), self::EU_CODES, true);
    }

    private static function normalizeCode(string $code): string
    {
        $code = strtoupper($code);

        return $code === 'GR' ? 'EL' : $code;
    }
}
