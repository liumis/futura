<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Destination extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'shipping_setting_id',
        'country_id',
        'city',
        'postal_code',
        'default_package_cost',
        'cost_per_kg',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_package_cost' => 'decimal:2',
            'cost_per_kg' => 'decimal:2',
        ];
    }

    public function shippingSetting(): BelongsTo
    {
        return $this->belongsTo(ShippingSetting::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
