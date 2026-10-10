<?php

use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use App\Services\CompanyBankAccountSyncer;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(CompanyBankAccountSyncer::class)->syncFromKnownSources();

        // Prefer existing company_iban as primary when present.
        $company = CompanySetting::query()->first();
        if ($company && filled($company->company_iban)) {
            $iban = CompanyBankAccount::normalizeIban((string) $company->company_iban);
            if ($iban !== null) {
                CompanyBankAccount::query()->where('is_primary', true)->update(['is_primary' => false]);
                CompanyBankAccount::query()->where('iban', $iban)->update([
                    'is_primary' => true,
                    'bic' => $company->company_bic ?: null,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep accounts; creation migration drops the table.
    }
};
