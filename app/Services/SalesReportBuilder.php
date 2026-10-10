<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class SalesReportBuilder
{
    public const METRIC_SUM = 'sum';

    public const METRIC_METERS = 'meters';

    public const CATALOG_KEY = 'catalog';

    public const OTHER_KEY = 'other';

    /**
     * @return list<OrderStatus>
     */
    public static function includedStatuses(): array
    {
        return [
            OrderStatus::Placed,
            OrderStatus::Approved,
            OrderStatus::Shipped,
            OrderStatus::Completed,
        ];
    }

    public static function normalizeMetric(mixed $metric): string
    {
        return (string) $metric === self::METRIC_METERS
            ? self::METRIC_METERS
            : self::METRIC_SUM;
    }

    public static function customerCode(?User $user): string
    {
        if ($user === null) {
            return '—';
        }

        $code = trim((string) ($user->company_code ?? ''));
        if ($code !== '') {
            return $code;
        }

        $source = trim((string) ($user->company_name ?: $user->displayName()));
        $letters = preg_replace('/[^A-Za-z0-9]+/u', '', Str::ascii(Str::upper($source))) ?? '';

        return $letters !== '' ? Str::substr($letters, 0, 16) : 'CUST-'.$user->getKey();
    }

    public static function customerName(?User $user): string
    {
        if ($user === null) {
            return 'Unknown customer';
        }

        $company = trim((string) ($user->company_name ?? ''));
        if ($company !== '') {
            return $company;
        }

        $name = $user->displayName();

        return $name !== '' ? $name : 'Customer #'.$user->getKey();
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     collections: list<array{key: string, label: string}>,
     *     rows: list<array{customer_id: int, company: string, code: string, values: array<string, float>, total: float}>,
     *     column_totals: array<string, float>,
     *     grand_total: float,
     *     customers: int,
     *     orders: int
     * }
     */
    public static function build(mixed $from = null, mixed $until = null, mixed $metric = self::METRIC_SUM): array
    {
        $metric = self::normalizeMetric($metric);
        $fromDate = self::dateLabel($from);
        $untilDate = self::dateLabel($until);
        $orders = self::ordersQuery($fromDate, $untilDate)->get();

        $collectionMeta = [];
        $customers = [];

        foreach ($orders as $order) {
            $user = $order->user;
            $customerId = (int) ($order->user_id ?? 0);
            if ($customerId < 1) {
                continue;
            }

            if (! isset($customers[$customerId])) {
                $customers[$customerId] = [
                    'customer_id' => $customerId,
                    'company' => self::customerName($user),
                    'code' => self::customerCode($user),
                    'values' => [],
                    'total' => 0.0,
                ];
            }

            foreach (OrderResource::orderLineItemsSummary($order) as $line) {
                [$key, $label] = self::collectionFromLine($line);
                $collectionMeta[$key] = $label;

                $value = self::lineValue($line, $metric);

                $customers[$customerId]['values'][$key] = ($customers[$customerId]['values'][$key] ?? 0.0) + $value;
                $customers[$customerId]['total'] += $value;
            }
        }

        $collections = self::sortedCollections($collectionMeta);
        $columnTotals = [];
        foreach ($collections as $collection) {
            $columnTotals[$collection['key']] = 0.0;
        }

        $rows = array_values($customers);
        usort($rows, fn (array $left, array $right): int => strcasecmp($left['company'], $right['company']));

        foreach ($rows as &$row) {
            $rowTotal = 0.0;
            $normalized = [];
            foreach ($collections as $collection) {
                $amount = round((float) ($row['values'][$collection['key']] ?? 0), 2);
                $normalized[$collection['key']] = $amount;
                $columnTotals[$collection['key']] = round($columnTotals[$collection['key']] + $amount, 2);
                $rowTotal += $amount;
            }
            $row['values'] = $normalized;
            $row['total'] = round($rowTotal, 2);
        }
        unset($row);

        $grandTotal = round(array_sum($columnTotals), 2);

        return [
            'metric' => $metric,
            'metric_label' => $metric === self::METRIC_METERS ? 'Meters' : 'Total sum',
            'title' => $metric === self::METRIC_METERS
                ? 'Sales, m: companies × collections'
                : 'Sales without VAT, EUR: companies × collections',
            'period_label' => self::periodLabel($fromDate, $untilDate),
            'from' => $fromDate,
            'until' => $untilDate,
            'collections' => $collections,
            'rows' => $rows,
            'column_totals' => $columnTotals,
            'grand_total' => $grandTotal,
            'customers' => count($rows),
            'orders' => $orders->count(),
        ];
    }

    public static function formatValue(float $value, string $metric = self::METRIC_SUM): string
    {
        if (abs($value) < 0.005) {
            return '—';
        }

        $formatted = number_format($value, 2, '.', ' ');

        return $formatted;
    }

    public static function ordersQuery(?string $from, ?string $until): Builder
    {
        $query = Order::query()
            ->with(['user', 'orderItems.product.productType', 'orderItems.product.color.collection'])
            ->whereIn('status', array_map(
                fn (OrderStatus $status): string => $status->value,
                self::includedStatuses(),
            ));

        if (filled($from)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) >= ?', [$from]);
        }

        if (filled($until)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) <= ?', [$until]);
        }

        return $query->orderBy('id');
    }

    /**
     * @param  array{collection: string, size: string, amount: int, line_total: float}  $line
     * @return array{0: string, 1: string}
     */
    private static function collectionFromLine(array $line): array
    {
        $label = trim((string) ($line['collection'] ?? ''));
        if ($label === '' || $label === '—') {
            return [self::OTHER_KEY, 'Other'];
        }

        if (! is_numeric($line['size'] ?? null)) {
            return [self::CATALOG_KEY, $label];
        }

        return ['c:'.Str::slug($label), $label];
    }

    /**
     * @param  array{size?: string, amount?: int, line_total?: float}  $line
     */
    public static function lineValue(array $line, string $metric): float
    {
        if (self::normalizeMetric($metric) === self::METRIC_METERS) {
            return self::lineMeters($line);
        }

        return round((float) ($line['line_total'] ?? 0), 2);
    }

    /**
     * @param  array{size?: string, amount?: int}  $line
     */
    public static function lineMeters(array $line): float
    {
        $size = $line['size'] ?? null;
        if (! is_numeric($size)) {
            return 0.0;
        }

        return round(((float) $size) * max(0, (int) ($line['amount'] ?? 0)), 2);
    }

    /**
     * @param  array<string, string>  $meta
     * @return list<array{key: string, label: string}>
     */
    private static function sortedCollections(array $meta): array
    {
        $collections = [];
        foreach ($meta as $key => $label) {
            $collections[] = [
                'key' => (string) $key,
                'label' => $label,
            ];
        }

        usort($collections, function (array $left, array $right): int {
            $rank = static fn (string $key): int => match ($key) {
                self::OTHER_KEY => 2,
                self::CATALOG_KEY => 1,
                default => 0,
            };

            $compared = $rank($left['key']) <=> $rank($right['key']);

            return $compared !== 0 ? $compared : strcasecmp($left['label'], $right['label']);
        });

        return $collections;
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

        return 'All dates';
    }
}
