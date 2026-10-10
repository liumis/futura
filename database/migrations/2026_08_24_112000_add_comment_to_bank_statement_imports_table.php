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
            if (! Schema::hasColumn('bank_statement_imports', 'comment')) {
                $table->text('comment')->nullable()->after('message_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            return;
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            if (Schema::hasColumn('bank_statement_imports', 'comment')) {
                $table->dropColumn('comment');
            }
        });
    }
};
