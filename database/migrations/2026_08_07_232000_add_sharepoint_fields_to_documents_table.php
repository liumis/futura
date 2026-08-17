<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('sharepoint_web_url', 1000)->nullable()->after('approved_file_path');
            $table->string('sharepoint_item_id')->nullable()->after('sharepoint_web_url');
            $table->string('sharepoint_path')->nullable()->after('sharepoint_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['sharepoint_web_url', 'sharepoint_item_id', 'sharepoint_path']);
        });
    }
};
