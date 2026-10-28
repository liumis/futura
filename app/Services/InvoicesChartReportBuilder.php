<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

final class InvoicesChartReportBuilder
{
    public const STATUS_ALL = 'all';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTLY_PAID = 'partly_paid';

    public const STATUS_UNPAID = 'unpaid';

    public const LATE_ANY = 'any';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_ALL => 'All',
            self::STATUS_PAID => 'Paid',
            self::STATUS_PARTLY_PAID => 'Partly paid',
            self::STATUS_UNPAID => 'Unpaid',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lateDaysOptions(): array
    {
        return [
            self::LATE_ANY => 'Any',
            '1' => '1+ days late',
            '7' => '7+ days late',
            '15' => '15+ days late',
            '30' => '30+ days late',
            '60' => '60+ days late',
            '90' => '90+ days late',
        ];
    }

    public static function normalizeStatus(mixed $status): string
    {
        return match ((string) $status) {
            self::STATUS_PAID => self::STATUS_PAID,
            self::STATUS_PARTLY_PAID => self::STATUS_PARTLY_PAID,
            self::STATUS_UNPAID => self::STATUS_UNPAID,
            default => self::STATUS_ALL,
        };
    }

    public static function normalizeLateDays(mixed $lateDays): ?int
    {
        if ($lateDays === null || $lateDays === '' || (string) $lateDays === self::LATE_ANY) {
            return null;
        }

        $days = (int) $lateDays;

        return $days > 0 ? $days : null;
    }

    /**
     * @return array{
     *     title: string,
     *     period_label: string,
     *     status: string,
     *     status_label: string,
     *     late_days: ?int,
     *     late_days_label: string,
     *     contact_id: ?int,
     *     contact_label: string,
     *     country: ?string,
     *     country_label: string,
     *     from: ?string,
     *     until: ?string,
     *     as_of: string,
     *     invoice_count: int,
     *     paid_count: int,
     *     partly_paid_count: int,
     *     unpaid_count: int,
     *     paid_total: float,
     *     partly_paid_total: float,
     *     unpaid_total: float,
     *     outstanding_total: float,
     *     grand_total: float,
     *     days: list<array{
     *         day: string,
     *         label: string,
     *         paid: float,
     *         partly_paid: float,
     *         unpaid: float
     *     }>
     * }
     */
    public static function build(
        mixed $from = null,
        mixed $until = null,
        mixed $status = self::STATUS_ALL,
        mixed $lateDays = self::LATE_ANY,
        mixed $contactId = null,
        mixed $country = null,
        mixed $asOf = null,
    ): array {
        $status = self::normalizeStatus($status);
        $minLateDays = self::normalizeLateDays($lateDays);
        $fromDate = self::normalizeDate($from);
        $untilDate = self::normalizeDate($until);
        $asOfDate = self::asOf($asOf);
        $contactId = filled($contactId) ? (int) $contactId : null;
        $country = filled($country) ? trim((string) $country) : null;

        if ($fromDate !== null && $untilDate !== null && $fromDate > $untilDate) {
            [$fromDate, $untilDate] = [$untilDate, $fromDate];
        }

        $query = Invoice::query()
            ->with(['contact:id,company_name,company_country'])
            ->whereNotNull('invoice_date');

        if ($fromDate !== null) {
            $query->whereDate('invoice_date', '>=', $fromDate);
        }

        if ($untilDate !== null) {
            $query->whereDate('invoice_date', '<=', $untilDate);
        }

        if ($contactId !== null && $contactId > 0) {
            $query->where('contact_id', $contactId);
        }

        if ($country !== null) {
            $normalized = mb_strtolower($country);
            $query->whereHas('contact', function (Builder $contact) use ($normalized): void {
                $contact->whereRaw('LOWER(TRIM(company_country)) = ?', [$normalized]);
            });
        }

        $invoices = $query
            ->orderBy('invoice_date')
            ->get([
                'id',
                'contact_id',
                'invoice_date',
                'payment_term',
                'sum_inc_vat',
                'sum_inc_vat_eur',
                'currency',
                'currency_rate',
                'paid',
                'paid_amount',
            ]);

        $paidTotal = 0.0;
        $partlyPaidTotal = 0.0;
        $unpaidTotal = 0.0;
        $outstandingTotal = 0.0;
        $paidCount = 0;
        $partlyPaidCount = 0;
        $unpaidCount = 0;
        $byDay = [];

        foreach ($invoices as $invoice) {
            $settlement = $invoice->paymentSettlementStatus();

            if ($status !== self::STATUS_ALL && $settlement !== $status) {
                continue;
            }

            $daysLate = self::daysLate($invoice, $asOfDate);

            if ($minLateDays !== null && $daysLate < $minLateDays) {
                continue;
            }

            $amount = round(abs($invoice->amountInEur()), 2);
            $outstanding = round($invoice->outstandingAmountInEur(), 2);
            $dayKey = $invoice->invoice_date instanceof Carbon
                ? $invoice->invoice_date->toDateString()
                : Carbon::parse((string) $invoice->invoice_date)->toDateString();

            if (! isset($byDay[$dayKey])) {
                $byDay[$dayKey] = [
                    'paid' => 0.0,
                    'partly_paid' => 0.0,
                    'unpaid' => 0.0,
                ];
            }

            if ($settlement === self::STATUS_PAID) {
                $paidTotal += $amount;
                $paidCount++;
                $byDay[$dayKey]['paid'] += $amount;
            } elseif ($settlement === self::STATUS_PARTLY_PAID) {
                $partlyPaidTotal += $amount;
                $partlyPaidCount++;
                $outstandingTotal += $outstanding;
                $byDay[$dayKey]['partly_paid'] += $amount;
            } else {
                $unpaidTotal += $amount;
                $unpaidCount++;
                $outstandingTotal += $outstanding;
                $byDay[$dayKey]['unpaid'] += $amount;
            }
        }

        ksort($byDay);

        $days = [];
        foreach ($byDay as $day => $bucket) {
            $days[] = [
                'day' => $day,
                'label' => Carbon::parse($day)->format('Y-m-d'),
                'paid' => round($bucket['paid'], 2),
                'partly_paid' => round($bucket['partly_paid'], 2),
                'unpaid' => round($bucket['unpaid'], 2),
            ];
        }

        $invoiceCount = $paidCount + $partlyPaidCount + $unpaidCount;
        $grandTotal = round($paidTotal + $partlyPaidTotal + $unpaidTotal, 2);

        return [
            'title' => 'Invoices by payment status',
            'period_label' => self::periodLabel($fromDate, $untilDate),
            'status' => $status,
            'status_label' => self::statusOptions()[$status] ?? 'All',
            'late_days' => $minLateDays,
            'late_days_label' => $minLateDays === null
                ? 'Any late days'
                : $minLateDays.'+ days late',
            'contact_id' => $contactId,
            'contact_label' => self::contactLabel($contactId),
            'country' => $country,
            'country_label' => $country !== null && $country !== '' ? $country : 'All countries',
            'from' => $fromDate,
            'until' => $untilDate,
            'as_of' => $asOfDate->toDateString(),
            'invoice_count' => $invoiceCount,
            'paid_count' => $paidCount,
            'partly_paid_count' => $partlyPaidCount,
            'unpaid_count' => $unpaidCount,
            'paid_total' => round($paidTotal, 2),
            'partly_paid_total' => round($partlyPaidTotal, 2),
            'unpaid_total' => round($unpaidTotal, 2),
            'outstanding_total' => round($outstandingTotal, 2),
            'grand_total' => $grandTotal,
            'days' => $days,
        ];
    }

