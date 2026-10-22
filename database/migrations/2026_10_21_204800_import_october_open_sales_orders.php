<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\HistoricalSalesOrderImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

/**
 * Import open AR sales rows (invoice nr / date / customer / due / amount).
 * Second column (Lithuanian month + day) → orders.order_date.
 */
return new class extends Migration
{
    /**
     * @return list<array{fte: ?string, order_date: string, customer_code: string, customer: string, amount: ?float}>
     */
    private function rows(): array
    {
        return [
            [
                'fte' => 'FTE0163',
                'order_date' => '2026-09-28', // RUGSĖJO 28
                'customer_code' => 'SVETMAR',
                'customer' => 'SVETMAR D.O.O.',
                'amount' => 6933.00,
            ],
            [
                'fte' => 'FTE0167',
                'order_date' => '2026-10-12', // SPALIO 12
                'customer_code' => 'PEDROTAVARES',
                'customer' => 'PEDRO TAVARES TEXTEIS, LDA',
                'amount' => 1070.00,
            ],
            [
                'fte' => 'FTE0170',
                'order_date' => '2026-10-16', // SPALIO 16
                'customer_code' => 'SVETMAR',
                'customer' => 'SVETMAR D.O.O.',
                'amount' => 2143.00,
            ],
            [
                'fte' => 'FTE0171',
                'order_date' => '2026-10-19', // SPALIO 19
                'customer_code' => 'TYLIGADESBROS',
                'customer' => 'TYLIGADAS BROS S.A.-VIOTAP',
                'amount' => 3455.00,
            ],
            [
                'fte' => 'FTE0173',
                'order_date' => '2026-10-20', // SPALIO 20
                'customer_code' => 'TENATEXTRADE',
                'customer' => 'TENA TEX TRADE , S.R.O.',
                'amount' => 4340.00,
            ],
            // SPALIO 7 / PEDRO TAVARES — skipped: no invoice number or amount in source.
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $importer = app(HistoricalSalesOrderImporter::class);
        $role = Role::findOrCreate(\App\Models\User::ROLE_CUSTOMER, 'web');

        foreach ($this->rows() as $row) {
            $historyName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                .$row['customer_code'].'|'
                .$row['order_date'].'|'
                .$row['fte'];

            if (Order::query()->where('name', $historyName)->exists()) {
                continue;
            }

            // Already imported under HIST|CODE|date without FTE suffix.
            $legacyName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                .$row['customer_code'].'|'
                .$row['order_date'];

            if (Order::query()->where('name', $legacyName)->exists()) {
                Order::query()->where('name', $legacyName)->update([
                    'name' => $historyName,
                    'amount' => $row['amount'],
                    'order_date' => Carbon::parse($row['order_date'])->setTime(12, 0),
                ]);

                continue;
            }

            [$user] = $importer->resolveCustomer($row['customer_code'], $row['customer'], $role);

            Order::query()->create([
                'name' => $historyName,
                'status' => OrderStatus::Completed,
                'stock_reserved' => false,
                'amount' => $row['amount'],
                'shipping_cost' => 0,
                // Source column 2 (RUGSĖJO/SPALIO + day) → order_date ("t").
                'order_date' => Carbon::parse($row['order_date'])->setTime(12, 0),
                'user_id' => $user->id,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        foreach ($this->rows() as $row) {
            $historyName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                .$row['customer_code'].'|'
                .$row['order_date'].'|'
                .$row['fte'];

            Order::query()->where('name', $historyName)->delete();
        }
    }
};
