<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('invoice_series_id')
                ->nullable()
                ->after('order_id')
                ->constrained('invoice_series')
                ->nullOnDelete();
            $table->unsignedInteger('series_number')->nullable()->after('invoice_series_id');
            $table->string('invoice_number')->nullable()->unique()->after('series_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_series_id');
            $table->dropColumn(['series_number', 'invoice_number']);
        });
    }
};
