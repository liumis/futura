<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_imports')
            || Schema::hasColumn('bank_statement_imports', 'content_hash')) {
            return;
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->string('content_hash', 64)->nullable()->after('stored_path');
            $table->unique('content_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('bank_statement_imports', 'content_hash')) {
            return;
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->dropUnique(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
