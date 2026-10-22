<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Map missing Paloma Futura codes onto existing PA*-30 products, then backfill
 * October 2026 F Duomenys order lines that were skipped.
 */
return new class extends Migration
{
    /**
     * @return array<string, string> product_code => alternative_code
     */
    private function palomaMaps(): array
    {
        return [
            'PA103-30' => 'PALOMA103PEARL30',
            'PA106-30' => 'PALOMA106STARFISH30',
            'PA121-30' => 'PALOMA121BLACK30',
        ];
    }

    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('products') || ! Schema::hasTable('orders')) {
            return;
        }

        foreach ($this->palomaMaps() as $productCode => $alternative) {
            $product = Product::query()->where('product_code', $productCode)->first();
            if ($product === null) {
                continue;
            }

            $currentAlt = trim((string) ($product->alternative_code ?? ''));
            if (strcasecmp($currentAlt, $alternative) === 0) {
                continue;
            }

            // Preserve supplier code if it was stored as alternative_code.
            if ($currentAlt !== ''
                && ! str_starts_with(strtoupper($currentAlt), 'PALOMA')
                && blank($product->dsv_code)) {
                $product->dsv_code = $currentAlt;
            }

            $product->alternative_code = $alternative;
            $product->save();
        }

        $path = database_path('data/october-2026-f-duomenys-sales.json');
        if (! is_file($path)) {
            return;
        }

        /** @var array{rows?: list<array<string, mixed>>} $payload */
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $payload['rows'] ?? [];
        if ($rows === []) {
            return;
        }

        $importer = app(HistoricalSalesOrderImporter::class);
        $role = Role::findOrCreate(\App\Models\User::ROLE_CUSTOMER, 'web');
        $productsByCode = $importer->productLookup();

        $groups = [];

        foreach ($rows as $row) {
            $customer = trim((string) ($row['customer'] ?? ''));
            $code = strtoupper(trim((string) ($row['customer_code'] ?? '')));
            $date = trim((string) ($row['sales_date'] ?? ''));
            $item = strtoupper(trim((string) ($row['item'] ?? '')));
            $quantity = (int) round((float) ($row['quantity'] ?? 0));
            $sales = $importer->parseEuro((string) ($row['sales_eur'] ?? '0'));

            if ($code === '' && $customer !== '') {
                $code = $importer->inferCustomerCode($customer);
            }

            if ($code === '' || $customer === '' || $date === '' || $item === '' || $quantity <= 0) {
                continue;
            }

            if (! str_starts_with($date, '2026-10-')) {
                continue;
            }

            $product = $productsByCode[$item] ?? null;
            if ($product === null) {
                continue;
            }

            $groupKey = $code.'|'.$date;
            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'customer_code' => $code,
                    'customer' => $customer,
                    'sales_date' => $date,
                    'lines' => [],
                    'sales_total' => 0.0,
                ];
            }

            $productId = (int) $product->id;
            $groups[$groupKey]['lines'][$productId] = ($groups[$groupKey]['lines'][$productId] ?? 0) + $quantity;
            $groups[$groupKey]['sales_total'] += $sales;
        }

        foreach ($groups as $group) {
            if ($group['lines'] === []) {
                continue;
            }

            [$user] = $importer->resolveCustomer($group['customer_code'], $group['customer'], $role);
            $baseName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                .$group['customer_code'].'|'
                .$group['sales_date'];

            $order = Order::query()
                ->where(function ($query) use ($baseName): void {
                    $query->where('name', $baseName)
                        ->orWhere('name', 'like', $baseName.'|%');
                })
                ->orderBy('id')
                ->first();

            if ($order === null) {
                $order = Order::query()->create([
                    'name' => $baseName,
                    'status' => OrderStatus::Completed,
                    'stock_reserved' => false,
                    'amount' => round($group['sales_total'], 2),
                    'shipping_cost' => 0,
                    'order_date' => Carbon::parse($group['sales_date'])->setTime(12, 0),
                    'user_id' => $user->id,
                ]);
            }

            $existingProductIds = OrderItem::query()
                ->where('order_id', $order->id)
                ->pluck('product_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $added = false;

            foreach ($group['lines'] as $productId => $qty) {
                if (in_array((int) $productId, $existingProductIds, true)) {
                    continue;
                }

                $product = Product::query()->find($productId);
                $amount = $product instanceof Product
                    ? $product->rollAmountFromMeters((int) $qty)
                    : (int) $qty;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'amount' => $amount,
                ]);
                $added = true;
            }

            // Header FTE amounts stay; F Duomenys-only orders refresh from matched lines.
            if ($added && ! str_contains((string) $order->name, '|FTE')) {
                $order->amount = round($group['sales_total'], 2);
                $order->save();
            }
        }
    }

    public function down(): void
    {
        //
    }
};
