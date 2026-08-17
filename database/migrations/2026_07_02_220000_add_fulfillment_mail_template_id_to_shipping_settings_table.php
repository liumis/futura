<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_settings', function (Blueprint $table) {
            $table->foreignId('fulfillment_mail_template_id')
                ->nullable()
                ->after('fulfillment_warehouse_email')
                ->constrained('mail_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fulfillment_mail_template_id');
        });
    }
};
