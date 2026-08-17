<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->foreignId('import_tax_id')
                ->nullable()
                ->after('status')
                ->constrained('import_taxes')
                ->nullOnDelete();

            $table->decimal('shipping_cost', 12, 2)
                ->default(0)
                ->after('import_tax_id');
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropForeign(['import_tax_id']);
            $table->dropColumn(['import_tax_id', 'shipping_cost']);
        });
    }
};
