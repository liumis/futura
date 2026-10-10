<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

class PackingReportPdfGenerator
{
    /**
     * @param  iterable<int, Order>  $orders
     * @param  array{
     *     from?: string|null,
     *     until?: string|null,
     *     package_label?: string,
     *     period_label?: string,
     *     audience?: string,
     *     audience_label?: string
     * }  $filters
     */
    public static function generate(iterable $orders, array $filters = []): string
    {
        return Pdf::loadView('pdf.packing-report', self::build($orders, $filters))
            ->setPaper('a4', 'landscape')
            ->output();
    }

    /**
     * @param  iterable<int, Order>  $orders
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function build(iterable $orders, array $filters = []): array
    {
        $audience = PackingReportDocumentGenerator::normalizeAudience($filters['audience'] ?? 'all');
        $audienceLabel = (string) ($filters['audience_label'] ?? PackingReportDocumentGenerator::audienceLabel($audience));

        $rows = Collection::make($orders)
            ->map(function (Order $order): array {
                $order->loadMissing(['user', 'package', 'orderItems']);
                $stats = OrderPackageCalculator::calculate($order);
                $user = $order->user;

                return [
                    'id' => (string) $order->getKey(),
                    'date' => $order->order_date?->format('Y-m-d')
                        ?? $order->created_at?->format('Y-m-d')
                        ?? '—',
                    'customer' => filled($user?->company_name)
                        ? (string) $user->company_name
                        : ($user?->displayName() ?: '—'),
                    'audience' => $user?->export ? 'Export' : 'Internal',
                    'package' => $order->package?->name ?: 'Default',
                    'tracking' => filled($order->tracking_number) ? (string) $order->tracking_number : '—',
                    'items' => $stats['items'],
                    'packages' => $stats['packages'],
                    'palletes' => $stats['palletes'],
                    'netto' => number_format($stats['netto'], 3, '.', ''),
                    'brutto' => number_format($stats['brutto'], 3, '.', ''),
                    'plastic' => number_format($stats['plastic'], 3, '.', ''),
                    'cardboard_i' => number_format($stats['cardboard_i'], 3, '.', ''),
                    'cardboard_ii' => number_format($stats['cardboard_ii'], 3, '.', ''),
                    'wood' => number_format($stats['wood'], 3, '.', ''),
                    'netto_raw' => $stats['netto'],
                    'brutto_raw' => $stats['brutto'],
                    'plastic_raw' => $stats['plastic'],
                    'cardboard_i_raw' => $stats['cardboard_i'],
                    'cardboard_ii_raw' => $stats['cardboard_ii'],
                    'wood_raw' => $stats['wood'],
                ];
            })
            ->values();

        $periodLabel = filled($filters['period_label'] ?? null)
            ? (string) $filters['period_label']
            : 'All dates';
        $packageLabel = filled($filters['package_label'] ?? null)
            ? (string) $filters['package_label']
            : 'All package profiles';

        return [
            'company' => CompanySetting::instance(),
            'title' => 'Packing report',
            'audienceLabel' => $audienceLabel,
            'periodLabel' => $periodLabel,
            'packageLabel' => $packageLabel,
            'lines' => $rows,
            'ordersCount' => $rows->count(),
            'itemsTotal' => (int) $rows->sum('items'),
            'packagesTotal' => (int) $rows->sum('packages'),
            'palletesTotal' => (int) $rows->sum('palletes'),
            'nettoTotal' => number_format((float) $rows->sum('netto_raw'), 3, '.', ''),
            'bruttoTotal' => number_format((float) $rows->sum('brutto_raw'), 3, '.', ''),
            'plasticTotal' => number_format((float) $rows->sum('plastic_raw'), 3, '.', ''),
            'cardboardITotal' => number_format((float) $rows->sum('cardboard_i_raw'), 3, '.', ''),
            'cardboardIITotal' => number_format((float) $rows->sum('cardboard_ii_raw'), 3, '.', ''),
            'woodTotal' => number_format((float) $rows->sum('wood_raw'), 3, '.', ''),
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
