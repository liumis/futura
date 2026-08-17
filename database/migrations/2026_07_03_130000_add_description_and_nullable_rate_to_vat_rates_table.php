<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->decimal('rate', 5, 2)->nullable()->change();
            $table->text('description')->nullable()->after('classificator');
        });
    }

    public function down(): void
    {
        Schema::table('vat_rates', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->decimal('rate', 5, 2)->nullable(false)->change();
        });
    }
};
