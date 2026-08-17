<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->renameColumn('author_id', 'user_id');
            $table->renameColumn('name', 'title');
            $table->renameColumn('deadline_at', 'deadline');
            $table->renameColumn('start_at', 'start_date');
        });

        DB::table('todos')
            ->where('status', 'in_progress')
            ->update(['status' => 'inprogress']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('todos')
            ->where('status', 'inprogress')
            ->update(['status' => 'in_progress']);

        Schema::table('todos', function (Blueprint $table): void {
            $table->renameColumn('user_id', 'author_id');
            $table->renameColumn('title', 'name');
            $table->renameColumn('deadline', 'deadline_at');
            $table->renameColumn('start_date', 'start_at');
        });
    }
};
