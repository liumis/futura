<?php

namespace App\Services;

use App\Enums\CompanyBankAccountType;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use App\Models\CompanySetting;
use Illuminate\Support\Collection;

class CompanyBankAccountSyncer
{
    /**
     * Ensure known company IBANs exist as managed bank accounts.
     *
     * @return int Number of newly created accounts
     */
    public function syncFromKnownSources(): int
    {
        $created = 0;
        // Use first() — not instance() — so migrate/seed does not insert an empty company_settings row.
        $company = CompanySetting::query()->first();

        if ($company && filled($company->company_iban)) {
            $created += $this->ensureAccount(
                (string) $company->company_iban,
                $company->company_bic,
                null,
                CompanyBankAccountType::Other,
                isPrimary: true,
            ) ? 1 : 0;
        }

        // Seed the three SEB accounts from the live bank overview when missing.
        // 5504 = payments from us (expense), 7970 = customer inflows (income), 9371 = card.
        $defaults = [
            [
                'iban' => 'LT117044090110985504',
                'type' => CompanyBankAccountType::Expense,
                'name' => 'UAB FUTURA TEXTILES EUROPE Expense',
                'aliases' => ['ACC-27101-5504'],
            ],
            [
                'iban' => 'LT357044090113587970',
                'type' => CompanyBankAccountType::Income,
                'name' => 'UAB FUTURA TEXTILES EUROPE Income',
                'aliases' => ['ACC-27102-7970'],
            ],
            [
                'iban' => 'LT677044090113979371',
                'type' => CompanyBankAccountType::CreditCard,
                'name' => 'UAB FUTURA TEXTILES EUROPE Credit card',
                'credit_limit' => 0,
                'aliases' => ['ACC-27103-9371'],
            ],
        ];

        foreach ($defaults as $default) {
            $created += $this->ensureAccount(
                $default['iban'],
                'CBVILT2X',
                $default['name'],
                $default['type'],
                creditLimit: $default['credit_limit'] ?? null,
                aliases: $default['aliases'],
            ) ? 1 : 0;
        }

        $fromStatements = BankStatementTransaction::query()
            ->whereNotNull('account_iban')
            ->where('account_iban', '!=', '')
            ->where('account_iban', 'like', 'LT%')
            ->selectRaw('account_iban, MAX(bank_name) as bank_name')
            ->groupBy('account_iban')
            ->get();

        foreach ($fromStatements as $row) {
            $created += $this->ensureAccount(
                (string) $row->account_iban,
                null,
                null,
                CompanyBankAccountType::Other,
                bankName: $row->bank_name,
            ) ? 1 : 0;
        }

        return $created;
    }

    public function ensureAccount(
        string $iban,
        ?string $bic = null,
        ?string $name = null,
        CompanyBankAccountType $type = CompanyBankAccountType::Other,
        bool $isPrimary = false,
        ?float $creditLimit = null,
        ?string $bankName = null,
        ?array $aliases = null,
    ): bool {
        $iban = CompanyBankAccount::normalizeIban($iban);
        if ($iban === null) {
            return false;
        }

        $existing = CompanyBankAccount::query()->where('iban', $iban)->first();
        if ($existing !== null) {
            $dirty = false;
            if ($isPrimary && ! $existing->is_primary) {
                CompanyBankAccount::query()->where('is_primary', true)->update(['is_primary' => false]);
                $existing->is_primary = true;
                $dirty = true;
            }
            if ($bic && blank($existing->bic)) {
                $existing->bic = strtoupper($bic);
                $dirty = true;
            }
            if ($bankName && blank($existing->bank_name)) {
                $existing->bank_name = $bankName;
                $dirty = true;
            }
            if ($name && blank($existing->name)) {
                $existing->name = $name;
                $dirty = true;
            }
            if ($type !== CompanyBankAccountType::Other && $existing->type === CompanyBankAccountType::Other) {
                $existing->type = $type;
                $dirty = true;
            }
            if ($type === CompanyBankAccountType::CreditCard && $existing->credit_limit === null) {
                $existing->credit_limit = $creditLimit ?? 0;
                $dirty = true;
            }
            if ($aliases) {
                $merged = array_values(array_unique(array_filter([
                    ...($existing->statement_aliases ?? []),
                    ...$aliases,
                ])));
                if ($merged !== ($existing->statement_aliases ?? [])) {
                    $existing->statement_aliases = $merged;
                    $dirty = true;
                }
            }
            if ($dirty) {
                $existing->save();
            }

            return false;
        }

        if ($isPrimary) {
            CompanyBankAccount::query()->where('is_primary', true)->update(['is_primary' => false]);
        }

        $maxSort = (int) CompanyBankAccount::query()->max('sort_order');

        CompanyBankAccount::query()->create([
            'iban' => $iban,
            'statement_aliases' => $aliases ? array_values(array_unique($aliases)) : null,
            'bic' => filled($bic) ? strtoupper((string) $bic) : null,
            'bank_name' => $bankName ?: CompanyBankAccount::bankNameFromIban($iban),
            'name' => $name,
            'type' => $type,
            'credit_limit' => $type === CompanyBankAccountType::CreditCard ? ($creditLimit ?? 0) : null,
            'is_primary' => $isPrimary,
            'is_active' => true,
            'sort_order' => $maxSort + 1,
        ]);

        if ($isPrimary) {
            $this->syncPrimaryToCompanySettings($iban, $bic);
        }

        CompanyBankAccount::ownAccountIdentifierMap(fresh: true);

        return true;
    }

    public function syncPrimaryToCompanySettings(string $iban, ?string $bic = null): void
    {
        $company = CompanySetting::instance();
        $company->update([
            'company_iban' => CompanyBankAccount::normalizeIban($iban),
            'company_bic' => filled($bic) ? strtoupper((string) $bic) : $company->company_bic,
        ]);
    }

    /**
     * @return Collection<string, Collection<int, CompanyBankAccount>>
     */
    public function bankAccountsGrouped(): Collection
    {
        return CompanyBankAccount::query()
            ->where('is_active', true)
            ->where('type', '!=', CompanyBankAccountType::CreditCard)
            ->orderBy('bank_name')
            ->orderBy('sort_order')
            ->orderBy('iban')
            ->get()
            ->groupBy(fn (CompanyBankAccount $account): string => $account->bankGroup());
    }

    /**
     * @return Collection<int, CompanyBankAccount>
     */
    public function creditCardAccounts(): Collection
    {
        return CompanyBankAccount::query()
            ->where('is_active', true)
            ->where('type', CompanyBankAccountType::CreditCard)
            ->orderBy('sort_order')
            ->orderBy('iban')
            ->get();
    }
}
