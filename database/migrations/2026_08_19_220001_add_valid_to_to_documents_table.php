<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'valid_to')) {
                $table->date('valid_to')->nullable()->after('document_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'valid_to')) {
                $table->dropColumn('valid_to');
            }
        });
    }
};
