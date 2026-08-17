<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->string('tracking')->nullable()->after('product_id');
            $table->string('status')->default('ordered')->after('amount');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['tracking', 'status']);
        });
    }
};
