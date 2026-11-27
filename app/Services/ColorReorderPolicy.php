<?php

namespace App\Services;

use App\Models\Color;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recommended stock / default order from Darbai STOCK_RULES + ORDER_POLICY.
 *
 * Seed: recommended_stock = rule.limit (m); default_order = rolls to cover max(moq, orderUpTo).
 * Live: recompute from trailing sales (coverMonths + ABC safetyCap).
 */
class ColorReorderPolicy
{
    public const DATA_PATH = 'database/data/stock_rules.json';

    public const TRAILING_MONTHS = 12;

    /** Extra cover months added to Futura (F) orderUpTo above limit. */
    public const FUTURA_ORDER_UP_EXTRA_MONTHS = 3.0;

    /** @var array{order_policy: array<string, mixed>, order_nomark: list<string>, stock_rules: array<string, array<string, mixed>>}|null */
    private static ?array $data = null;

    /** @var array<string, string>|null key → rule id */
    private static ?array $ruleIndex = null;

    /**
     * @return array{matched: int, updated: int, skipped: int, nomark: int, rules: int, colors: int}
     */
    public static function applyStockRules(bool $dryRun = false): array
    {
        self::loadData();

        $stats = [
            'matched' => 0,
            'updated' => 0,
            'skipped' => 0,
            'nomark' => 0,
            'rules' => count(self::$data['stock_rules'] ?? []),
            'colors' => 0,
        ];

        Color::query()
            ->with('collection')
            ->orderBy('id')
            ->each(function (Color $color) use (&$stats, $dryRun): void {
                $stats['colors']++;

                $rule = self::findStockRule($color);
                if ($rule !== null) {
                    $stats['matched']++;
                    [$recommended, $defaultOrder] = self::valuesFromRule($rule);
                    if (self::persistIfChanged($color, $recommended, $defaultOrder, $dryRun)) {
                        $stats['updated']++;
                    }

                    return;
                }

                if (self::matchesNoMarkPrefix($color)) {
                    $stats['nomark']++;
                    $stats['matched']++;
                    if (self::persistIfChanged($color, 0, 0, $dryRun)) {
                        $stats['updated']++;
                    }

                    return;
                }

                $stats['skipped']++;
            });

        return $stats;
    }

    /**
     * Silently refresh recommended_stock / default_order for colors on the order.
     * Uses trailing sales + ORDER_POLICY; STOCK_RULES supply roll/co/reserve/cls when matched.
     *
     * @return int number of colors updated
     */
    public static function refreshForOrder(Order $order): int
    {
        $order->loadMissing('orderItems.product');

        $colorIds = [];
        foreach ($order->orderItems as $item) {
            $colorId = $item->product?->color_id;
            if ($colorId !== null) {
                $colorIds[(int) $colorId] = true;
            }
        }

        if ($colorIds === []) {
            return 0;
        }

        return self::refreshColorIds(array_keys($colorIds));
    }

