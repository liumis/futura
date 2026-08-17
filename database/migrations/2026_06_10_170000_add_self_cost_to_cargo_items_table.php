<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cargo_items', 'self_cost')) {
            Schema::table('cargo_items', function (Blueprint $table) {
                $table->decimal('self_cost', 12, 2)
                    ->default(0)
                    ->after('amount');
            });
        }

        if (Schema::hasColumn('products', 'default_cost')) {
            DB::table('cargo_items')
                ->select(['cargo_items.id', 'products.default_cost'])
                ->join('products', 'products.id', '=', 'cargo_items.product_id')
                ->orderBy('cargo_items.id')
                ->each(function (object $row): void {
                    DB::table('cargo_items')
                        ->where('id', $row->id)
                        ->update(['self_cost' => $row->default_cost]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('cargo_items', function (Blueprint $table) {
            $table->dropColumn('self_cost');
        });
    }
};
