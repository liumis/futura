<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->foreignId('responsable_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        DB::table('todos')
            ->whereNull('responsable_id')
            ->update([
                'responsable_id' => DB::raw('user_id'),
            ]);
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('responsable_id');
        });
    }
};
