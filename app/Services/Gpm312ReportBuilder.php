<?php

namespace App\Services;

use App\Enums\DividendPaymentStatus;
use App\Models\CompanySetting;
use App\Models\Dividend;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class Gpm312ReportBuilder
{
    public const INCOME_CODE = '26';

    public const INCOME_CLASS = 'A';

    public const PROVIDER_INCOME_CODE = '53';

    public const PROVIDER_INCOME_CLASS = 'B';

    /**
     * @return array{from: Carbon, until: Carbon}
     */
    public static function period(int $year): array
    {
        $from = Carbon::create($year, 1, 1)->startOfDay();

        return [
            'from' => $from,
            'until' => $from->copy()->endOfYear()->endOfDay(),
        ];
    }

    /**
     * @return Collection<int, Dividend>
     */
    public static function dividends(int $year): Collection
    {
        $period = self::period($year);

        return Dividend::query()
            ->with('shareholder')
            ->whereDate('date', '>=', $period['from']->toDateString())
            ->whereDate('date', '<=', $period['until']->toDateString())
            ->whereIn('status', [
                DividendPaymentStatus::Open->value,
                DividendPaymentStatus::Payed->value,
            ])
            ->orderBy('date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public static function privateProviderInvoices(int $year): Collection
    {
        $period = self::period($year);

        return Invoice::query()
            ->with('contact')
            ->where('private_provider', true)
            ->whereDate('invoice_date', '>=', $period['from']->toDateString())
            ->whereDate('invoice_date', '<=', $period['until']->toDateString())
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public static function yearSelectOptions(): array
    {
        $options = [];
        $year = (int) now()->year;

        for ($i = 0; $i < 8; $i++) {
            $value = (string) ($year - $i);
            $options[$value] = $value;
        }

        return $options;
    }

    /**
     * @return array{
     *     year: int,
     *     period_label: string,
     *     company_name: string,
     *     company_id: string,
     *     company_address: string,
     *     company_email: string,
     *     company_phone: string,
     *     field4: float,
     *     field5: float,
     *     field6: float,
     *     field7: float,
     *     field4_label: string,
     *     field5_label: string,
     *     field6_label: string,
     *     field7_label: string,
     *     dividend_count: int,
     *     shareholder_count: int,
     *     invoice_count: int,
     *     provider_count: int,
     *     line_count: int,
     *     lines: list<array{
     *         shareholder_id: int|null,
     *         contact_id: int|null,
     *         source: string,
     *         name: string,
     *         class: string,
     *         code: string,
     *         l8: float,
     *         l9: float,
     *         l10: float,
     *         l11: float,
     *         l8_label: string,
     *         l9_label: string,
     *         l10_label: string,
     *         l11_label: string
     *     }>
     * }
     */
    public static function build(int $year): array
    {
        $company = CompanySetting::instance();
        $dividends = self::dividends($year);
        $invoices = self::privateProviderInvoices($year);
        $dividendLines = self::lines($dividends);
        $invoiceLines = self::invoiceLines($invoices);
        $lines = array_values([...$dividendLines, ...$invoiceLines]);

        $field4 = round(array_sum(array_column($lines, 'l8')), 2);
        $field5 = round(array_sum(array_column($lines, 'l10')), 2);
        $field6 = round(array_sum(array_column($lines, 'l11')), 2);

        return [
            'year' => $year,
            'period_label' => (string) $year,
            'company_name' => trim((string) ($company->company_name ?: 'Įmonė')),
            'company_id' => trim((string) ($company->company_id ?? '')),
            'company_address' => trim((string) ($company->company_address ?? '')),
            'company_email' => trim((string) ($company->company_email ?? '')),
            'company_phone' => trim((string) ($company->company_phone ?? '')),
            'field4' => $field4,
            'field5' => $field5,
            'field6' => $field6,
            'field7' => 0.0,
            'field4_label' => Gpm313ReportBuilder::formatLt($field4),
            'field5_label' => Gpm313ReportBuilder::formatLt($field5),
            'field6_label' => Gpm313ReportBuilder::formatLt($field6),
            'field7_label' => Gpm313ReportBuilder::formatLt(0),
            'dividend_count' => $dividends->count(),
            'shareholder_count' => count($dividendLines),
            'invoice_count' => $invoices->count(),
            'provider_count' => count($invoiceLines),
            'line_count' => count($lines),
            'lines' => $lines,
        ];
    }

    /**
     * @param  Collection<int, Dividend>  $dividends
     * @return list<array{
     *     shareholder_id: int|null,
     *     contact_id: int|null,
     *     source: string,
     *     name: string,
     *     class: string,
     *     code: string,
     *     l8: float,
     *     l9: float,
     *     l10: float,
     *     l11: float,
     *     l8_label: string,
     *     l9_label: string,
     *     l10_label: string,
     *     l11_label: string
     * }>
     */
    public static function lines(Collection $dividends): array
    {
        $grouped = $dividends
            ->groupBy(fn (Dividend $dividend): string => (string) ($dividend->shareholder_id ?? 'none'))
            ->values();

        return $grouped->map(function (Collection $rows): array {
            $first = $rows->first();
            $gross = round($rows->sum(fn (Dividend $row): float => (float) ($row->amount ?? 0)), 2);
            $gpm = round($rows->sum(fn (Dividend $row): float => (float) ($row->gpm_amount ?? 0)), 2);
            $rate = $gross > 0 ? round(($gpm / $gross) * 100, 2) : 20.0;

            return [
                'shareholder_id' => $first instanceof Dividend ? $first->shareholder_id : null,
                'contact_id' => null,
                'source' => 'dividend',
                'name' => trim((string) ($first?->shareholder?->name ?? '—')) ?: '—',
                'class' => self::INCOME_CLASS,
                'code' => self::INCOME_CODE,
                'l8' => $gross,
                'l9' => $rate,
                'l10' => $gpm,
                'l11' => 0.0,
                'l8_label' => Gpm313ReportBuilder::formatLt($gross),
                'l9_label' => number_format($rate, 2, ',', ' '),
                'l10_label' => Gpm313ReportBuilder::formatLt($gpm),
                'l11_label' => Gpm313ReportBuilder::formatLt(0),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @return list<array{
     *     shareholder_id: int|null,
     *     contact_id: int|null,
     *     source: string,
     *     name: string,
     *     class: string,
     *     code: string,
     *     l8: float,
     *     l9: float,
     *     l10: float,
     *     l11: float,
     *     l8_label: string,
     *     l9_label: string,
     *     l10_label: string,
     *     l11_label: string
     * }>
     */
    public static function invoiceLines(Collection $invoices): array
    {
        $grouped = $invoices
            ->groupBy(fn (Invoice $invoice): string => (string) ($invoice->contact_id ?? 'none'))
            ->values();

        return $grouped->map(function (Collection $rows): array {
            $first = $rows->first();
            $gross = round($rows->sum(fn (Invoice $row): float => (float) ($row->sum_inc_vat ?? 0)), 2);
            $name = trim((string) (
                $first?->contact?->company_name
                ?: $first?->contact?->contact_name
                ?: '—'
            )) ?: '—';

            return [
                'shareholder_id' => null,
                'contact_id' => $first instanceof Invoice ? $first->contact_id : null,
                'source' => 'invoice',
                'name' => $name,
                'class' => self::PROVIDER_INCOME_CLASS,
                'code' => self::PROVIDER_INCOME_CODE,
                'l8' => $gross,
                'l9' => 0.0,
                'l10' => 0.0,
                'l11' => 0.0,
                'l8_label' => Gpm313ReportBuilder::formatLt($gross),
                'l9_label' => number_format(0, 2, ',', ' '),
                'l10_label' => Gpm313ReportBuilder::formatLt(0),
                'l11_label' => Gpm313ReportBuilder::formatLt(0),
            ];
        })->all();
    }
}
