<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'company_country',
        'company_id',
        'company_vat',
        'company_address',
        'company_email',
        'company_phone',
        'contact_name',
        'contact_email',
        'contact_phone',
        'private_provider',
        'default_finance_type',
        'bank_account',
        'bank_bic',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_provider' => 'boolean',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function manualImports(): HasMany
    {
        return $this->hasMany(ManualImport::class);
    }

    /**
     * Lithuanian counterpart (country or VAT).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLithuaniaMarket($query)
    {
        return $query->where(function ($geo): void {
            $geo
                ->whereRaw('LOWER(TRIM(COALESCE(company_country, ""))) in (?, ?, ?)', ['lithuania', 'lietuva', 'lt'])
                ->orWhereRaw("UPPER(REPLACE(COALESCE(company_vat, ''), ' ', '')) like 'LT%'");
        });
    }

    /**
     * Non-Lithuanian / export counterpart.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeExportMarket($query)
    {
        return $query
            ->whereRaw('LOWER(TRIM(COALESCE(company_country, ""))) not in (?, ?, ?)', ['lithuania', 'lietuva', 'lt'])
            ->whereRaw("UPPER(REPLACE(COALESCE(company_vat, ''), ' ', '')) not like 'LT%'");
    }

    /**
     * Detach invoices before contact delete (contact_id becomes null).
     *
     * @return int Number of invoices unlinked
     */
    public function unlinkInvoices(): int
    {
        return $this->invoices()->update(['contact_id' => null]);
    }

    protected static function booted(): void
    {
        static::deleting(function (Contact $contact): void {
            $contact->unlinkInvoices();
        });
    }

    public function defaultFinanceTypeValue(): ?string
    {
        $value = trim((string) ($this->default_finance_type ?? ''));

        return $value !== '' && Invoice::isValidFinanceCategoryValue($value) ? $value : null;
    }

    /**
     * Store the invoice category as this provider's default for future invoices.
     * Explicit category choices (list / create) always update the default.
     */
    public function rememberDefaultCategoryFromInvoice(Invoice $invoice): bool
    {
        $value = $invoice->storedFinanceTypeValue();
        if ($value === null || ! Invoice::isValidFinanceCategoryValue($value)) {
            return false;
        }

        if ($this->defaultFinanceTypeValue() === $value) {
            return false;
        }

        $this->forceFill(['default_finance_type' => $value])->save();

        return true;
    }

    /**
     * Apply known provider defaults (e.g. DSV Road UAB → Transportas).
     */
    public static function prefillKnownDefaultCategories(): int
    {
        $updated = 0;

        foreach (self::knownDefaultFinanceMaps() as $map) {
            $expenseId = ExpenseType::query()
                ->whereIn('name', $map['expense_names'])
                ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$map['expense_names'][0]])
                ->value('id');

            if ($expenseId === null) {
                continue;
            }

            $value = 'expense:'.(int) $expenseId;
            $query = static::query()->where(function ($builder) use ($map): void {
                if ($map['company_ids'] !== []) {
                    $builder->whereIn('company_id', $map['company_ids']);
                }
                foreach ($map['company_names'] as $name) {
                    $builder->orWhereRaw('LOWER(company_name) = ?', [mb_strtolower($name)]);
                }
            });

            $updated += $query->update(['default_finance_type' => $value]);
        }

        return $updated;
    }

    /**
     * @return list<array{company_ids: list<string>, company_names: list<string>, expense_names: list<string>}>
     */
    public static function knownDefaultFinanceMaps(): array
    {
        return [
            [
                'company_ids' => ['111552645'],
                'company_names' => ['DSV Road, UAB', 'DSV Road UAB', 'DSV Road'],
                'expense_names' => ['Transportas', 'Transport'],
            ],
        ];
    }

    /**
     * Find or create a buyer contact from a customer user (sales invoices).
     */
    public static function syncFromCustomer(User $user): self
    {
        $companyName = trim((string) ($user->company_name ?: $user->name));
        if ($companyName === '') {
            $companyName = 'Customer #'.$user->getKey();
        }

        $companyId = trim((string) ($user->company_code ?? ''));
        if ($companyId === '') {
            $companyId = 'CUST-'.$user->getKey();
        }

        $match = static::query()
            ->where('company_id', $companyId)
            ->orWhere(function ($query) use ($companyName): void {
                $query->whereRaw('LOWER(company_name) = ?', [mb_strtolower($companyName)]);
            })
            ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
            ->first();

        $payload = [
            'company_name' => $companyName,
            'company_country' => $user->company_country,
            'company_id' => $companyId,
            'company_vat' => $user->company_vat,
            'company_address' => $user->company_address ?: $user->company_shipping_address ?: '—',
            'company_email' => $user->email,
            'company_phone' => $user->phone,
            'contact_name' => trim(implode(' ', array_filter([(string) $user->name, (string) $user->surname]))) ?: null,
            'contact_email' => $user->email,
            'contact_phone' => $user->phone,
        ];

        if ($match !== null) {
            $match->fill($payload)->save();

            return $match->fresh() ?? $match;
        }

        return static::query()->create($payload);
    }

    /**
     * Find or create a buyer contact from imported customer code/name.
     */
    public static function syncFromCustomerDetails(string $companyName, ?string $companyCode = null): self
    {
        $companyName = trim($companyName);
        if ($companyName === '') {
            $companyName = 'Unknown customer';
        }

        $companyId = trim((string) $companyCode);
        if ($companyId === '') {
            $companyId = 'CUST-'.strtoupper(substr(md5(mb_strtolower($companyName)), 0, 10));
        }

        return static::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'company_name' => $companyName,
                'company_address' => '—',
            ],
        );
    }
}