    /**
     * @param  list<int>  $colorIds
     * @return int number of colors updated
     */
    public static function refreshColorIds(array $colorIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $colorIds))));
        if ($ids === []) {
            return 0;
        }

        self::loadData();

        $avgs = self::monthlyAvgByColor(self::TRAILING_MONTHS);
        $classes = self::classifyAbc($avgs);

        $updated = 0;
        Color::query()
            ->with(['collection', 'products'])
            ->whereIn('id', $ids)
            ->each(function (Color $color) use ($avgs, $classes, &$updated): void {
                $rule = self::findStockRule($color);
                if ($rule === null && self::matchesNoMarkPrefix($color)) {
                    if (self::persistIfChanged($color, 0, 0, false)) {
                        $updated++;
                    }

                    return;
                }

                $avg = (float) ($avgs[(int) $color->id] ?? 0.0);
                $cls = $rule['cls'] ?? $classes[(int) $color->id] ?? 'C';
                $cls = strtoupper((string) $cls);
                if (! in_array($cls, ['A', 'B', 'C'], true)) {
                    $cls = 'C';
                }

                $roll = self::resolveRoll($color, $rule);
                $co = self::resolveCo($color, $rule, $roll);
                $reserve = (float) ($rule['reserve'] ?? 0);

                $targets = self::computeFromSales($avg, $cls, $roll, $co, $reserve);
                if (self::persistIfChanged($color, $targets['recommended_stock'], $targets['default_order'], false)) {
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{0: int, 1: int} recommended_stock meters, default_order rolls
     */
    public static function valuesFromRule(array $rule): array
    {
        $policy = self::policy();
        $moq = (float) ($rule['moq'] ?? $policy['moq'] ?? 300);
        $roll = max(1.0, (float) ($rule['roll'] ?? $policy['rollF'] ?? 25));
        $limit = (float) ($rule['limit'] ?? 0);
        $orderUpTo = (float) ($rule['orderUpTo'] ?? $limit);

        $recommended = (int) max(0, round($limit));
        $defaultOrder = self::defaultOrderRolls($orderUpTo, $moq, $roll);

        return [$recommended, $defaultOrder];
    }

    /**
     * @return array{recommended_stock: int, default_order: int, limit: float, orderUpTo: float, canAdd: float}
     */
    public static function computeFromSales(
        float $avgMonthlyMeters,
        string $cls = 'C',
        float $roll = 25.0,
        string $co = 'F',
        float $reserve = 0.0,
    ): array {
        $policy = self::policy();
        $roll = max(1.0, $roll);
        $cover = (float) ($policy['coverMonths'] ?? 4.5);
        $minM = (float) ($policy['minM'] ?? 100);
        $moq = (float) ($policy['moq'] ?? 300);
        $canAddMonths = (float) ($policy['canAddMonths'] ?? 1.5);
        $safety = (float) (($policy['safetyCapMonths'][$cls] ?? null) ?? 1.5);

        $avg = max(0.0, $avgMonthlyMeters);
        $rawLimit = $avg * ($cover + $safety);
        $limit = max($minM, self::ceilToRoll($rawLimit, $roll)) + max(0.0, $reserve);

        $canAdd = $limit + self::ceilToRoll($avg * $canAddMonths, $roll);

        $co = strtoupper($co);
        if ($co === 'V') {
            $orderUpTo = $limit;
        } else {
            $orderUpTo = $limit + self::ceilToRoll($avg * self::FUTURA_ORDER_UP_EXTRA_MONTHS, $roll);
        }

        return [
            'recommended_stock' => (int) max(0, round($limit)),
            'default_order' => self::defaultOrderRolls($orderUpTo, $moq, $roll),
            'limit' => $limit,
            'orderUpTo' => $orderUpTo,
            'canAdd' => $canAdd,
        ];
    }

    public static function defaultOrderRolls(float $orderUpTo, float $moq, float $roll): int
    {
        $roll = max(1.0, $roll);
        $meters = max($moq, $orderUpTo);
        if ($meters <= 0) {
            return 0;
        }

        return max(1, (int) ceil($meters / $roll));
    }

    public static function findStockRule(Color $color): ?array
    {
        self::loadData();
        $index = self::ruleIndex();
        $rules = self::$data['stock_rules'] ?? [];

        foreach (self::colorKeyCandidates($color) as $key) {
            if ($key !== '' && isset($index[$key])) {
                $id = $index[$key];
                $rule = $rules[$id] ?? null;

                return is_array($rule) ? array_merge(['id' => $id], $rule) : null;
            }
        }

        // Abbreviated colour names (PALERMO5555BL, FERRARA1985KO, AGNONA111COBBLE).
        $collection = self::normalizeKey((string) ($color->collection?->name ?? ''));
        $code = self::normalizeKey((string) $color->color_code);
        $name = self::normalizeKey((string) $color->color_name);
        if ($collection === '' || $code === '') {
            return null;
        }

        $prefix = $collection.$code;
        foreach ($index as $key => $id) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }
            $suffix = substr($key, strlen($prefix));
            if ($suffix === '' || $name === '' || str_starts_with($name, $suffix) || str_starts_with($suffix, $name)) {
                $rule = $rules[$id] ?? null;

                return is_array($rule) ? array_merge(['id' => $id], $rule) : null;
            }
        }

        return null;
    }

    public static function isNoMark(Color $color): bool
    {
        // Explicit STOCK_RULES match wins over ORDER_NOMARK (same as Darbai stockNoMark).
        if (self::findStockRule($color) !== null) {
            return false;
        }

        return self::matchesNoMarkPrefix($color);
    }

    public static function matchesNoMarkPrefix(Color $color): bool
    {
        self::loadData();

        $prefixes = self::$data['order_nomark'] ?? [];
        foreach (self::colorKeyCandidates($color) as $key) {
            if ($key === '') {
                continue;
            }
            foreach ($prefixes as $prefix) {
                $normalized = self::normalizeKey((string) $prefix);
                if ($normalized !== '' && str_starts_with($key, $normalized)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function colorKeyCandidates(Color $color): array
    {
        $color->loadMissing('collection');

        $collection = self::normalizeKey((string) ($color->collection?->name ?? ''));
        $code = self::normalizeKey((string) $color->color_code);
        $name = self::normalizeKey((string) $color->color_name);

        $keys = [
            $collection.$code.$name,
            $collection.$code,
            $code.$name,
            $code,
        ];

        return array_values(array_unique(array_filter($keys, fn (string $k): bool => $k !== '')));
    }

    public static function normalizeKey(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    public static function ceilToRoll(float $meters, float $roll): float
    {
        $roll = max(1.0, $roll);
        if ($meters <= 0) {
            return 0.0;
        }

        return ceil($meters / $roll) * $roll;
    }

    /**
     * @return array<int, float> color_id => average meters / month over trailing window
     */
    public static function monthlyAvgByColor(int $months = self::TRAILING_MONTHS): array
    {
        $months = max(1, $months);
        $until = Carbon::today();
        $from = $until->copy()->subMonthsNoOverflow($months)->startOfDay();

        $sizeExpr = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(NULLIF(p.name, '') AS REAL)"
            : "CAST(NULLIF(p.name, '') AS DECIMAL(10,2))";
        $dateExpr = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(o.order_date, o.created_at))'
            : 'DATE(COALESCE(o.order_date, o.created_at))';

        $statusValues = array_map(
            fn ($status): string => $status->value,
            SalesReportBuilder::includedStatuses(),
        );

        try {
            $rows = DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->join('products as p', 'p.id', '=', 'oi.product_id')
                ->whereIn('o.status', $statusValues)
                ->whereNotNull('p.color_id')
                ->whereRaw("{$dateExpr} >= ?", [$from->toDateString()])
                ->whereRaw("{$dateExpr} <= ?", [$until->toDateString()])
                ->groupBy('p.color_id')
                ->selectRaw("p.color_id as color_id, SUM(oi.amount * {$sizeExpr}) as meters")
                ->get();
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->color_id] = ((float) $row->meters) / $months;
        }

        return $result;
    }

    /**
     * Pareto ABC: A until ~80% of volume, B until ~95%, rest C.
     *
     * @param  array<int, float>  $avgs
     * @return array<int, string>
     */
    public static function classifyAbc(array $avgs): array
    {
        $positive = array_filter($avgs, fn (float $v): bool => $v > 0);
        arsort($positive);
        $total = array_sum($positive);
        if ($total <= 0) {
            return array_fill_keys(array_keys($avgs), 'C');
        }

        $classes = array_fill_keys(array_keys($avgs), 'C');
        $cum = 0.0;
        foreach ($positive as $colorId => $avg) {
            $cum += $avg;
            $share = $cum / $total;
            $classes[(int) $colorId] = $share <= 0.80 ? 'A' : ($share <= 0.95 ? 'B' : 'C');
        }

        return $classes;
    }

    /**
     * @return array{order_policy: array<string, mixed>, order_nomark: list<string>, stock_rules: array<string, array<string, mixed>>}
     */
    public static function data(): array
    {
        self::loadData();

        return self::$data ?? ['order_policy' => [], 'order_nomark' => [], 'stock_rules' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function policy(): array
    {
        self::loadData();

        return self::$data['order_policy'] ?? [];
    }

    public static function clearCache(): void
    {
        self::$data = null;
        self::$ruleIndex = null;
    }

    private static function loadData(): void
    {
        if (self::$data !== null) {
            return;
        }

        $path = base_path(self::DATA_PATH);
        if (! is_file($path)) {
            self::$data = ['order_policy' => [], 'order_nomark' => [], 'stock_rules' => []];

            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        self::$data = is_array($decoded)
            ? [
                'order_policy' => is_array($decoded['order_policy'] ?? null) ? $decoded['order_policy'] : [],
                'order_nomark' => array_values(array_map('strval', $decoded['order_nomark'] ?? [])),
                'stock_rules' => is_array($decoded['stock_rules'] ?? null) ? $decoded['stock_rules'] : [],
            ]
            : ['order_policy' => [], 'order_nomark' => [], 'stock_rules' => []];
    }

    /**
     * @return array<string, string>
     */
    private static function ruleIndex(): array
    {
        if (self::$ruleIndex !== null) {
            return self::$ruleIndex;
        }

        self::loadData();
        $ix = [];
        foreach (self::$data['stock_rules'] ?? [] as $id => $rule) {
            $keys = $rule['keys'] ?? [$id];
            if (! is_array($keys)) {
                $keys = [$id];
            }
            foreach ($keys as $key) {
                $normalized = self::normalizeKey((string) $key);
                if ($normalized !== '') {
                    $ix[$normalized] = (string) $id;
                }
            }
            $idKey = self::normalizeKey((string) $id);
            if ($idKey !== '') {
                $ix[$idKey] = (string) $id;
            }
        }

        return self::$ruleIndex = $ix;
    }

    private static function persistIfChanged(Color $color, int $recommended, int $defaultOrder, bool $dryRun): bool
    {
        $currentRecommended = (int) ($color->recommended_stock ?? 0);
        $currentDefault = (int) ($color->default_order ?? 0);

        if ($currentRecommended === $recommended && $currentDefault === $defaultOrder) {
            return false;
        }

        if ($dryRun) {
            return true;
        }

        $color->forceFill([
            'recommended_stock' => $recommended,
            'default_order' => $defaultOrder,
        ])->save();

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $rule
     */
    private static function resolveRoll(Color $color, ?array $rule): float
    {
        if (isset($rule['roll']) && (float) $rule['roll'] > 0) {
            return (float) $rule['roll'];
        }

        $sizes = [];
        foreach ($color->products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $meters = $product->sizeMeters();
            if ($meters !== null && $meters > 0) {
                $sizes[] = $meters;
            }
        }

        if ($sizes !== []) {
            $counts = array_count_values(array_map(fn (float $m): string => (string) $m, $sizes));
            arsort($counts);
            $mode = (float) array_key_first($counts);
            if ($mode > 0) {
                return $mode;
            }
        }

        $policy = self::policy();
        $co = isset($rule['co']) ? strtoupper((string) $rule['co']) : null;
        if ($co === 'V') {
            return (float) ($policy['rollV'] ?? 30);
        }

        return (float) ($policy['rollF'] ?? 25);
    }

    /**
     * @param  array<string, mixed>|null  $rule
     */
    private static function resolveCo(Color $color, ?array $rule, float $roll): string
    {
        if (isset($rule['co']) && $rule['co'] !== '') {
            return strtoupper((string) $rule['co']);
        }

        return $roll >= 30 ? 'V' : 'F';
    }
}
