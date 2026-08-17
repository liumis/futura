<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable();
            $table->string('phone')->nullable();
            $table->string('company_name')->nullable();
            $table->text('company_address')->nullable();
            $table->text('company_shipping_address')->nullable();
            $table->string('company_code')->nullable();
            $table->string('company_vat')->nullable();
            $table->string('company_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'surname',
                'phone',
                'company_name',
                'company_address',
                'company_shipping_address',
                'company_code',
                'company_vat',
                'company_email',
            ]);
        });
    }
};
