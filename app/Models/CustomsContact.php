<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomsContact extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_name',
        'company_code',
        'vat_code',
        'address',
        'phone',
        'email',
        'mail_template_id',
        'bank_account',
        'bank_bic',
    ];

    public function mailTemplate(): BelongsTo
    {
        return $this->belongsTo(MailTemplate::class);
    }

    public function importTaxPayments(): HasMany
    {
        return $this->hasMany(ImportTaxPayment::class);
    }

    public function cargos(): HasMany
    {
        return $this->hasMany(Cargo::class);
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()
            ->orderBy('company_name')
            ->pluck('company_name', 'id')
            ->all();
    }

    public static function defaultId(): ?int
    {
        $ids = static::query()->orderBy('company_name')->pluck('id');

        if ($ids->count() !== 1) {
            return null;
        }

        return (int) $ids->first();
    }

    public static function countContacts(): int
    {
        return static::query()->count();
    }
}
