<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Order;
use App\Models\Package;

class OrderPackageCalculator
{
    /**
     * @return array{
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }
     */
    public static function calculate(Order $order, ?Package $package = null): array
    {
        $order->loadMissing('orderItems');
        $items = (int) $order->orderItems->sum('amount');

        if ($package === null) {
            $package = self::resolvePackage($order);
        }

        if ($package === null || $items <= 0) {
            return self::emptyStats($items);
        }

        $packages = $items;
        $itemsOnPalette = max(1, (int) $package->items_on_palette);
        $palletes = max(1, (int) ceil($packages / $itemsOnPalette));

        $plastic = $packages * (float) $package->plastic_weight;
        $cardboardI = $packages * (float) $package->cardboard_i_weight;
        $cardboardII = $packages * (float) $package->cardboard_ii_weight;
        $wood = $palletes * (float) $package->palette_weight;
        $brutto = ($packages * (float) $package->total_weight) + $wood;
        $netto = $brutto - $plastic - $cardboardI - $cardboardII;

        return [
            'items' => $items,
            'packages' => $packages,
            'palletes' => $palletes,
            'netto' => round($netto, 3),
            'brutto' => round($brutto, 3),
            'plastic' => round($plastic, 3),
            'cardboard_i' => round($cardboardI, 3),
            'cardboard_ii' => round($cardboardII, 3),
            'wood' => round($wood, 3),
        ];
    }

    /**
     * @param  array{
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }  $stats
     */
    public static function formatTrackingLine(Order $order, array $stats, InvoiceLanguage $language = InvoiceLanguage::English): ?string
    {
        if ($stats['items'] <= 0) {
            return null;
        }

        $unit = InvoicePdfTranslator::strings($language)['roll_unit'];
        $tracking = trim((string) ($order->tracking_number ?? ''));

        if ($tracking === '') {
            return sprintf('%d %s', $stats['items'], $unit);
        }

        return sprintf('%s %d %s', $tracking, $stats['items'], $unit);
    }

    /**
     * @param  array{
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }  $stats
     */
    public static function formatWeightsLine(array $stats, InvoiceLanguage $language = InvoiceLanguage::English): ?string
    {
        if ($stats['items'] <= 0) {
            return null;
        }

        $t = InvoicePdfTranslator::strings($language);

        $parts = [
            sprintf('%s %s kg', $t['netto'], self::formatKg($stats['netto'])),
            sprintf('%s %s kg', $t['brutto'], self::formatKg($stats['brutto'])),
        ];

        $packingParts = [];

        if ($stats['plastic'] > 0) {
            $packingParts[] = sprintf('%s kg-%s', self::formatKg($stats['plastic']), $t['plastic']);
        }

        if ($stats['cardboard_i'] > 0) {
            $packingParts[] = sprintf('%s kg-%s', self::formatKg($stats['cardboard_i']), $t['carton_i']);
        }

        if ($stats['cardboard_ii'] > 0) {
            $packingParts[] = sprintf('%s kg-%s', self::formatKg($stats['cardboard_ii']), $t['carton_ii']);
        }

        if ($packingParts !== []) {
            $parts[] = $t['packing'].': '.implode('; ', $packingParts);
        }

        return implode('; ', $parts);
    }

    public static function resolvePackage(Order $order): ?Package
    {
        $order->loadMissing('package');

        if ($order->package !== null) {
            return $order->package;
        }

        $id = Package::query()->orderBy('id')->value('id');

        return filled($id) ? Package::query()->find((int) $id) : null;
    }

    /**
     * @return array{
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }
     */
    private static function emptyStats(int $items = 0): array
    {
        return [
            'items' => $items,
            'packages' => 0,
            'palletes' => 0,
            'netto' => 0.0,
            'brutto' => 0.0,
            'plastic' => 0.0,
            'cardboard_i' => 0.0,
            'cardboard_ii' => 0.0,
            'wood' => 0.0,
        ];
    }

    private static function formatKg(float $value): string
    {
        if (abs($value - round($value)) < 0.0005) {
            return number_format($value, 0, ',', '');
        }

        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }
}
