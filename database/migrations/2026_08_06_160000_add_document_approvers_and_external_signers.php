<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_approvers')) {
            Schema::create('document_approvers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->boolean('is_auto_approved')->default(false);
                $table->timestamps();

                $table->unique(['document_id', 'user_id'], 'document_approver_user_unique');
            });
        }

        if (! Schema::hasColumn('document_signing_signers', 'is_external')) {
            Schema::table('document_signing_signers', function (Blueprint $table): void {
                $table->boolean('is_external')->default(false)->after('email');
                $table->timestamp('invited_at')->nullable()->after('signing_url');
            });
        }
    }

    public function down(): void
    {
        Schema::table('document_signing_signers', function (Blueprint $table): void {
            $table->dropColumn(['is_external', 'invited_at']);
        });

        Schema::dropIfExists('document_approvers');
    }
};
