<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $warehouseId = DB::table('warehouses')
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id')
            ?? DB::table('warehouses')->orderBy('id')->value('id');

        if (! Schema::hasColumn('collections', 'warehouse_id')) {
            Schema::table('collections', function (Blueprint $table): void {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('supplier_id');
            });
        }

        if ($warehouseId !== null) {
            DB::table('collections')
                ->whereNull('warehouse_id')
                ->update(['warehouse_id' => $warehouseId]);
        }

        if (SchemaForeignKeys::hasOnColumn('collections', 'warehouse_id')) {
            return;
        }

        Schema::table('collections', function (Blueprint $table): void {
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->restrictOnDelete();
            $table->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->dropForeign(['warehouse_id']);
            $table->dropIndex(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
