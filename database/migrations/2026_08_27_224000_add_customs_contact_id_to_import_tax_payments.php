<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_tax_payments')) {
            return;
        }

        if (! Schema::hasColumn('import_tax_payments', 'customs_contact_id')) {
            Schema::table('import_tax_payments', function (Blueprint $table): void {
                $table->foreignId('customs_contact_id')
                    ->nullable()
                    ->after('cargo_id')
                    ->constrained('customs_contacts')
                    ->nullOnDelete();
            });
        }

        if (DB::table('customs_contacts')->count() === 1) {
            $customsContactId = DB::table('customs_contacts')->orderBy('id')->value('id');

            DB::table('import_tax_payments')
                ->whereNull('customs_contact_id')
                ->update(['customs_contact_id' => $customsContactId]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('import_tax_payments') || ! Schema::hasColumn('import_tax_payments', 'customs_contact_id')) {
            return;
        }

        Schema::table('import_tax_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customs_contact_id');
        });
    }
};
