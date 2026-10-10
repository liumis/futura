<?php

namespace App\Models;

use App\Enums\CompanyBankAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CompanyBankAccount extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'iban',
        'statement_aliases',
        'bic',
        'bank_name',
        'name',
        'type',
        'credit_limit',
        'is_primary',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => CompanyBankAccountType::class,
            'statement_aliases' => 'array',
            'credit_limit' => 'decimal:2',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public static function normalizeIban(?string $iban): ?string
    {
        if (! filled($iban)) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $iban) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    public function displayLabel(): string
    {
        $parts = array_filter([
            $this->bank_name ?: self::bankNameFromIban((string) $this->iban),
            $this->type?->label(),
            $this->iban,
            $this->name,
        ], fn (mixed $value): bool => filled($value));

        return implode(' · ', $parts);
    }

    /**
     * Match a stored counterpart label to a company bank account (Excel often has no IBAN).
     */
    public static function resolveFromPartyName(?string $name): ?self
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $name) ?? $name);

        foreach (static::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $account) {
            $candidates = array_filter([
                $account->name,
                $account->displayLabel(),
                implode(' · ', array_filter([
                    $account->bank_name ?: self::bankNameFromIban((string) $account->iban),
                    $account->type?->label(),
                ], fn (mixed $value): bool => filled($value))),
            ], fn (mixed $value): bool => filled($value));

            foreach ($candidates as $candidate) {
                $candidateNorm = mb_strtolower(preg_replace('/\s+/u', ' ', (string) $candidate) ?? (string) $candidate);
                if ($candidateNorm === $normalized) {
                    return $account;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allIdentifiers(): array
    {
        $ids = [(string) $this->iban];
        foreach ($this->statement_aliases ?? [] as $alias) {
            if (filled($alias)) {
                $ids[] = strtoupper(trim((string) $alias));
            }
        }

        return array_values(array_unique($ids));
    }

    public static function resolveFromStatementAccount(?string $accountKey): ?self
    {
        $accountKey = self::normalizeIban($accountKey) ?? strtoupper(trim((string) $accountKey));
        if ($accountKey === '') {
            return null;
        }

        $exact = static::query()->where('iban', $accountKey)->first();
        if ($exact !== null) {
            return $exact;
        }

        foreach (static::query()->whereNotNull('statement_aliases')->get() as $account) {
            foreach ($account->allIdentifiers() as $id) {
                if ($id === $accountKey) {
                    return $account;
                }
            }
        }

        // Excel cash-flow keys like ACC-27101-5504 → match by trailing account number.
        if (preg_match('/(?:ACC-\d+-)?(\d{4})$/i', $accountKey, $matches) === 1) {
            $tail = $matches[1];

            return static::query()
                ->where('iban', 'like', '%'.$tail)
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    public static function canonicalizeStatementAccount(?string $accountKey): ?string
    {
        $resolved = self::resolveFromStatementAccount($accountKey);
        if ($resolved !== null) {
            return $resolved->iban;
        }

        return self::normalizeIban($accountKey) ?? (filled($accountKey) ? strtoupper(trim((string) $accountKey)) : null);
    }

    public function bankGroup(): string
    {
        if (filled($this->bank_name)) {
            return (string) $this->bank_name;
        }

        return self::bankNameFromIban((string) $this->iban) ?? 'Other banks';
    }

    public static function bankNameFromIban(string $iban): ?string
    {
        $iban = self::normalizeIban($iban) ?? '';

        // Lithuanian bank code is digits 5–9 of the IBAN (after LT + check digits).
        if (preg_match('/^LT\d{2}(\d{5})/i', $iban, $matches) !== 1) {
            return null;
        }

        return match ($matches[1]) {
            '70440' => 'SEB',
            '73000' => 'Swedbank',
            '71800' => 'Šiaulių bankas',
            '72300' => 'Medicinos bankas',
            '74102' => 'Revolut',
            default => null,
        };
    }

    /**
     * Active company IBANs + statement aliases + My Company primary IBAN.
     *
     * @return array<string, true>
     */
    public static function ownAccountIdentifierMap(bool $fresh = false): array
    {
        static $cache = null;

        if ($fresh) {
            $cache = null;
        }

        if ($cache !== null) {
            return $cache;
        }

        $map = [];

        foreach (static::query()->where('is_active', true)->get() as $account) {
            foreach ($account->allIdentifiers() as $id) {
                $map[$id] = true;
            }
        }

        // Use first()/value — not instance() — so callers (e.g. migrate seed) do not
        // insert an empty company_settings row that shadows later test creates.
        $companyIban = self::normalizeIban(
            CompanySetting::query()->value('company_iban')
        );
        if ($companyIban !== null) {
            $map[$companyIban] = true;
        }

        return $cache = $map;
    }

    public static function isOwnAccountIdentifier(?string $accountKey): bool
    {
        $normalized = self::normalizeIban($accountKey) ?? strtoupper(trim((string) $accountKey));
        if ($normalized === '') {
            return false;
        }

        return isset(self::ownAccountIdentifierMap()[$normalized]);
    }

    public static function guessTypeFromLabel(?string $label): CompanyBankAccountType
    {
        $hay = Str::lower((string) $label);

        if (str_contains($hay, 'credit') || str_contains($hay, 'kortel')) {
            return CompanyBankAccountType::CreditCard;
        }

        if (str_contains($hay, 'income') || str_contains($hay, 'pajam')) {
            return CompanyBankAccountType::Income;
        }

        if (str_contains($hay, 'expense') || str_contains($hay, 'išlaid') || str_contains($hay, 'islaid')) {
            return CompanyBankAccountType::Expense;
        }

        return CompanyBankAccountType::Other;
    }
}
