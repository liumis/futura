<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Throwable;

final class LatePaymentsReportBuilder
{
    public const SOURCE_ALL = 'all';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_CONTRACT = 'contract';

    public const STATUS_OUTSTANDING = 'outstanding';

    public const STATUS_LATE = 'late';

    /**
     * @return array{
     *     title: string,
     *     period_label: string,
     *     source: string,
     *     source_label: string,
     *     status: string,
     *     status_label: string,
     *     from: ?string,
     *     until: ?string,
     *     as_of: string,
     *     rows: list<array{
     *         key: string,
     *         source: string,
     *         source_label: string,
     *         record_id: int,
     *         number: string,
     *         date: ?string,
     *         company: string,
     *         due_date: ?string,
     *         amount: float,
     *         late: bool
     *     }>,
     *     grand_total: float,
     *     late_total: float,
     *     count: int,
     *     late_count: int,
     *     invoice_count: int,
     *     contract_count: int
     * }
     */
    public static function build(
        mixed $from = null,
        mixed $until = null,
        mixed $source = self::SOURCE_ALL,
        mixed $status = self::STATUS_OUTSTANDING,
        mixed $asOf = null,
    ): array {
        $fromDate = self::dateLabel($from);
        $untilDate = self::dateLabel($until);
        $source = self::normalizeSource($source);
        $status = self::normalizeStatus($status);
        $asOfDate = self::asOf($asOf);
        $fromCarbon = $fromDate !== null ? Carbon::parse($fromDate)->startOfDay() : null;
        $untilCarbon = $untilDate !== null ? Carbon::parse($untilDate)->startOfDay() : null;

        $rows = [];

        if ($source !== self::SOURCE_CONTRACT) {
            $rows = array_merge($rows, self::invoiceRows($fromCarbon, $untilCarbon, $asOfDate));
        }

        if ($source !== self::SOURCE_INVOICE) {
            $rows = array_merge($rows, self::contractRows($fromCarbon, $untilCarbon, $asOfDate));
        }

        if ($status === self::STATUS_LATE) {
            $rows = array_values(array_filter($rows, fn (array $row): bool => $row['late']));
        }

        usort($rows, function (array $left, array $right): int {
            $due = strcmp((string) ($left['due_date'] ?? '9999-12-31'), (string) ($right['due_date'] ?? '9999-12-31'));
            if ($due !== 0) {
                return $due;
            }

            $date = strcmp((string) ($left['date'] ?? ''), (string) ($right['date'] ?? ''));
            if ($date !== 0) {
                return $date;
            }

            return strcasecmp($left['number'], $right['number']);
        });

        $grandTotal = 0.0;
        $lateTotal = 0.0;
        $lateCount = 0;
        $invoiceCount = 0;
        $contractCount = 0;

        foreach ($rows as $row) {
            $grandTotal += $row['amount'];
            if ($row['late']) {
                $lateTotal += $row['amount'];
                $lateCount++;
            }
            if ($row['source'] === self::SOURCE_INVOICE) {
                $invoiceCount++;
            } else {
                $contractCount++;
            }
        }

        return [
            'title' => 'Late payments',
            'period_label' => self::periodLabel($fromDate, $untilDate),
            'source' => $source,
            'source_label' => self::sourceLabel($source),
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'from' => $fromDate,
            'until' => $untilDate,
            'as_of' => $asOfDate->toDateString(),
            'rows' => $rows,
            'grand_total' => round($grandTotal, 2),
            'late_total' => round($lateTotal, 2),
            'count' => count($rows),
            'late_count' => $lateCount,
            'invoice_count' => $invoiceCount,
            'contract_count' => $contractCount,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        return [
            self::SOURCE_ALL => 'Invoices and contracts',
            self::SOURCE_INVOICE => 'Invoices',
            self::SOURCE_CONTRACT => 'Contracts',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_OUTSTANDING => 'Unpaid',
            self::STATUS_LATE => 'Late only',
        ];
    }

    public static function sourceLabel(string $source): string
    {
        return self::sourceOptions()[$source] ?? self::sourceOptions()[self::SOURCE_ALL];
    }

    public static function statusLabel(string $status): string
    {
        return self::statusOptions()[$status] ?? self::statusOptions()[self::STATUS_OUTSTANDING];
    }

    public static function formatDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        try {
            return Carbon::parse($value)->format('j M Y');
        } catch (Throwable) {
            return $value;
        }
    }

