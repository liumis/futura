<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_connections')) {
            Schema::create('calendar_connections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider', 32)->default('microsoft');
                $table->string('external_account_id')->nullable();
                $table->string('account_email')->nullable();
                $table->string('calendar_id')->nullable();
                $table->string('calendar_name')->nullable();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->string('subscription_id')->nullable();
                $table->timestamp('subscription_expires_at')->nullable();
                $table->string('subscription_client_state', 64)->nullable();
                $table->text('delta_link')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->string('status', 32)->default('active');
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'provider']);
                $table->index(['provider', 'subscription_id']);
                $table->index('subscription_expires_at');
            });
        }

        if (! Schema::hasTable('task_calendar_events')) {
            Schema::create('task_calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('todo_id')->constrained('todos')->cascadeOnDelete();
                $table->foreignId('calendar_connection_id')->constrained('calendar_connections')->cascadeOnDelete();
                $table->string('external_event_id')->nullable();
                $table->string('last_external_event_id')->nullable();
                $table->string('external_change_key')->nullable();
                $table->string('external_status', 32)->default('synced');
                $table->timestamp('last_external_modified_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamp('deleted_externally_at')->nullable();
                $table->string('sync_hash', 64)->nullable();
                $table->string('last_sync_origin', 32)->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['todo_id', 'calendar_connection_id']);
                $table->unique(['calendar_connection_id', 'external_event_id']);
                $table->index(['external_status', 'deleted_externally_at']);
            });
        }

        if (! Schema::hasColumn('todos', 'calendar_sync_enabled')) {
            Schema::table('todos', function (Blueprint $table): void {
                $table->boolean('calendar_sync_enabled')->default(false)->after('archived');
                $table->boolean('all_day')->default(false)->after('deadline');
            });
        }
    }

    public function down(): void
    {
        Schema::table('todos', function (Blueprint $table): void {
            $table->dropColumn(['calendar_sync_enabled', 'all_day']);
        });

        Schema::dropIfExists('task_calendar_events');
        Schema::dropIfExists('calendar_connections');
    }
};
