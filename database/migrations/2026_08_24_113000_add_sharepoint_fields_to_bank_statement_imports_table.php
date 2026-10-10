<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return;
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            if (! Schema::hasColumn('bank_statement_imports', 'sharepoint_web_url')) {
                $table->string('sharepoint_web_url', 2000)->nullable();
            }
            if (! Schema::hasColumn('bank_statement_imports', 'sharepoint_item_id')) {
                $table->string('sharepoint_item_id')->nullable();
            }
            if (! Schema::hasColumn('bank_statement_imports', 'sharepoint_path')) {
                $table->string('sharepoint_path', 1000)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return;
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            foreach (['sharepoint_web_url', 'sharepoint_item_id', 'sharepoint_path'] as $column) {
                if (Schema::hasColumn('bank_statement_imports', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
