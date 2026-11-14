<?php

namespace App\Services;

use App\Enums\CargoStatus;
use App\Enums\OrderStatus;
use App\Models\CargoItem;
use App\Models\Color;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ForecastReportBuilder
{
    public const DEFAULT_LOOKBACK_DAYS = 90;

    public const DEFAULT_HORIZON_DAYS = 60;

    public const STANDARD_ROLL_METERS = 30.0;

    /**
     * Company-name needles for customers whose demand must never under-forecast.
     *
     * @var list<string>
     */
    public const STABLE_CUSTOMER_NEEDLES = [
        'bikinis',
        'biskinis',
    ];

    /**
     * @param  list<int|string>  $collectionIds
     * @param  list<int|string>  $customerIds
     * @return array{
     *     lookback_days: int,
     *     horizon_days: int,
     *     lookback_from: string,
     *     lookback_until: string,
     *     horizon_until: string,
     *     only_needs_order: bool,
     *     stable_customers: list<array{id: int, label: string}>,
     *     rows: list<array{
     *         collection_id: int,
     *         collection: string,
     *         color_id: int,
     *         color_code: string,
     *         color_name: string,
     *         sold_m: float,
     *         expected_m: float,
     *         stable_floor_m: float,
     *         recommended_m: float,
     *         cover_m: float,
     *         on_hand_m: float,
     *         inbound_m: float,
     *         buy_m: float,
     *         buy_rolls: int,
     *         default_order_rolls: int,
     *         product_id: ?int,
     *         stable: bool
     *     }>,
     *     summary: array{
     *         colors: int,
     *         needs_order: int,
     *         buy_m: float,
     *         buy_rolls: int,
     *         sold_m: float,
     *         stable_colors: int
     *     }
     * }
     */
    public static function build(
        int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS,
        int $horizonDays = self::DEFAULT_HORIZON_DAYS,
        array $collectionIds = [],
        array $customerIds = [],
        bool $onlyNeedsOrder = true,
    ): array {
        $lookbackDays = max(7, $lookbackDays);
        $horizonDays = max(7, $horizonDays);
        $until = Carbon::today();
        $from = $until->copy()->subDays($lookbackDays - 1)->startOfDay();
        $collectionIds = self::normalizeIds($collectionIds);
        $customerIds = self::normalizeIds($customerIds);

        $soldByColor = self::soldMetersByColor($from, $until, $customerIds, $collectionIds);
        $stableCustomers = self::stableCustomers($customerIds);
        $stableFloors = self::stableFloorsByColor($stableCustomers, $horizonDays, $collectionIds);
        $onHandByColor = self::onHandMetersByColor($collectionIds);
        $inboundByColor = self::inboundMetersByColor($collectionIds);
        $colorMeta = self::colorMeta(array_values(array_unique(array_merge(
            array_keys($soldByColor),
            array_keys($stableFloors),
            array_keys($onHandByColor),
            array_keys($inboundByColor),
        ))), $collectionIds);

        $rows = [];

        foreach ($colorMeta as $colorId => $meta) {
            $soldM = round((float) ($soldByColor[$colorId] ?? 0), 2);
            $expectedM = round(($soldM / $lookbackDays) * $horizonDays, 2);
            $stableFloorM = round((float) ($stableFloors[$colorId] ?? 0), 2);
            $recommendedM = (float) max(0, (int) ($meta['recommended_stock'] ?? 0));
            $onHandM = round((float) ($onHandByColor[$colorId] ?? 0), 2);
            $inboundM = round((float) ($inboundByColor[$colorId] ?? 0), 2);
            $coverM = round(max($recommendedM, $expectedM, $stableFloorM), 2);
            $buyM = round(max(0, $coverM - $onHandM - $inboundM), 2);
            $buyRolls = $buyM > 0
                ? (int) max(1, (int) ceil($buyM / self::STANDARD_ROLL_METERS))
                : 0;
            $defaultOrder = max(0, (int) ($meta['default_order'] ?? 0));
            if ($buyM > 0 && $defaultOrder > $buyRolls) {
                $buyRolls = $defaultOrder;
                $buyM = round($buyRolls * self::STANDARD_ROLL_METERS, 2);
            }

            $hasActivity = $soldM > 0 || $stableFloorM > 0 || $buyM > 0;
            if (! $hasActivity) {
                continue;
            }
            if ($onlyNeedsOrder && $buyM <= 0) {
                continue;
            }

            $rows[] = [
                'collection_id' => (int) $meta['collection_id'],
                'collection' => (string) $meta['collection'],
                'color_id' => $colorId,
                'color_code' => (string) $meta['color_code'],
                'color_name' => (string) $meta['color_name'],
                'sold_m' => $soldM,
                'expected_m' => $expectedM,
                'stable_floor_m' => $stableFloorM,
                'recommended_m' => $recommendedM,
                'cover_m' => $coverM,
                'on_hand_m' => $onHandM,
                'inbound_m' => $inboundM,
                'buy_m' => $buyM,
                'buy_rolls' => $buyRolls,
                'default_order_rolls' => $defaultOrder,
                'product_id' => $meta['product_id'] !== null ? (int) $meta['product_id'] : null,
                'stable' => $stableFloorM > 0,
            ];
        }

        usort($rows, function (array $left, array $right): int {
            return [$right['buy_m'], $right['sold_m'], $left['collection'], $left['color_code']]
                <=> [$left['buy_m'], $left['sold_m'], $right['collection'], $right['color_code']];
        });

        return [
            'lookback_days' => $lookbackDays,
            'horizon_days' => $horizonDays,
            'lookback_from' => $from->toDateString(),
            'lookback_until' => $until->toDateString(),
            'horizon_until' => $until->copy()->addDays($horizonDays)->toDateString(),
            'only_needs_order' => $onlyNeedsOrder,
            'stable_customers' => $stableCustomers
                ->map(fn (User $user): array => [
                    'id' => (int) $user->getKey(),
                    'label' => self::customerLabel($user),
                ])
                ->values()
                ->all(),
            'rows' => $rows,
            'summary' => [
                'colors' => count($rows),
                'needs_order' => count(array_filter($rows, fn (array $row): bool => $row['buy_m'] > 0)),
                'buy_m' => round(array_sum(array_column($rows, 'buy_m')), 2),
                'buy_rolls' => (int) array_sum(array_column($rows, 'buy_rolls')),
                'sold_m' => round(array_sum(array_column($rows, 'sold_m')), 2),
                'stable_colors' => count(array_filter($rows, fn (array $row): bool => $row['stable'])),
            ],
        ];
    }

    public static function formatMeters(float $meters): string
    {
        if (abs($meters) < 0.005) {
            return '—';
        }

        return rtrim(rtrim(number_format($meters, 2, '.', ' '), '0'), '.').' m';
    }

    /**
     * @return Collection<int, User>
     */
    public static function stableCustomers(array $customerIds = []): Collection
    {
        $customerIds = self::normalizeIds($customerIds);

        $query = User::query()->role(User::ROLE_CUSTOMER);

        if ($customerIds !== []) {
            $query->whereIn('id', $customerIds);
        }

        return $query
            ->orderBy('company_name')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => self::isStableCustomer($user))
            ->values();
    }

    public static function isStableCustomer(User $user): bool
    {
        if ((bool) ($user->is_stable_forecast ?? false)) {
            return true;
        }

        $haystack = Str::lower(trim(implode(' ', array_filter([
            (string) ($user->company_name ?? ''),
            (string) ($user->name ?? ''),
            (string) ($user->surname ?? ''),
        ]))));

        if ($haystack === '') {
            return false;
        }

        foreach (self::STABLE_CUSTOMER_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, User>  $stableCustomers
     * @param  list<int>  $collectionIds
     * @return array<int, float> color_id => meters floor for horizon
     */
    private static function stableFloorsByColor(Collection $stableCustomers, int $horizonDays, array $collectionIds): array
    {
        if ($stableCustomers->isEmpty()) {
            return [];
        }

        $floors = [];

        foreach ($stableCustomers as $customer) {
            $history = self::customerColorOrderHistory((int) $customer->getKey(), $collectionIds);

            foreach ($history as $colorId => $batches) {
                if ($batches === []) {
                    continue;
                }

                $medianBatch = self::median(array_column($batches, 'meters'));
                $gapDays = self::averageGapDays(array_column($batches, 'date'));
                $expectedOrders = max(1, (int) ceil($horizonDays / max(30, $gapDays)));
                $floor = $medianBatch * $expectedOrders;
                $floors[$colorId] = max($floors[$colorId] ?? 0, $floor);
            }
        }

        return $floors;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, list<array{date: string, meters: float}>>
     */
    private static function customerColorOrderHistory(int $customerId, array $collectionIds): array
    {
        $sizeExpr = self::productSizeSql('p');
        $dateExpr = self::orderDateSql('o');

        $query = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('colors as c', 'c.id', '=', 'p.color_id')
            ->where('o.user_id', $customerId)
            ->whereIn('o.status', array_map(
                fn (OrderStatus $status): string => $status->value,
                SalesReportBuilder::includedStatuses(),
            ))
            ->whereNotNull('p.color_id')
            ->when($collectionIds !== [], fn ($q) => $q->whereIn('c.collection_id', $collectionIds))
            ->groupBy('oi.order_id', 'p.color_id')
            ->orderBy('oi.order_id')
            ->selectRaw("p.color_id as color_id, MAX({$dateExpr}) as order_day, SUM(oi.amount * {$sizeExpr}) as meters");

        $history = [];

        foreach ($query->get() as $row) {
            $colorId = (int) $row->color_id;
            $meters = (float) $row->meters;
            if ($colorId <= 0 || $meters <= 0) {
                continue;
            }

            $history[$colorId][] = [
                'date' => (string) $row->order_day,
                'meters' => $meters,
            ];
        }

        return $history;
    }

    /**
     * @param  list<int>  $customerIds
     * @param  list<int>  $collectionIds
     * @return array<int, float>
     */
    private static function soldMetersByColor(Carbon $from, Carbon $until, array $customerIds, array $collectionIds): array
    {
        $sizeExpr = self::productSizeSql('p');
        $dateExpr = self::orderDateSql('o');

        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('products as p', 'p.id', '=', 'oi.product_id')
            ->join('colors as c', 'c.id', '=', 'p.color_id')
            ->whereIn('o.status', array_map(
                fn (OrderStatus $status): string => $status->value,
                SalesReportBuilder::includedStatuses(),
            ))
            ->whereNotNull('p.color_id')
            ->whereRaw("{$dateExpr} >= ?", [$from->toDateString()])
            ->whereRaw("{$dateExpr} <= ?", [$until->toDateString()])
            ->when($customerIds !== [], fn ($q) => $q->whereIn('o.user_id', $customerIds))
            ->when($collectionIds !== [], fn ($q) => $q->whereIn('c.collection_id', $collectionIds))
            ->groupBy('p.color_id')
            ->selectRaw("p.color_id as color_id, SUM(oi.amount * {$sizeExpr}) as meters")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->color_id] = (float) $row->meters;
        }

        return $result;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, float>
     */
    private static function onHandMetersByColor(array $collectionIds): array
    {
        $metersSql = Product::stockMetersSqlExpression();
        $leatherKey = \App\Models\ProductType::KEY_ARTIFICIAL_LEATHER;

        $rows = DB::table('products')
            ->join('product_types', 'product_types.id', '=', 'products.product_type_id')
            ->join('colors', 'colors.id', '=', 'products.color_id')
            ->where('product_types.key', $leatherKey)
            ->whereNotNull('products.color_id')
            ->when($collectionIds !== [], fn ($q) => $q->whereIn('colors.collection_id', $collectionIds))
            ->groupBy('products.color_id')
            ->selectRaw("products.color_id as color_id, COALESCE(SUM({$metersSql}), 0) as meters")
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->color_id] = (float) $row->meters;
        }

        return $result;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, float>
     */
    private static function inboundMetersByColor(array $collectionIds): array
    {
        $sizeExpr = self::productSizeSql('products');

        $rows = CargoItem::query()
            ->selectRaw("products.color_id as color_id, SUM(cargo_items.amount * {$sizeExpr}) as meters")
            ->join('cargos', 'cargos.id', '=', 'cargo_items.cargo_id')
            ->join('products', 'products.id', '=', 'cargo_items.product_id')
            ->join('colors', 'colors.id', '=', 'products.color_id')
            ->where('cargos.status', '!=', CargoStatus::Received->value)
            ->whereNotNull('products.color_id')
            ->when($collectionIds !== [], fn (Builder $q) => $q->whereIn('colors.collection_id', $collectionIds))
            ->groupBy('products.color_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->color_id] = (float) $row->meters;
        }

        return $result;
    }

    /**
     * @param  list<int>  $colorIds
     * @param  list<int>  $collectionIds
     * @return array<int, array{collection_id: int, collection: string, color_code: string, color_name: string, recommended_stock: int, default_order: int, product_id: ?int}>
     */
    private static function colorMeta(array $colorIds, array $collectionIds): array
    {
        if ($colorIds === []) {
            return [];
        }

        $colors = Color::query()
            ->with(['collection', 'products.productType'])
            ->whereIn('id', $colorIds)
            ->when($collectionIds !== [], fn (Builder $q) => $q->whereIn('collection_id', $collectionIds))
            ->get();

        $meta = [];

        foreach ($colors as $color) {
            $products = $color->products
                ->filter(fn (Product $product): bool => ! $product->isCatalog())
                ->values();
            $preferred = $products->first(fn (Product $product): bool => abs((float) $product->name - self::STANDARD_ROLL_METERS) < 0.01)
                ?? $products->sortByDesc(fn (Product $product): float => $product->stockMeters())->first();

            $meta[(int) $color->getKey()] = [
                'collection_id' => (int) $color->collection_id,
                'collection' => (string) ($color->collection?->name ?? '—'),
                'color_code' => (string) $color->color_code,
                'color_name' => (string) $color->color_name,
                'recommended_stock' => (int) ($color->recommended_stock ?? 0),
                'default_order' => (int) ($color->default_order ?? 0),
                'product_id' => $preferred?->getKey(),
            ];
        }

        return $meta;
    }

    private static function productSizeSql(string $alias): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(NULLIF({$alias}.name, '') AS REAL)"
            : "CAST(NULLIF({$alias}.name, '') AS DECIMAL(10,2))";
    }

    private static function orderDateSql(string $alias): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date(COALESCE({$alias}.order_date, {$alias}.created_at))"
            : "DATE(COALESCE({$alias}.order_date, {$alias}.created_at))";
    }

    /**
     * @param  list<float|int>  $values
     */
    private static function median(array $values): float
    {
        $values = array_values(array_filter(array_map('floatval', $values), fn (float $v): bool => $v > 0));
        sort($values);
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * @param  list<string>  $dates
     */
    private static function averageGapDays(array $dates): float
    {
        $timestamps = [];
        foreach ($dates as $date) {
            try {
                $timestamps[] = Carbon::parse($date)->startOfDay()->timestamp;
            } catch (\Throwable) {
                // skip
            }
        }
        $timestamps = array_values(array_unique($timestamps));
        sort($timestamps);
        if (count($timestamps) < 2) {
            return 90.0;
        }

        $gaps = [];
        for ($i = 1; $i < count($timestamps); $i++) {
            $gaps[] = max(1, ($timestamps[$i] - $timestamps[$i - 1]) / 86400);
        }

        return array_sum($gaps) / count($gaps);
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }

    private static function customerLabel(User $user): string
    {
        if (filled($user->company_name)) {
            return (string) $user->company_name;
        }

        $person = trim(implode(' ', array_filter([$user->name, $user->surname])));

        return $person !== '' ? $person : (string) ($user->email ?? '#'.$user->getKey());
    }
}
