<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_manual_updates', function (Blueprint $table) {
            $table->foreignId('write_off_document_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('write_off_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_manual_updates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('write_off_document_id');
        });
    }
};
