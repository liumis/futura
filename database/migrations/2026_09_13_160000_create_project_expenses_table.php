<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_expenses')) {
            return;
        }

        Schema::create('project_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->date('worked_on')->nullable();
            $table->decimal('hours', 8, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('attachments')->nullable();
            $table->string('sharepoint_web_url')->nullable();
            $table->string('sharepoint_item_id')->nullable();
            $table->string('sharepoint_path')->nullable();
            $table->json('sharepoint_files')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'worked_on']);
            $table->index(['user_id', 'worked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
    }
};
