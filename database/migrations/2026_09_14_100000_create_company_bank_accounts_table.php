<?php

use App\Enums\CompanyBankAccountType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('iban', 34)->unique();
            $table->json('statement_aliases')->nullable();
            $table->string('bic', 11)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('name')->nullable();
            $table->string('type', 32)->default(CompanyBankAccountType::Other->value);
            $table->decimal('credit_limit', 12, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
    }
};
