<?php

namespace App\Services;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use Illuminate\Support\Carbon;

final class CustomerReportBuilder
{
    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     months: list<array{key: string, label: string}>,
     *     rows: list<array{customer_id: int, company: string, code: string, values: array<string, float>, total: float}>,
     *     column_totals: array<string, float>,
     *     grand_total: float,
     *     customers: int,
     *     orders: int
     * }
     */
    public static function build(mixed $from = null, mixed $until = null, mixed $metric = SalesReportBuilder::METRIC_SUM): array
    {
        $metric = SalesReportBuilder::normalizeMetric($metric);
        $fromDate = SalesReportBuilder::dateLabel($from);
        $untilDate = SalesReportBuilder::dateLabel($until);
        $orders = SalesReportBuilder::ordersQuery($fromDate, $untilDate)->get();
        $months = self::monthColumns($fromDate, $untilDate, $orders);

        $customers = [];

        foreach ($orders as $order) {
            $customerId = (int) ($order->user_id ?? 0);
            if ($customerId < 1) {
                continue;
            }

            $monthKey = self::orderMonthKey($order);
            if ($monthKey === null) {
                continue;
            }

            if (! isset($customers[$customerId])) {
                $user = $order->user;
                $customers[$customerId] = [
                    'customer_id' => $customerId,
                    'company' => SalesReportBuilder::customerName($user),
                    'code' => SalesReportBuilder::customerCode($user),
                    'values' => [],
                    'total' => 0.0,
                ];
            }

            $value = 0.0;
            foreach (OrderResource::orderLineItemsSummary($order) as $line) {
                $value += SalesReportBuilder::lineValue($line, $metric);
            }

            $customers[$customerId]['values'][$monthKey] = ($customers[$customerId]['values'][$monthKey] ?? 0.0) + $value;
            $customers[$customerId]['total'] += $value;
        }

        $columnTotals = [];
        foreach ($months as $month) {
            $columnTotals[$month['key']] = 0.0;
        }

        $rows = array_values($customers);
        usort($rows, fn (array $left, array $right): int => strcasecmp($left['company'], $right['company']));

        foreach ($rows as &$row) {
            $rowTotal = 0.0;
            $normalized = [];
            foreach ($months as $month) {
                $amount = round((float) ($row['values'][$month['key']] ?? 0), 2);
                $normalized[$month['key']] = $amount;
                $columnTotals[$month['key']] = round($columnTotals[$month['key']] + $amount, 2);
                $rowTotal += $amount;
            }
            $row['values'] = $normalized;
            $row['total'] = round($rowTotal, 2);
        }
        unset($row);

        return [
            'metric' => $metric,
            'metric_label' => $metric === SalesReportBuilder::METRIC_METERS ? 'Meters' : 'Total sum',
            'title' => $metric === SalesReportBuilder::METRIC_METERS
                ? 'Quantity, m: customers × months'
                : 'Sales without VAT, EUR: customers × months',
            'period_label' => SalesReportBuilder::periodLabel($fromDate, $untilDate),
            'from' => $fromDate,
            'until' => $untilDate,
            'months' => $months,
            'rows' => $rows,
            'column_totals' => $columnTotals,
            'grand_total' => round(array_sum($columnTotals), 2),
            'customers' => count($rows),
            'orders' => $orders->count(),
        ];
    }

    /**
     * @param  iterable<int, Order>  $orders
     * @return list<array{key: string, label: string}>
     */
    private static function monthColumns(?string $from, ?string $until, iterable $orders): array
    {
        $start = filled($from) ? Carbon::parse($from)->startOfMonth() : null;
        $end = filled($until) ? Carbon::parse($until)->startOfMonth() : null;

        if ($start === null || $end === null) {
            foreach ($orders as $order) {
                $key = self::orderMonthKey($order);
                if ($key === null) {
                    continue;
                }

                $month = Carbon::createFromFormat('Y-m', $key)?->startOfMonth();
                if ($month === null) {
                    continue;
                }

                if ($start === null || $month->lt($start)) {
                    $start = $month->copy();
                }
                if ($end === null || $month->gt($end)) {
                    $end = $month->copy();
                }
            }
        }

        if ($start === null) {
            $start = now()->startOfMonth();
            $end = now()->startOfMonth();
        }

        if ($end === null || $end->lt($start)) {
            $end = $start->copy();
        }

        $months = [];
        $cursor = $start->copy();
        $guard = 0;

        while ($cursor->lte($end) && $guard < 60) {
            $key = $cursor->format('Y-m');
            $months[] = [
                'key' => $key,
                'label' => $key,
            ];
            $cursor->addMonth();
            $guard++;
        }

        return $months;
    }

    private static function orderMonthKey(Order $order): ?string
    {
        $date = $order->order_date ?? $order->created_at;
        if ($date === null) {
            return null;
        }

        return Carbon::parse($date)->format('Y-m');
    }
}
