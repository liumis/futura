<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customs_contacts')) {
            Schema::table('customs_contacts', function (Blueprint $table): void {
                if (! Schema::hasColumn('customs_contacts', 'bank_account')) {
                    $table->string('bank_account', 34)->nullable()->after('email');
                }

                if (! Schema::hasColumn('customs_contacts', 'bank_bic')) {
                    $table->string('bank_bic', 11)->nullable()->after('bank_account');
                }
            });
        }

        if (Schema::hasTable('import_tax_payments')) {
            Schema::table('import_tax_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('import_tax_payments', 'service_cost')) {
                    $table->decimal('service_cost', 12, 2)->default(0)->after('tax_amount');
                }

                if (! Schema::hasColumn('import_tax_payments', 'status')) {
                    $table->string('status', 16)->default('unpaid')->after('service_cost');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('import_tax_payments')) {
            Schema::table('import_tax_payments', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('import_tax_payments', 'status') ? 'status' : null,
                    Schema::hasColumn('import_tax_payments', 'service_cost') ? 'service_cost' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('customs_contacts')) {
            Schema::table('customs_contacts', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('customs_contacts', 'bank_bic') ? 'bank_bic' : null,
                    Schema::hasColumn('customs_contacts', 'bank_account') ? 'bank_account' : null,
                ]));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
