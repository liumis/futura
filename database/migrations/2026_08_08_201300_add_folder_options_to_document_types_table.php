<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->boolean('new_folder_per_document')->default(false)->after('name');
            $table->boolean('group_by_year')->default(false)->after('new_folder_per_document');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table): void {
            $table->dropColumn(['new_folder_per_document', 'group_by_year']);
        });
    }
};
