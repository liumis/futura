<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'sharepoint_files')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->json('sharepoint_files')->nullable()->after('sharepoint_path');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('sharepoint_files');
        });
    }
};
