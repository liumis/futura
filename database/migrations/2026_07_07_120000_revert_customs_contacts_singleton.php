<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('customs_contacts')
            ->whereNull('company_name')
            ->whereNull('company_code')
            ->whereNull('vat_code')
            ->whereNull('address')
            ->whereNull('phone')
            ->whereNull('email')
            ->delete();

        DB::table('customs_contacts')
            ->whereNull('company_name')
            ->update(['company_name' => '']);

        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->string('company_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customs_contacts', function (Blueprint $table) {
            $table->string('company_name')->nullable()->change();
        });
    }
};
