<?php

use App\Support\SchemaForeignKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_contracts', 'document_id')) {
            Schema::table('employee_contracts', function (Blueprint $table): void {
                $table->foreignId('document_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('documents')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('employee_contract_signings')) {
            Schema::create('employee_contract_signings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('employee_contract_id')->constrained()->cascadeOnDelete();
                $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
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

        if (! Schema::hasTable('employee_contract_signing_signers')) {
            Schema::create('employee_contract_signing_signers', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('employee_contract_signing_id');
                $table->string('signer_key');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('name');
                $table->string('surname')->nullable();
                $table->string('email')->nullable();
                $table->string('dokobit_access_token')->nullable();
                $table->string('signing_url', 1000)->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->timestamps();

                $table->unique(['employee_contract_signing_id', 'signer_key'], 'contract_signing_signer_key_unique');
            });
        }

        SchemaForeignKeys::ensure(
            'employee_contract_signing_signers',
            'employee_contract_signing_id',
            'employee_contract_signings',
            'ecs_signers_signing_fk',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_contract_signing_signers');
        Schema::dropIfExists('employee_contract_signings');

        Schema::table('employee_contracts', function (Blueprint $table): void {
            if (Schema::hasColumn('employee_contracts', 'document_id')) {
                $table->dropConstrainedForeignId('document_id');
            }
        });
    }
};
