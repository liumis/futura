<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('documents', 'confirmation_audience')) {
                $table->string('confirmation_audience', 40)
                    ->default('all_active_employees')
                    ->after('valid_to');
            }
        });

        Schema::dropIfExists('document_confirmation_users');

        Schema::create('document_confirmation_users', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->primary(['document_id', 'user_id']);
            $table->index('user_id');

            if (Schema::hasTable('documents')) {
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            }
        });

        Schema::dropIfExists('document_confirmations');

        Schema::create('document_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle_index')->default(1);
            $table->date('due_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'user_id', 'cycle_index']);
            $table->index(['due_at', 'confirmed_at', 'notified_at']);
            $table->index(['user_id', 'confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_confirmations');
        Schema::dropIfExists('document_confirmation_users');

        Schema::table('documents', function (Blueprint $table): void {
            if (Schema::hasColumn('documents', 'confirmation_audience')) {
                $table->dropColumn('confirmation_audience');
            }
        });
    }
};
