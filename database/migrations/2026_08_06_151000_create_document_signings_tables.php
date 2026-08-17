<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_signings')) {
            Schema::create('document_signings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('status', 32)->default('pending');
                $table->string('dokobit_token')->nullable();
                $table->string('dokobit_file_token')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('dokobit_token');
            });
        }

        if (! Schema::hasTable('document_signing_signers')) {
            Schema::create('document_signing_signers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_signing_id')
                    ->constrained('document_signings')
                    ->cascadeOnDelete();
                $table->string('signer_key');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('surname')->nullable();
                $table->string('email')->nullable();
                $table->string('dokobit_access_token')->nullable();
                $table->string('signing_url', 1000)->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->timestamps();

                $table->unique(['document_signing_id', 'signer_key'], 'document_signing_signer_key_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signing_signers');
        Schema::dropIfExists('document_signings');
    }
};
