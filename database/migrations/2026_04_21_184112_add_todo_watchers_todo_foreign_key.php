<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('todo_watchers') || ! Schema::hasTable('todos')) {
            return;
        }

        $hasForeign = collect(Schema::getForeignKeys('todo_watchers'))->contains(
            function (array $foreign): bool {
                return in_array('todo_id', $foreign['columns'] ?? [], true)
                    && ($foreign['foreign_table'] ?? null) === 'todos';
            }
        );

        if ($hasForeign) {
            return;
        }

        Schema::table('todo_watchers', function (Blueprint $table): void {
            $table->foreign('todo_id')->references('id')->on('todos')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('todo_watchers')) {
            return;
        }

        Schema::table('todo_watchers', function (Blueprint $table): void {
            $table->dropForeign(['todo_id']);
        });
    }
};
