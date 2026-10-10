<?php

namespace App\Services;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

final class BankPaymentsChartReportBuilder
{
    public const METRIC_SUM = 'sum';

    public const METRIC_COUNT = 'count';

    public const DIRECTION_ALL = 'all';

    public const DIRECTION_INCOME = 'income';

    public const DIRECTION_OUTCOME = 'outcome';

    public static function normalizeMetric(mixed $metric): string
    {
        return (string) $metric === self::METRIC_COUNT
            ? self::METRIC_COUNT
            : self::METRIC_SUM;
    }

    public static function normalizeDirection(mixed $direction): string
    {
        return match ((string) $direction) {
            self::DIRECTION_INCOME => self::DIRECTION_INCOME,
            self::DIRECTION_OUTCOME => self::DIRECTION_OUTCOME,
            default => self::DIRECTION_ALL,
        };
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     direction: string,
     *     direction_label: string,
     *     title: string,
     *     unit_label: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     days: list<array{day: string, label: string, value: float}>,
     *     transactions: int,
     *     income_total: float,
     *     outcome_total: float,
     *     net_total: float,
     *     grand_total: float
     * }
     */
    public static function build(
        mixed $from = null,
        mixed $until = null,
        mixed $metric = self::METRIC_SUM,
        mixed $direction = self::DIRECTION_ALL,
    ): array {
        $metric = self::normalizeMetric($metric);
        $direction = self::normalizeDirection($direction);
        $fromDate = self::normalizeDate($from);
        $untilDate = self::normalizeDate($until);

        if ($fromDate !== null && $untilDate !== null && $fromDate > $untilDate) {
            [$fromDate, $untilDate] = [$untilDate, $fromDate];
        }

        $query = BankStatementTransaction::query()
            ->whereNotNull('booking_date');

        if ($fromDate !== null) {
            $query->whereDate('booking_date', '>=', $fromDate);
        }

        if ($untilDate !== null) {
            $query->whereDate('booking_date', '<=', $untilDate);
        }

        self::applyDirectionFilter($query, $direction);

        $transactions = $query
            ->orderBy('booking_date')
            ->get(['booking_date', 'direction', 'amount']);

        $byDay = [];
        $incomeTotal = 0.0;
        $outcomeTotal = 0.0;

        foreach ($transactions as $transaction) {
            $day = $transaction->booking_date instanceof Carbon
                ? $transaction->booking_date->toDateString()
                : Carbon::parse((string) $transaction->booking_date)->toDateString();

            if (! isset($byDay[$day])) {
                $byDay[$day] = [
                    'sum' => 0.0,
                    'count' => 0,
                ];
            }

            $amount = (float) $transaction->amount;
            $isIncome = $transaction->direction === BankStatementDirection::Credit;

            if ($isIncome) {
                $incomeTotal += $amount;
            } else {
                $outcomeTotal += $amount;
            }

            $byDay[$day]['sum'] += $amount;
            $byDay[$day]['count']++;
        }

        ksort($byDay);

        $days = [];
        $grandTotal = 0.0;

        foreach ($byDay as $day => $bucket) {
            $value = $metric === self::METRIC_COUNT
                ? (float) $bucket['count']
                : round($bucket['sum'], 2);

            $grandTotal += $value;
            $days[] = [
                'day' => $day,
                'label' => Carbon::parse($day)->format('Y-m-d'),
                'value' => $value,
            ];
        }

        $netTotal = round($incomeTotal - $outcomeTotal, 2);

        return [
            'metric' => $metric,
            'metric_label' => $metric === self::METRIC_COUNT ? 'Count' : 'Sum',
            'direction' => $direction,
            'direction_label' => match ($direction) {
                self::DIRECTION_INCOME => 'Income',
                self::DIRECTION_OUTCOME => 'Outcome',
                default => 'All directions',
            },
            'title' => $metric === self::METRIC_COUNT
                ? 'Bank payments by day (count)'
                : 'Bank payments by day (sum)',
            'unit_label' => $metric === self::METRIC_COUNT ? 'txns' : '€',
            'period_label' => self::periodLabel($fromDate, $untilDate),
            'from' => $fromDate,
            'until' => $untilDate,
            'days' => $days,
            'transactions' => $transactions->count(),
            'income_total' => round($incomeTotal, 2),
            'outcome_total' => round($outcomeTotal, 2),
            'net_total' => $netTotal,
            'grand_total' => $metric === self::METRIC_COUNT
                ? (float) $transactions->count()
                : round($grandTotal, 2),
        ];
    }

    public static function formatValue(float $value, string $metric = self::METRIC_SUM): string
    {
        $metric = self::normalizeMetric($metric);

        if ($metric === self::METRIC_COUNT) {
            return number_format((int) round($value));
        }

        return number_format($value, 2, '.', '').' €';
    }

    private static function applyDirectionFilter(Builder $query, string $direction): void
    {
        if ($direction === self::DIRECTION_INCOME) {
            $query->where('direction', BankStatementDirection::Credit);

            return;
        }

        if ($direction === self::DIRECTION_OUTCOME) {
            $query->where('direction', BankStatementDirection::Debit);
        }
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