    public static function dateLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return trim((string) $value) ?: null;
        }
    }

    public static function periodLabel(?string $from, ?string $until): string
    {
        if (filled($from) && filled($until)) {
            return $from.' — '.$until;
        }

        if (filled($from)) {
            return 'From '.$from;
        }

        if (filled($until)) {
            return 'Until '.$until;
        }

        return 'All unpaid';
    }

    public static function money(float $amount): string
    {
        return Money::format($amount);
    }

    private static function normalizeSource(mixed $source): string
    {
        $value = is_string($source) ? $source : self::SOURCE_ALL;

        return array_key_exists($value, self::sourceOptions()) ? $value : self::SOURCE_ALL;
    }

    private static function normalizeStatus(mixed $status): string
    {
        $value = is_string($status) ? $status : self::STATUS_OUTSTANDING;

        return array_key_exists($value, self::statusOptions()) ? $value : self::STATUS_OUTSTANDING;
    }

    private static function asOf(mixed $asOf): Carbon
    {
        if ($asOf instanceof Carbon) {
            return $asOf->copy()->startOfDay();
        }

        if (filled($asOf)) {
            try {
                return Carbon::parse((string) $asOf)->startOfDay();
            } catch (Throwable) {
            }
        }

        return now()->copy()->startOfDay();
    }

    /**
     * @return list<array{
     *     key: string,
     *     source: string,
     *     source_label: string,
     *     record_id: int,
     *     number: string,
     *     date: ?string,
     *     company: string,
     *     due_date: ?string,
     *     amount: float,
     *     late: bool
     * }>
     */
    private static function invoiceRows(?Carbon $from, ?Carbon $until, Carbon $asOf): array
    {
        $invoices = Invoice::query()
            ->with('contact')
            ->where('paid', false)
            ->get();

        $rows = [];

        foreach ($invoices as $invoice) {
            $amount = $invoice->outstandingAmount();
            if ($amount <= 0.005) {
                continue;
            }

            $due = $invoice->dueDate();
            $filterDate = $invoice->invoice_date?->copy()->startOfDay() ?? $due;
            if (! self::inRange($filterDate, $from, $until)) {
                continue;
            }

            $rows[] = [
                'key' => 'invoice-'.$invoice->getKey(),
                'source' => self::SOURCE_INVOICE,
                'source_label' => 'Invoice',
                'record_id' => (int) $invoice->getKey(),
                'number' => $invoice->displayNumber(),
                'date' => $invoice->invoice_date?->toDateString(),
                'company' => $invoice->contact?->company_name ?: '—',
                'due_date' => $due?->toDateString(),
                'amount' => $amount,
                'late' => $invoice->isOverdue($asOf),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{
     *     key: string,
     *     source: string,
     *     source_label: string,
     *     record_id: int,
     *     number: string,
     *     date: ?string,
     *     company: string,
     *     due_date: ?string,
     *     amount: float,
     *     late: bool
     * }>
     */
    private static function contractRows(?Carbon $from, ?Carbon $until, Carbon $asOf): array
    {
        $payments = Payment::query()
            ->with('contact')
            ->get();

        $rows = [];

        foreach ($payments as $payment) {
            if ($payment->isOneTime() && (bool) $payment->paid) {
                continue;
            }

            $amount = round((float) $payment->amount, 2);
            if ($amount <= 0.005) {
                continue;
            }

            foreach (self::contractOccurrenceDates($payment, $from, $until, $asOf) as $due) {
                $rows[] = [
                    'key' => 'payment-'.$payment->getKey().'-'.$due->toDateString(),
                    'source' => self::SOURCE_CONTRACT,
                    'source_label' => 'Contract',
                    'record_id' => (int) $payment->getKey(),
                    'number' => (string) $payment->name,
                    'date' => $payment->isOneTime()
                        ? $payment->due_date?->toDateString()
                        : $payment->start_date?->toDateString(),
                    'company' => $payment->contact?->company_name ?: '—',
                    'due_date' => $due->toDateString(),
                    'amount' => $amount,
                    'late' => $due->lt($asOf),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<Carbon>
     */
    private static function contractOccurrenceDates(Payment $payment, ?Carbon $from, ?Carbon $until, Carbon $asOf): array
    {
        if ($payment->isOneTime()) {
            return $payment->occurrences($from, $until);
        }

        $horizon = $until ?? $asOf->copy()->endOfMonth()->startOfDay();
        $all = $payment->occurrences(null, $horizon);
        $dates = [];
        $cap = $until !== null && $until->lt($asOf) ? $until : $asOf;

        $dueOrPast = array_values(array_filter(
            $all,
            fn (Carbon $date): bool => ! $date->gt($cap),
        ));

        if ($dueOrPast !== []) {
            $current = $dueOrPast[array_key_last($dueOrPast)];
            if ($until === null || ! $current->gt($until)) {
                $dates[] = $current;
            }
        }

        foreach ($all as $date) {
            if ($date->gt($asOf) && self::inRange($date, $from, $until)) {
                $dates[] = $date;
            }
        }

        return $dates;
    }

    private static function inRange(?Carbon $date, ?Carbon $from, ?Carbon $until): bool
    {
        if ($date === null) {
            return $from === null && $until === null;
        }

        if ($from !== null && $date->lt($from)) {
            return false;
        }

        if ($until !== null && $date->gt($until)) {
            return false;
        }

        return true;
    }
}
