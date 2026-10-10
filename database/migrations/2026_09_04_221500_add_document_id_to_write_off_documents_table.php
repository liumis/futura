<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('write_off_documents', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->nullable()
                ->after('user_id')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('write_off_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
