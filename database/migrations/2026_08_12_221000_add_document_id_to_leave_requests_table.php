<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leave_requests', 'document_id')) {
            return;
        }

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('document_id')
                ->nullable()
                ->after('confirmed_at')
                ->constrained('documents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
