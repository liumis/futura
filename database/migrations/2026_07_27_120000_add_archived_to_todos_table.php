<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('todos', 'archived')) {
            return;
        }

        Schema::table('todos', function (Blueprint $table): void {
            $table->boolean('archived')->default(false)->after('status');
            $table->index('archived');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropIndex(['archived']);
            $table->dropColumn('archived');
        });
    }
};
