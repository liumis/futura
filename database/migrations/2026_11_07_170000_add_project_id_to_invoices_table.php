<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'project_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignId('project_id')
                ->nullable()
                ->after('contact_id')
                ->constrained('projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoices', 'project_id')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
