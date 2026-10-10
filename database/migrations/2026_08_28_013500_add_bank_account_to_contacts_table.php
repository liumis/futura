<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            if (! Schema::hasColumn('contacts', 'bank_account')) {
                $table->string('bank_account', 34)->nullable()->after('private_provider');
            }

            if (! Schema::hasColumn('contacts', 'bank_bic')) {
                $table->string('bank_bic', 11)->nullable()->after('bank_account');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contacts')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('contacts', 'bank_bic') ? 'bank_bic' : null,
                Schema::hasColumn('contacts', 'bank_account') ? 'bank_account' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
