<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_statement_imports')) {
            Schema::create('bank_statement_imports', function (Blueprint $table): void {
                $table->id();
                $table->string('original_filename');
                $table->string('stored_path')->nullable();
                $table->string('bank_name')->nullable();
                $table->string('account_iban', 34)->nullable();
                $table->string('account_currency', 3)->nullable();
                $table->string('statement_id')->nullable();
                $table->string('message_id')->nullable();
                $table->date('period_from')->nullable();
                $table->date('period_to')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('transaction_count')->default(0);
                $table->unsignedInteger('imported_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('bank_statement_transactions')) {
            Schema::create('bank_statement_transactions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('bank_statement_import_id')
                    ->constrained('bank_statement_imports')
                    ->cascadeOnDelete();
                $table->string('fingerprint', 64)->unique();
                $table->string('month_key', 7)->index();
                $table->date('booking_date')->index();
                $table->date('value_date')->nullable();
                $table->string('direction', 16);
                $table->decimal('amount', 14, 2);
                $table->string('currency', 3)->default('EUR');
                $table->string('account_iban', 34)->nullable();
                $table->string('bank_name')->nullable();
                $table->string('counterpart_name')->nullable();
                $table->string('counterpart_iban', 34)->nullable();
                $table->string('counterpart_bic', 11)->nullable();
                $table->text('description')->nullable();
                $table->string('end_to_end_id')->nullable();
                $table->string('account_servicer_ref')->nullable();
                $table->string('transaction_id')->nullable();
                $table->timestamps();

                $table->index(['month_key', 'booking_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_transactions');
        Schema::dropIfExists('bank_statement_imports');
    }
};
