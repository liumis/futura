<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shareholder extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'shareholder_percentage',
        'email',
        'phone',
        'bank_account',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shareholder_percentage' => 'decimal:2',
        ];
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }
}
