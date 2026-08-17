<?php

namespace App\Filament\Admin\Pages;

use App\Models\Order;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

class OrderCharts extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Charts';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.admin.pages.order-charts';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOrderChartItems(): array
    {
        return Order::query()
            ->with(['user', 'orderItems.product.color.collection'])
            ->orderBy('order_date')
            ->get()
            ->map(function (Order $order): array {
                $date = $order->order_date instanceof Carbon
                    ? $order->order_date->copy()->startOfDay()
                    : Carbon::parse((string) ($order->order_date ?? $order->created_at))->startOfDay();

                $country = $this->extractCountryFromAddress((string) ($order->user?->company_address ?? ''));
                $collectionNames = $order->orderItems
                    ->map(fn ($item) => $item->product?->color?->collection?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $amount = (float) ($order->amount ?? 0);
                $shipping = (float) ($order->shipping_cost ?? 0);
                $withoutShipping = max(0, round($amount - $shipping, 2));

                return [
                    'day' => $date->format('Y-m-d'),
                    'customer_id' => $order->user?->getKey(),
                    'customer_label' => $order->user?->company_name
                        ?? $order->user?->name
                        ?? null
                        ?? ('Customer #'.$order->user_id),
                    'customer_country' => $country,
                    'collections' => $collectionNames,
                    'sum_without_shipping' => $withoutShipping,
                ];
            })
            ->values()
            ->all();
    }

    private function extractCountryFromAddress(string $address): string
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
