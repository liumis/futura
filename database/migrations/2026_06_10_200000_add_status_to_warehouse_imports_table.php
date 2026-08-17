<?php

use App\Enums\WarehouseImportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_imports', function (Blueprint $table) {
            $table->string('status')->default(WarehouseImportStatus::Pending->value)->after('amount');
        });

        DB::table('warehouse_imports')
            ->whereNotNull('cargo_id')
            ->update(['status' => WarehouseImportStatus::Received->value]);
    }

    public function down(): void
    {
        Schema::table('warehouse_imports', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
