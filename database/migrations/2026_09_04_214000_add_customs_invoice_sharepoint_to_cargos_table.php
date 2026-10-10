<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->string('customs_invoice_file_name')->nullable()->after('additional_cost');
            $table->string('customs_invoice_sharepoint_web_url', 2048)->nullable()->after('customs_invoice_file_name');
            $table->string('customs_invoice_sharepoint_item_id')->nullable()->after('customs_invoice_sharepoint_web_url');
            $table->string('customs_invoice_sharepoint_path', 1024)->nullable()->after('customs_invoice_sharepoint_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('cargos', function (Blueprint $table) {
            $table->dropColumn([
                'customs_invoice_file_name',
                'customs_invoice_sharepoint_web_url',
                'customs_invoice_sharepoint_item_id',
                'customs_invoice_sharepoint_path',
            ]);
        });
    }
};