    public static function formatEuro(float $value): string
    {
        return number_format($value, 2, '.', '').' €';
    }

    public static function daysLate(Invoice $invoice, ?Carbon $asOf = null): int
    {
        if ($invoice->isFullyPaid()) {
            return 0;
        }

        $due = $invoice->dueDate();
        if ($due === null) {
            return 0;
        }

        $asOfDate = ($asOf ?? now())->copy()->startOfDay();
        $dueDay = $due->copy()->startOfDay();

        if ($dueDay->gte($asOfDate)) {
            return 0;
        }

        return (int) $dueDay->diffInDays($asOfDate);
    }

    private static function contactLabel(?int $contactId): string
    {
        if ($contactId === null || $contactId < 1) {
            return 'All counterparts';
        }

        $name = Contact::query()->whereKey($contactId)->value('company_name');

        return filled($name) ? (string) $name : 'Counterpart #'.$contactId;
    }

    private static function asOf(mixed $value): Carbon
    {
        try {
            if ($value !== null && $value !== '') {
                return Carbon::parse((string) $value)->startOfDay();
            }
        } catch (Throwable) {
            // fall through
        }

        return now()->copy()->startOfDay();
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private static function periodLabel(?string $from, ?string $until): string
    {
        if ($from !== null && $until !== null) {
            return $from.' → '.$until;
        }

        if ($from !== null) {
            return 'From '.$from;
        }

        if ($until !== null) {
            return 'Until '.$until;
        }

        return 'All dates';
    }
}
