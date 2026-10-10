<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Throwable;

final class OrdersChartReportBuilder
{
    public const METRIC_METERS = 'meters';

    public const METRIC_ROLLS = 'rolls';

    /**
     * @return list<OrderStatus>
     */
    public static function includedStatuses(): array
    {
        return SalesReportBuilder::includedStatuses();
    }

    public static function normalizeMetric(mixed $metric): string
    {
        return (string) $metric === self::METRIC_ROLLS
            ? self::METRIC_ROLLS
            : self::METRIC_METERS;
    }

    /**
     * @param  list<int|string>  $customerIds
     * @param  list<string>  $countries
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     unit_label: string,
     *     period_label: string,
     *     from: ?string,
     *     until: ?string,
     *     collections: list<array{
     *         id: int,
     *         label: string,
     *         total: float,
     *         colors: list<array{id: int, label: string, code: string, hex: string|null, stock: float}>
     *     }>,
     *     colors: int,
     *     orders: int,
     *     grand_total: float
     * }
     */
    public static function build(
        mixed $from = null,
        mixed $until = null,
        mixed $metric = self::METRIC_METERS,
        array $customerIds = [],
        array $countries = [],
    ): array {
        $metric = self::normalizeMetric($metric);
        $fromDate = self::dateLabel($from);
        $untilDate = self::dateLabel($until);
        $customerIds = self::normalizeIds($customerIds);
        $countries = self::normalizeCountries($countries);

        $orders = self::ordersQuery($fromDate, $untilDate, $customerIds, $countries)->get();

        /** @var array<int, array{id: int, label: string, colors: array<int, array{id: int, label: string, code: string, hex: string|null, stock: float}>}> $collections */
        $collections = [];

        foreach ($orders as $order) {
            $order->loadMissing(['orderItems.product.productType', 'orderItems.product.color.collection']);

            foreach ($order->orderItems as $item) {
                $product = $item->product;

                if ($product === null || $product->isCatalog()) {
                    continue;
                }

                $color = $product->color;
                $collection = $color?->collection;

                if ($color === null || $collection === null) {
                    continue;
                }

                $amount = max(0, (int) $item->amount);
                if ($amount <= 0) {
                    continue;
                }

                $value = $metric === self::METRIC_ROLLS
                    ? (float) $amount
                    : (float) (is_numeric($product->name) ? ((float) $product->name * $amount) : 0);

                if ($value <= 0) {
                    continue;
                }

                $collectionId = (int) $collection->getKey();
                $colorId = (int) $color->getKey();

                if (! isset($collections[$collectionId])) {
                    $collections[$collectionId] = [
                        'id' => $collectionId,
                        'label' => (string) $collection->name,
                        'colors' => [],
                    ];
                }

                if (! isset($collections[$collectionId]['colors'][$colorId])) {
                    $colorName = trim((string) ($color->color_name ?? ''));
                    $colorCode = trim((string) ($color->color_code ?? ''));

                    $collections[$collectionId]['colors'][$colorId] = [
                        'id' => $colorId,
                        'label' => $colorName !== '' ? $colorName : ($colorCode !== '' ? $colorCode : 'Color #'.$colorId),
                        'code' => $colorCode,
                        'hex' => $color->normalizedHex(),
                        'stock' => 0.0,
                    ];
                }

                $collections[$collectionId]['colors'][$colorId]['stock'] += $value;
            }
        }

        $rows = [];
        $grandTotal = 0.0;
        $colorCount = 0;

        foreach ($collections as $collection) {
            $colors = array_values($collection['colors']);
            usort(
                $colors,
                fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']),
            );

            $colors = array_map(function (array $color): array {
                $color['stock'] = round((float) $color['stock'], 2);

                return $color;
            }, $colors);

            if ($colors === []) {
                continue;
            }

            $total = round(array_sum(array_column($colors, 'stock')), 2);
            $grandTotal += $total;
            $colorCount += count($colors);

            $rows[] = [
                'id' => $collection['id'],
                'label' => $collection['label'],
                'total' => $total,
                'colors' => $colors,
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']),
        );

        return [
            'metric' => $metric,
            'metric_label' => $metric === self::METRIC_ROLLS ? 'Rolls' : 'Meters',
            'title' => $metric === self::METRIC_ROLLS
                ? 'Sold stock by color (rolls)'
                : 'Sold stock by color (meters)',
            'unit_label' => $metric === self::METRIC_ROLLS ? 'rolls' : 'm',
            'period_label' => self::periodLabel($fromDate, $untilDate),
            'from' => $fromDate,
            'until' => $untilDate,
            'collections' => $rows,
            'colors' => $colorCount,
            'orders' => $orders->count(),
            'grand_total' => round($grandTotal, 2),
        ];
    }

    public static function formatValue(float $value, string $metric = self::METRIC_METERS): string
    {
        return StockChartReportBuilder::formatValue($value, self::normalizeMetric($metric));
    }

    /**
     * @return array<int, string>
     */
    public static function customerOptions(): array
    {
        if (! self::customerRoleExists()) {
            return [];
        }

        return User::query()
            ->role('customer')
            ->orderBy('company_name')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user): array => [
                $user->getKey() => SalesReportBuilder::customerName($user),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function countryOptions(): array
    {
        if (! self::customerRoleExists()) {
            return [];
        }

        $countries = User::query()
            ->role('customer')
            ->get(['company_country', 'company_address'])
            ->map(fn (User $user): string => self::customerCountry($user))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return $countries
            ->mapWithKeys(fn (string $country): array => [$country => $country])
            ->all();
    }

    public static function customerCountry(?User $user): string
    {
        if ($user === null) {
            return 'Unknown';
        }

        $country = trim((string) ($user->company_country ?? ''));
        if ($country !== '') {
            return $country;
        }

        return self::extractCountryFromAddress((string) ($user->company_address ?? ''));
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<string>  $countries
     */
    public static function ordersQuery(
        ?string $from,
        ?string $until,
        array $customerIds = [],
        array $countries = [],
    ): Builder {
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

        if ($customerIds !== []) {
            $query->whereIn('user_id', $customerIds);
        }

        if ($countries !== []) {
            if (! self::customerRoleExists()) {
                $query->whereRaw('1 = 0');

                return $query->orderBy('id');
            }

            $countryUserIds = User::query()
                ->role('customer')
                ->when($customerIds !== [], fn (Builder $builder) => $builder->whereKey($customerIds))
                ->get(['id', 'company_country', 'company_address'])
                ->filter(fn (User $user): bool => in_array(self::customerCountry($user), $countries, true))
                ->modelKeys();

            $query->whereIn('user_id', $countryUserIds !== [] ? $countryUserIds : [0]);
        }

        return $query->orderBy('id');
    }

    private static function customerRoleExists(): bool
    {
        return Role::query()
            ->where('name', 'customer')
            ->where('guard_name', 'web')
            ->exists();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  list<string>  $countries
     * @return list<string>
     */
    private static function normalizeCountries(array $countries): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($country): string => trim((string) $country), $countries),
            static fn (string $country): bool => $country !== '',
        )));
    }

    private static function dateLabel(mixed $value): ?string
    {
        if (! filled($value)) {
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

    private static function extractCountryFromAddress(string $address): string
    {
        if ($address === '') {
            return 'Unknown';
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $address) ?: [])));
        if ($parts === []) {
            return 'Unknown';
        }

        $candidate = end($parts);

        return $candidate !== false && $candidate !== '' ? $candidate : 'Unknown';
    }
}
