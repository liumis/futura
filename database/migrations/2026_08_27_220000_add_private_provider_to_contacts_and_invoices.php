<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contacts') && ! Schema::hasColumn('contacts', 'private_provider')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->boolean('private_provider')->default(false)->after('contact_phone');
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'private_provider')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->boolean('private_provider')->default(false)->after('paid');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contacts') && Schema::hasColumn('contacts', 'private_provider')) {
            Schema::table('contacts', function (Blueprint $table): void {
                $table->dropColumn('private_provider');
            });
        }

        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'private_provider')) {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropColumn('private_provider');
            });
        }
    }
};
