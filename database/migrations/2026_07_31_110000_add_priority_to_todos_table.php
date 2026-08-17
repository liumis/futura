<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('todos', 'priority')) {
            return;
        }

        Schema::table('todos', function (Blueprint $table): void {
            $table->string('priority', 20)->default('regular')->after('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropIndex(['priority']);
            $table->dropColumn('priority');
        });
    }
};
