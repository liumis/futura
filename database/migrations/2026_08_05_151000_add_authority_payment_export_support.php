<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_tax_settings', function (Blueprint $table): void {
            $table->decimal('employer_sodra_permanent_rate', 8, 4)->default(0.0177)->after('gpm_rate');
            $table->decimal('employer_sodra_fixed_term_rate', 8, 4)->default(0.0249)->after('employer_sodra_permanent_rate');
        });

        DB::table('payroll_tax_settings')->update([
            'employer_sodra_permanent_rate' => 0.0177,
            'employer_sodra_fixed_term_rate' => 0.0249,
        ]);

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->decimal('sodra_employer_amount', 12, 2)->nullable()->after('sodra_employee_amount');
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->string('vmi_iban', 34)->nullable()->after('company_bic');
            $table->string('vmi_bic', 11)->nullable()->after('vmi_iban');
            $table->string('sodra_iban', 34)->nullable()->after('vmi_bic');
            $table->string('sodra_bic', 11)->nullable()->after('sodra_iban');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['vmi_iban', 'vmi_bic', 'sodra_iban', 'sodra_bic']);
        });

        Schema::table('employee_monthly_payments', function (Blueprint $table): void {
            $table->dropColumn('sodra_employer_amount');
        });

        Schema::table('payroll_tax_settings', function (Blueprint $table): void {
            $table->dropColumn(['employer_sodra_permanent_rate', 'employer_sodra_fixed_term_rate']);
        });
    }
};
