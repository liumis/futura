<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductType;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StockChartReportBuilder
{
    public const METRIC_METERS = 'meters';

    public const METRIC_ROLLS = 'rolls';

    public static function normalizeMetric(mixed $metric): string
    {
        return (string) $metric === self::METRIC_ROLLS
            ? self::METRIC_ROLLS
            : self::METRIC_METERS;
    }

    /**
     * @return array{
     *     metric: string,
     *     metric_label: string,
     *     title: string,
     *     unit_label: string,
     *     collections: list<array{
     *         id: int,
     *         label: string,
     *         total: float,
     *         colors: list<array{
     *             id: int,
     *             label: string,
     *             code: string,
     *             hex: string|null,
     *             stock: float,
     *             recommended: float|null
     *         }>
     *     }>,
     *     colors: int,
     *     grand_total: float,
     *     low_stock_limit: float,
     *     low_stock_limit_label: string
     * }
     */
    public static function build(mixed $metric = self::METRIC_METERS): array
    {
        $metric = self::normalizeMetric($metric);
        $lowStockLimit = max(0, SystemSetting::instance()->lowStockAlertLimit());

        /** @var Collection<int, Product> $products */
        $products = self::productsQuery()->get();

        /** @var array<int, array{id: int, label: string, colors: array<int, array{id: int, label: string, code: string, hex: string|null, stock: float, recommended: float|null}>}> $collections */
        $collections = [];

        foreach ($products as $product) {
            $color = $product->color;
            $collection = $color?->collection;

            if ($color === null || $collection === null) {
                continue;
            }

            $collectionId = (int) $collection->getKey();
            $colorId = (int) $color->getKey();
            $value = $metric === self::METRIC_ROLLS
                ? (float) $product->current_amount
                : $product->stockMeters();

            if ($value <= 0) {
                continue;
            }

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
                $recommendedMeters = max(0, (int) ($color->recommended_stock ?? 0));

                $collections[$collectionId]['colors'][$colorId] = [
                    'id' => $colorId,
                    'label' => $colorName !== '' ? $colorName : ($colorCode !== '' ? $colorCode : 'Color #'.$colorId),
                    'code' => $colorCode,
                    'hex' => $color->normalizedHex(),
                    'stock' => 0.0,
                    // Recommended stock is stored in meters on the color.
                    'recommended' => $metric === self::METRIC_METERS && $recommendedMeters > 0
                        ? (float) $recommendedMeters
                        : null,
                ];
            }

            $collections[$collectionId]['colors'][$colorId]['stock'] += $value;
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
                if ($color['recommended'] !== null) {
                    $color['recommended'] = round((float) $color['recommended'], 2);
                }

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
                ? 'Current stock by color (rolls)'
                : 'Current stock by color (meters)',
            'unit_label' => $metric === self::METRIC_ROLLS ? 'rolls' : 'm',
            'collections' => $rows,
            'colors' => $colorCount,
            'grand_total' => round($grandTotal, 2),
            'low_stock_limit' => round($lowStockLimit, 3),
            'low_stock_limit_label' => $lowStockLimit > 0
                ? self::formatValue($lowStockLimit, self::METRIC_METERS)
                : '',
        ];
    }

    public static function formatValue(float $value, string $metric = self::METRIC_METERS): string
    {
        if (abs($value) < 0.005) {
            return '—';
        }

        $formatted = number_format($value, $metric === self::METRIC_ROLLS ? 0 : 2, '.', ' ');

        return $metric === self::METRIC_ROLLS
            ? $formatted
            : $formatted.' m';
    }

    public static function productsQuery(): Builder
    {
        return Product::query()
            ->with(['color.collection', 'productType'])
            ->whereNotNull('color_id')
            ->where(function (Builder $query): void {
                $query->whereDoesntHave('productType')
                    ->orWhereHas(
                        'productType',
                        fn (Builder $type): Builder => $type->where('key', '!=', ProductType::KEY_CATALOG),
                    );
            })
            ->where('current_amount', '>', 0);
    }
}
