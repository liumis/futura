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
 * Import October 2026 sales lines from F Duomenys (Darbai_2.html) into orders.
 * Creates missing HIST orders (e.g. Vinilas) and fills order items on existing
 * October header rows that were imported without products.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('orders')) {
            return;
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

            if (OrderItem::query()->where('order_id', $order->id)->exists()) {
                continue;
            }

            foreach ($group['lines'] as $productId => $qty) {
                $product = Product::query()->find($productId);
                $amount = $product instanceof Product
                    ? $product->rollAmountFromMeters((int) $qty)
                    : (int) $qty;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'amount' => $amount,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep imported October orders; line items are not safely reversible alone.
    }
};
