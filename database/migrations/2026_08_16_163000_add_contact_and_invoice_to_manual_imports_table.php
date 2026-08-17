<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('manual_imports', 'contact_id')) {
            return;
        }

        Schema::table('manual_imports', function (Blueprint $table): void {
            $table->foreignId('contact_id')
                ->nullable()
                ->after('user_id')
                ->constrained('contacts')
                ->nullOnDelete();

            $table->foreignId('invoice_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manual_imports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropConstrainedForeignId('contact_id');
        });
    }
};
