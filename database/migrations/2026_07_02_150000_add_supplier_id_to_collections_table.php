<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });

        $defaultSupplierId = DB::table('suppliers')->orderBy('id')->value('id');

        if ($defaultSupplierId === null) {
            return;
        }

        DB::table('collections')->update(['supplier_id' => $defaultSupplierId]);
    }

    public function down(): void
    {
        Schema::table('collections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
    }
};
